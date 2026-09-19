<?php
/**
 * 现有子账号关系迁移脚本（源 XBoard -> 目标 V2Board）。
 *
 * 用途: 把源站 XBoard 中「启用且父子均存在」的子账号关系导入目标 V2Board 的
 *       v2_user_sub_accounts 表，并写入审计日志。
 *
 * 用法（在目标 V2Board 项目根目录执行）:
 *   php tools/subaccount-migrate-relations.php --dry-run     # 只校验，不写入
 *   php tools/subaccount-migrate-relations.php --apply       # 单事务导入
 *
 * 设计约束:
 *   - 只迁移启用关系；停用关系、孤立关系、旧审计日志一律不迁移；
 *   - 导入前逐项校验目标用户存在、ID/邮箱一致、无冲突/循环/多层；
 *   - 两个子账号残留的"复制套餐字段"归一为中性值；
 *   - 保留邮箱、密码、UUID、Token、u、d；
 *   - 单事务，失败整体回滚；
 *   - 不累加主账号 u/d（主账号现有 u/d 已包含历史子账号流量）。
 */

require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\SubAccountAuditLog;
use App\Models\SubAccountRelation;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * 需要迁移的关系。
 * expected_email 用于导入前核对 ID 与邮箱是否一致，防止 ID 复用导致错绑。
 */
$RELATIONS = [
    [
        'parent_user_id' => 7840,
        'child_user_id' => 7843,
        'traffic_limit' => 32212254720,
        'parent_email' => 'fanyilin0916@163.com',
        'child_email' => '15518961871@163.com',
        'remark' => null,
    ],
    [
        'parent_user_id' => 4948,
        'child_user_id' => 8593,
        'traffic_limit' => 21474836480,
        'parent_email' => 'shenqixiaoxuanxuan666@gmail.com',
        'child_email' => '1969460985@qq.com',
        'remark' => null,
    ],
];

$apply = in_array('--apply', $argv, true);
$dryRun = in_array('--dry-run', $argv, true) || !$apply;

if (!$apply && !$dryRun) {
    fwrite(STDERR, "用法: php tools/subaccount-migrate-relations.php [--dry-run|--apply]\n");
    exit(2);
}

$errors = [];
$ok = function ($cond, $msg) use (&$errors) {
    if ($cond) {
        echo "  OK    {$msg}\n";
    } else {
        echo "  FAIL  {$msg}\n";
        $errors[] = $msg;
    }
    return $cond;
};

echo "=== 迁移前校验 (mode=" . ($apply ? 'apply' : 'dry-run') . ") ===\n";
echo "database: " . DB::connection()->getDatabaseName() . "\n\n";

// 1. 表存在
foreach (['v2_user_sub_accounts', 'v2_sub_account_audit_logs'] as $t) {
    $row = DB::selectOne(
        'SELECT COUNT(*) c FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
        [$t]
    );
    $ok((int)$row->c === 1, "表 {$t} 存在");
}
if ($errors) {
    echo "\n表结构未就绪，请先执行: php artisan sub-account:install --apply\n";
    exit(1);
}

// 2. 用户存在 + ID/邮箱一致
echo "\n--- 目标用户核对 ---\n";
foreach ($RELATIONS as $r) {
    foreach ([['parent', $r['parent_user_id'], $r['parent_email']], ['child', $r['child_user_id'], $r['child_email']]] as $u) {
        $user = User::find($u[1]);
        $ok($user !== null, "{$u[0]} 用户 #{$u[1]} 存在");
        if ($user) {
            $ok(strtolower($user->email) === strtolower($u[2]),
                "{$u[0]} #{$u[1]} 邮箱一致 ({$user->email})");
        }
    }
}

// 3. 无冲突 / 无循环 / 无多层
echo "\n--- 冲突与结构校验 ---\n";
foreach ($RELATIONS as $r) {
    $existingChild = SubAccountRelation::where('child_user_id', $r['child_user_id'])->first();
    if ($existingChild) {
        $ok((int)$existingChild->parent_user_id === (int)$r['parent_user_id'],
            "子账号 #{$r['child_user_id']} 已存在关系且主账号一致（将复用/启用）");
    } else {
        $ok(true, "子账号 #{$r['child_user_id']} 无既有关系");
    }
    $ok(!SubAccountRelation::where('child_user_id', $r['parent_user_id'])
        ->where('status', SubAccountRelation::STATUS_ENABLED)->exists(),
        "主账号 #{$r['parent_user_id']} 自身不是子账号（无多层）");
    $ok(!SubAccountRelation::where('parent_user_id', $r['child_user_id'])
        ->where('status', SubAccountRelation::STATUS_ENABLED)->exists(),
        "子账号 #{$r['child_user_id']} 自身没有子账号（无多层）");
    $ok((int)$r['parent_user_id'] !== (int)$r['child_user_id'], "不是自绑定 #{$r['parent_user_id']}");
}

