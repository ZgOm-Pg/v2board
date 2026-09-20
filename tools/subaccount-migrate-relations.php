<?php
/**
 * 子账号关系迁移（源 XBoard → 目标 V2Board），仅导入关系，不改动任何用户账号字段。
 *
 * 用法（在目标 V2Board 项目根目录执行）:
 *   php tools/subaccount-migrate-relations.php --source=/path/relations.jsonl [--dry-run|--apply]
 *
 * 数据来源：源站只读导出脚本 `_mig/subaccount/export_source_relations.sh` 产生的
 * JSONL（每行一条 JSON，字段见下）。脚本**不依赖任何硬编码记录**，必须在执行前
 * 重新从源站导出，以保证与源站当前状态一致。
 *
 * 导入规则（对应需求书第十三节）:
 *   - 源 status=1 且父、子用户都存在，才进入候选；
 *   - 目标库中父、子用户都必须存在，且邮箱与源一致（防 ID 复用错绑）；
 *   - child_user_id 不得已绑定到其它主账号（可复用/复活同主账号的归档关系）；
 *   - 父子不得相同；主账号自身不得是子账号；子账号自身不得已有子账号（禁止多层）；
 *   - 其余情况（停用、缺父、缺子、目标缺用户、邮箱不一致、冲突、自绑定、多层）
 *     全部写入异常清单，不自动导入。
 *
 * 安全约束:
 *   - dry-run 为默认行为，--apply 才写入；
 *   - 单事务，任一失败整体回滚；
 *   - 只写 v2_user_sub_accounts 与 v2_sub_account_audit_logs，
 *     **绝不修改 v2_user 的任何字段**；
 *   - 显式列名写入，不使用 INSERT ... SELECT *。
 */

require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\SubAccountAuditLog;
use App\Models\SubAccountRelation;
use App\Models\User;
use Illuminate\Support\Facades\DB;

$opts = [];
foreach (array_slice($argv, 1) as $arg) {
    if (strpos($arg, '--') === 0) {
        $parts = explode('=', $arg, 2);
        $opts[substr($parts[0], 2)] = isset($parts[1]) ? $parts[1] : true;
    }
}

$apply = array_key_exists('apply', $opts);
$source = isset($opts['source']) && is_string($opts['source']) ? $opts['source'] : null;

if (!$source || !is_readable($source)) {
    fwrite(STDERR, "用法: php tools/subaccount-migrate-relations.php --source=<relations.jsonl> [--dry-run|--apply]\n");
    fwrite(STDERR, "请先用源站只读脚本导出：_mig/subaccount/export_source_relations.sh\n");
    exit(2);
}