// 4. 停用关系数量核对（预期 6 条，不迁移）
$disabled = SubAccountRelation::where('status', SubAccountRelation::STATUS_DISABLED)->count();
echo "\n--- 现状 ---\n";
echo "  已存在关系总数: " . SubAccountRelation::count() . " (其中停用 {$disabled})\n";
echo "  迁移目标: " . count($RELATIONS) . " 条启用关系\n";

if ($errors) {
    echo "\n校验失败 " . count($errors) . " 项，未做任何写入。\n";
    exit(1);
}

if (!$apply) {
    echo "\n校验全部通过。[dry-run] 未写入任何数据。加 --apply 执行导入。\n";
    exit(0);
}

// ---------------------------------------------------------------- 导入
echo "\n=== 单事务导入 ===\n";
DB::beginTransaction();
try {
    foreach ($RELATIONS as $r) {
        $child = User::lockForUpdate()->find($r['child_user_id']);
        if (!$child) throw new \RuntimeException("子账号 #{$r['child_user_id']} 不存在");

        // 归一化子账号残留的复制套餐字段（保留邮箱/密码/uuid/token/u/d）
        $before = [
            'plan_id' => $child->plan_id,
            'group_id' => $child->group_id,
            'expired_at' => $child->expired_at,
            'transfer_enable' => $child->transfer_enable,
            'speed_limit' => $child->speed_limit,
            'device_limit' => $child->device_limit,
        ];
        $child->plan_id = null;
        $child->group_id = null;
        $child->expired_at = null;
        $child->transfer_enable = 0;
        $child->speed_limit = null;
        $child->device_limit = null;
        $child->auto_renewal = 0;
        $child->remind_expire = 0;
        $child->remind_traffic = 0;
        if (!$child->save()) throw new \RuntimeException("子账号 #{$child->id} 归一化保存失败");

        $relation = SubAccountRelation::where('child_user_id', $r['child_user_id'])->lockForUpdate()->first();
        $reused = (bool)$relation;
        if (!$relation) {
            $relation = new SubAccountRelation();
            $relation->child_user_id = $r['child_user_id'];
        }
        $relation->parent_user_id = $r['parent_user_id'];
        $relation->traffic_limit = $r['traffic_limit'];
        $relation->remark = $r['remark'];
        $relation->status = SubAccountRelation::STATUS_ENABLED;
        $relation->created_by_parent = 1;
        if (!$relation->save()) throw new \RuntimeException("关系 #{$r['child_user_id']} 保存失败");

        $log = new SubAccountAuditLog();
        $log->relation_id = $relation->id;
        $log->parent_user_id = $r['parent_user_id'];
        $log->child_user_id = $r['child_user_id'];
        $log->actor_type = SubAccountAuditLog::ACTOR_SYSTEM;
        $log->actor_user_id = null;
        $log->action = 'migrate_import';
        $log->metadata = [
            'source' => 'xboard',
            'traffic_limit' => $r['traffic_limit'],
            'created_by_parent' => true,
            'relation_reused' => $reused,
            'normalized_from' => $before,
        ];
        $log->ip = null;
        $log->created_at = time();
        $log->save();

        printf("  imported: %d -> %d (traffic_limit=%d, relation_id=%d%s)\n",
            $r['parent_user_id'], $r['child_user_id'], $r['traffic_limit'],
            $relation->id, $reused ? ', reused' : '');
    }
    DB::commit();
    echo "事务提交成功。\n";
} catch (\Throwable $e) {
    DB::rollBack();
    fwrite(STDERR, "导入失败，已整体回滚: " . $e->getMessage() . "\n");
    exit(1);
}

// ---------------------------------------------------------------- 导入后校验
echo "\n=== 导入后校验 ===\n";
$service = new App\Services\SubAccountService();
$stats = $service->healthStats();
echo "  active relations : {$stats['active_relations']}\n";
echo "  orphan relations : {$stats['orphan_relations']}\n";
echo "  duplicate child  : {$stats['duplicate_child']}\n";
echo "  cycles           : {$stats['cycles']}\n";

$fail = 0;
if ($stats['active_relations'] !== 2) { echo "  FAIL active != 2\n"; $fail++; }
if ($stats['orphan_relations'] !== 0) { echo "  FAIL orphan != 0\n"; $fail++; }
if ($stats['duplicate_child'] !== 0) { echo "  FAIL duplicate != 0\n"; $fail++; }
if ($stats['cycles'] !== 0) { echo "  FAIL cycles != 0\n"; $fail++; }

exit($fail > 0 ? 1 : 0);