$rows = [];
foreach (file($source, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
    $row = json_decode($line, true);
    if (is_array($row)) $rows[] = $row;
}

echo "=== 子账号关系迁移 ===\n";
echo "源数据文件 : {$source}\n";
echo "源记录数   : " . count($rows) . "\n";
echo "目标数据库 : " . DB::connection()->getDatabaseName() . "\n";
echo "模式       : " . ($apply ? 'APPLY（写入）' : 'DRY-RUN（只校验）') . "\n\n";

// ---------- 0. 表就绪 ----------
foreach (['v2_user_sub_accounts', 'v2_sub_account_audit_logs'] as $t) {
    $row = DB::selectOne(
        'SELECT COUNT(*) c FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
        [$t]
    );
    if ((int)$row->c !== 1) {
        fwrite(STDERR, "表 {$t} 不存在，请先执行: php artisan sub-account:install --apply\n");
        exit(1);
    }
}

$import = [];
$exceptions = [];

foreach ($rows as $r) {
    $rid = isset($r['relation_id']) ? $r['relation_id'] : '?';
    $parentId = (int)($r['parent_user_id'] ?? 0);
    $childId = (int)($r['child_user_id'] ?? 0);

    if ((int)($r['status'] ?? 0) !== 1) {
        $exceptions[] = ['relation_id' => $rid, 'reason' => '源关系已停用(status=0)', 'parent' => $parentId, 'child' => $childId];
        continue;
    }
    if (empty($r['parent_exists']) || empty($r['child_exists'])) {
        $exceptions[] = ['relation_id' => $rid, 'reason' => '源站缺父或子用户(孤儿关系)', 'parent' => $parentId, 'child' => $childId];
        continue;
    }
    if ($parentId === $childId) {
        $exceptions[] = ['relation_id' => $rid, 'reason' => '父子为同一用户', 'parent' => $parentId, 'child' => $childId];
        continue;
    }

    $parent = User::find($parentId);
    $child = User::find($childId);
    if (!$parent || !$child) {
        $exceptions[] = ['relation_id' => $rid, 'reason' => '目标库缺少父或子用户', 'parent' => $parentId, 'child' => $childId];
        continue;
    }
    if (isset($r['parent_email']) && $r['parent_email'] !== null
        && strtolower((string)$parent->email) !== strtolower((string)$r['parent_email'])) {
        $exceptions[] = ['relation_id' => $rid, 'reason' => '父用户邮箱与源不一致(疑似ID复用)', 'parent' => $parentId, 'child' => $childId];
        continue;
    }
    if (isset($r['child_email']) && $r['child_email'] !== null
        && strtolower((string)$child->email) !== strtolower((string)$r['child_email'])) {
        $exceptions[] = ['relation_id' => $rid, 'reason' => '子用户邮箱与源不一致(疑似ID复用)', 'parent' => $parentId, 'child' => $childId];
        continue;
    }

    $existing = SubAccountRelation::where('child_user_id', $childId)->first();
    if ($existing && (int)$existing->parent_user_id !== $parentId) {
        $exceptions[] = [
            'relation_id' => $rid,
            'reason' => '目标库中该子账号已属于其它主账号 #' . (int)$existing->parent_user_id,
            'parent' => $parentId, 'child' => $childId,
        ];
        continue;
    }
    if (SubAccountRelation::where('child_user_id', $parentId)->where('status', 1)->exists()) {
        $exceptions[] = ['relation_id' => $rid, 'reason' => '主账号自身是子账号(禁止多层)', 'parent' => $parentId, 'child' => $childId];
        continue;
    }
    if (SubAccountRelation::where('parent_user_id', $childId)->where('status', 1)->exists()) {
        $exceptions[] = ['relation_id' => $rid, 'reason' => '子账号自身已有子账号(禁止多层)', 'parent' => $parentId, 'child' => $childId];
        continue;
    }
    if (SubAccountRelation::where('parent_user_id', $parentId)->where('status', 1)->count() > 0
        && !($existing && (int)$existing->parent_user_id === $parentId)) {
        // 同一主账号已有其它启用子账号：按目标配置上限判断在服务层，这里只警告不阻断
        // （迁移不引入新的数量约束，是否超限由业务层决定）
    }

    $import[] = [
        'relation_id' => $rid,
        'parent_user_id' => $parentId,
        'child_user_id' => $childId,
        'traffic_limit' => (int)($r['traffic_limit'] ?? 0),
        'remark' => isset($r['remark']) ? $r['remark'] : null,
        'reuse' => (bool)$existing,
        'child_plan_id' => $child->plan_id,
        'child_has_traffic' => ((int)$child->u + (int)$child->d) > 0,
    ];
}

echo "--- 可导入（" . count($import) . " 条）---\n";
foreach ($import as $i) {
    printf("  #%s  %d -> %d  额度=%d  %s%s\n",
        $i['relation_id'], $i['parent_user_id'], $i['child_user_id'], $i['traffic_limit'],
        $i['reuse'] ? '复用已有关系' : '新建关系',
        $i['child_plan_id'] !== null ? '  [警告: 子账号自身仍有 plan_id=' . $i['child_plan_id'] . ']' : ''
    );
}

echo "\n--- 异常清单（" . count($exceptions) . " 条，不导入）---\n";
foreach ($exceptions as $e) {
    printf("  #%s  parent=%s child=%s  %s\n", $e['relation_id'], $e['parent'], $e['child'], $e['reason']);
}

$exceptionFile = sys_get_temp_dir() . '/subaccount_migration_exceptions_' . date('Ymd_His') . '.json';
file_put_contents($exceptionFile, json_encode($exceptions, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
echo "\n异常清单已写入: {$exceptionFile}\n";

if (!$apply) {
    echo "\n[DRY-RUN] 未写入任何数据。加 --apply 执行导入。\n";
    exit(0);
}

// ---------- 导入 ----------
echo "\n=== 单事务导入（仅写关系表与审计表）===\n";
DB::beginTransaction();
try {
    foreach ($import as $i) {
        $relation = SubAccountRelation::where('child_user_id', $i['child_user_id'])->lockForUpdate()->first();
        $reused = (bool)$relation;
        if (!$relation) {
            $relation = new SubAccountRelation();
            $relation->child_user_id = $i['child_user_id'];
            $relation->created_by_parent = 1;
            $relation->created_at = time();
        }
        $relation->parent_user_id = $i['parent_user_id'];
        $relation->traffic_limit = $i['traffic_limit'];
        $relation->remark = $i['remark'];
        $relation->status = SubAccountRelation::STATUS_ENABLED;
        $relation->updated_at = time();
        if (!$relation->save()) {
            throw new \RuntimeException("关系保存失败: child={$i['child_user_id']}");
        }

        $log = new SubAccountAuditLog();
        $log->relation_id = $relation->id;
        $log->parent_user_id = $i['parent_user_id'];
        $log->child_user_id = $i['child_user_id'];
        $log->actor_type = SubAccountAuditLog::ACTOR_SYSTEM;
        $log->actor_user_id = null;
        $log->action = 'migration_import';
        $log->metadata = [
            'source' => 'xboard',
            'source_relation_id' => $i['relation_id'],
            'traffic_limit' => $i['traffic_limit'],
            'relation_reused' => $reused,
        ];
        $log->ip = null;
        $log->created_at = time();
        $log->save();

        printf("  imported: %d -> %d (relation_id=%d%s)\n", $i['parent_user_id'], $i['child_user_id'], $relation->id, $reused ? ', reused' : '');
    }
    DB::commit();
    echo "事务提交成功。\n";
} catch (\Throwable $e) {
    DB::rollBack();
    fwrite(STDERR, "导入失败，已整体回滚: " . $e->getMessage() . "\n");
    exit(1);
}

// ---------- 导入后校验 ----------
echo "\n=== 导入后校验 ===\n";
$service = new App\Services\SubAccountService();
$stats = $service->healthStats();
echo "  active relations : {$stats['active_relations']}（期望 " . count($import) . "）\n";
echo "  orphan relations : {$stats['orphan_relations']}（期望 0）\n";
echo "  duplicate child  : {$stats['duplicate_child']}（期望 0）\n";
echo "  cycles           : {$stats['cycles']}（期望 0）\n";
echo "  审计 migration_import 条数: " . SubAccountAuditLog::where('action', 'migration_import')->count() . "\n";

$fail = 0;
if ((int)$stats['active_relations'] !== count($import)) { echo "  FAIL active 数量不符\n"; $fail++; }
if ((int)$stats['orphan_relations'] !== 0) { echo "  FAIL 存在孤儿关系\n"; $fail++; }
if ((int)$stats['duplicate_child'] !== 0) { echo "  FAIL 存在重复绑定\n"; $fail++; }
if ((int)$stats['cycles'] !== 0) { echo "  FAIL 存在循环引用\n"; $fail++; }
echo $fail === 0 ? "MIGRATION_OK\n" : "MIGRATION_FAILED\n";
exit($fail > 0 ? 1 : 0);
