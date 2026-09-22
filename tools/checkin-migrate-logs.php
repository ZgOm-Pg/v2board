<?php
/**
 * 签到历史数据同步（原站停用后执行；默认 dry-run）
 *
 * 设计原则（务必遵守）：
 *   1. **只写** `v2_checkin_rewards` 与 `v2_user_checkin_logs`，绝不 UPDATE `v2_user`；
 *   2. 历史日志一律 `credited = 0`、`source = migration`：
 *      原站发放奖励时已经把流量加进了 transfer_enable，本功能**绝不能**再加一次；
 *   3. 已存在的 (user_id, checkin_date) 行**保留原值**（INSERT IGNORE），不重算、不覆盖；
 *   4. 只导入「目标库存在该 user_id 且邮箱一致」的记录，其余进入异常清单文件；
 *   5. 单事务、幂等、可重复执行；--apply 结束后打印 transfer_enable 汇总校验。
 *
 * 用法：
 *   # 1) 在原站导出（只读）
 *   mysql -N -B -e "SELECT ..." > relations.jsonl
 *   # 2) 预演（不写库）
 *   php tools/checkin-migrate-logs.php --source=logs.jsonl --rules=rules.jsonl
 *   # 3) 正式导入
 *   php tools/checkin-migrate-logs.php --source=logs.jsonl --rules=rules.jsonl --apply
 *   # 4) 核对额度增量与不存在用户（只读报告）
 *   php tools/checkin-migrate-logs.php --quota=quota.jsonl
 */

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';
$app = require $root . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\CheckinReward;
use App\Models\User;
use App\Models\UserCheckinLog;
use Illuminate\Support\Facades\DB;

$options = [
    'source' => null,   // 签到日志 jsonl
    'rules' => null,    // 奖励规则 jsonl
    'quota' => null,    // 额度核对 jsonl（只读）
    'apply' => false,
    'exceptions' => $root . '/storage/app/checkin-migration-exceptions.jsonl',
];

foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--apply') $options['apply'] = true;
    elseif ($arg === '--dry-run') $options['apply'] = false;
    elseif (strpos($arg, '--source=') === 0) $options['source'] = substr($arg, 9);
    elseif (strpos($arg, '--rules=') === 0) $options['rules'] = substr($arg, 8);
    elseif (strpos($arg, '--quota=') === 0) $options['quota'] = substr($arg, 8);
    elseif (strpos($arg, '--exceptions=') === 0) $options['exceptions'] = substr($arg, 13);
    else {
        fwrite(STDERR, "未知参数: {$arg}\n");
        exit(2);
    }
}

function line($msg = '') { echo $msg . PHP_EOL; }
function readJsonl($path)
{
    if (!is_file($path)) {
        fwrite(STDERR, "找不到文件: {$path}\n");
        exit(1);
    }
    $rows = [];
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $no => $raw) {
        $row = json_decode($raw, true);
        if (!is_array($row)) {
            fwrite(STDERR, "第 " . ($no + 1) . " 行不是合法 JSON，已跳过\n");
            continue;
        }
        $rows[] = $row;
    }
    return $rows;
}

line('=== 签到历史数据同步 ===');
line('database : ' . DB::connection()->getDatabaseName());
line('模式     : ' . ($options['apply'] ? 'APPLY（写入）' : 'DRY-RUN（只读预演）'));
line('');

/* ------------------------------------------------------------------ 额度核对模式 */
if ($options['quota']) {
    $rows = readJsonl($options['quota']);
    $missing = [];
    $mismatch = [];
    $okCount = 0;
    foreach ($rows as $row) {
        $userId = isset($row['user_id']) ? (int)$row['user_id'] : 0;
        $email = isset($row['email']) ? (string)$row['email'] : '';
        $originTransfer = isset($row['transfer_enable']) ? (int)$row['transfer_enable'] : null;
        $user = User::find($userId);
        if (!$user) {
            $missing[] = ['user_id' => $userId, 'email' => $email, 'reason' => '目标库不存在该 user_id'];
            continue;
        }
        if ($email !== '' && strcasecmp($user->email, $email) !== 0) {
            $missing[] = ['user_id' => $userId, 'email' => $email, 'target_email' => $user->email, 'reason' => '邮箱不一致'];
            continue;
        }
        if ($originTransfer !== null && (int)$user->transfer_enable !== $originTransfer) {
            $mismatch[] = [
                'user_id' => $userId,
                'email' => $email,
                'origin_transfer_enable' => $originTransfer,
                'target_transfer_enable' => (int)$user->transfer_enable,
                'delta' => (int)$user->transfer_enable - $originTransfer,
            ];
            continue;
        }
        $okCount++;
    }
    line('额度核对行数: ' . count($rows));
    line('  一致      : ' . $okCount);
    line('  额度不一致: ' . count($mismatch));
    foreach (array_slice($mismatch, 0, 20) as $m) {
        line('    #' . $m['user_id'] . ' ' . $m['email'] . " 原站={$m['origin_transfer_enable']} 目标={$m['target_transfer_enable']} 差={$m['delta']}");
    }
    line('  不存在的用户引用: ' . count($missing));
    foreach (array_slice($missing, 0, 20) as $m) {
        line('    #' . $m['user_id'] . ' ' . $m['email'] . ' -> ' . $m['reason']);
    }
    line('');
    line('提示：额度不一致通常意味着停站后仍有流量结算写入；请先确认原站已完全停止写入再重跑本核对。');
    exit((count($missing) || count($mismatch)) ? 1 : 0);
}

if (!$options['source']) {
    fwrite(STDERR, "缺少 --source=<logs.jsonl>（或使用 --quota=<quota.jsonl> 只做额度核对）\n");
    exit(2);
}

/* ------------------------------------------------------------------ 规则导入 */
$ruleRows = $options['rules'] ? readJsonl($options['rules']) : [];
if ($ruleRows && $options['apply']) {
    line('--- 奖励规则（按 day_index 幂等 upsert，保留原站数值）---');
    $upserted = 0;
    foreach ($ruleRows as $row) {
        $dayIndex = isset($row['day_index']) ? (int)$row['day_index'] : 0;
        if ($dayIndex < 1 || $dayIndex > 30) {
            line('  跳过非法档位: ' . json_encode($row, JSON_UNESCAPED_UNICODE));
            continue;
        }
        $bytes = isset($row['reward_bytes']) ? (int)$row['reward_bytes'] : 0;
        $text = isset($row['reward_text']) ? (string)$row['reward_text'] : '';
        $enabled = isset($row['enabled']) ? (int)$row['enabled'] : 1;
        $rule = CheckinReward::where('day_index', $dayIndex)->first();
        if (!$rule) {
            $rule = new CheckinReward();
            $rule->day_index = $dayIndex;
            $rule->created_at = time();
        }
        $rule->reward_bytes = $bytes;
        $rule->reward_text = $text !== '' ? $text : '';
        $rule->enabled = $enabled === 1 ? 1 : 0;
        $rule->sort = $dayIndex;
        $rule->updated_at = time();
        $rule->save();
        $upserted++;
    }
    line("  已 upsert {$upserted} 条规则");
    line('');
} elseif ($ruleRows) {
    line('--- 奖励规则（预演）--- 将 upsert ' . count($ruleRows) . ' 条（--apply 时才写入）');
    line('');
}

/* ------------------------------------------------------------------ 日志导入 */
$rows = readJsonl($options['source']);
line('--- 签到日志 ---');
line('  源文件行数: ' . count($rows));

$transferBefore = (int)DB::table('v2_user')->sum('transfer_enable');
$userCountBefore = (int)DB::table('v2_user')->count();
$logCountBefore = (int)UserCheckinLog::count();

$imported = 0;
$skippedExisting = 0;
$exceptions = [];
$seen = [];

foreach ($rows as $row) {
    $userId = isset($row['user_id']) ? (int)$row['user_id'] : 0;
    $email = isset($row['email']) ? (string)$row['email'] : '';
    $date = isset($row['checkin_date']) ? substr((string)$row['checkin_date'], 0, 10) : '';
    $continuous = isset($row['continuous_days']) ? (int)$row['continuous_days'] : 1;
    $dayIndex = isset($row['day_index']) ? (int)$row['day_index'] : ((($continuous - 1) % 30) + 1);
    $rewardBytes = isset($row['reward_bytes']) ? (int)$row['reward_bytes'] : 0;
    $rewardText = isset($row['reward_text']) ? (string)$row['reward_text'] : '';
    $createdAt = isset($row['created_at']) ? (int)$row['created_at'] : null;

    if (!$userId || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        $exceptions[] = ['row' => $row, 'reason' => 'user_id 或 checkin_date 非法'];
        continue;
    }
    $key = $userId . '|' . $date;
    if (isset($seen[$key])) {
        $exceptions[] = ['row' => $row, 'reason' => '源文件内重复 (user_id, checkin_date)'];
        continue;
    }
    $seen[$key] = true;

    $user = User::find($userId);
    if (!$user) {
        $exceptions[] = ['row' => $row, 'reason' => '目标库不存在该 user_id'];
        continue;
    }
    if ($email !== '' && strcasecmp($user->email, $email) !== 0) {
        $exceptions[] = ['row' => $row, 'reason' => '邮箱与目标库不一致', 'target_email' => $user->email];
        continue;
    }

    $exists = UserCheckinLog::where('user_id', $userId)->where('checkin_date', $date)->exists();
    if ($exists) {
        $skippedExisting++;
        continue;
    }

    if ($options['apply']) {
        DB::table('v2_user_checkin_logs')->insert([
            'user_id' => $userId,
            'checkin_date' => $date,
            'continuous_days' => max(1, $continuous),
            'day_index' => max(1, min(30, $dayIndex)),
            'reward_rule_id' => null,
            'reward_bytes' => $rewardBytes,
            'reward_text' => mb_substr($rewardText, 0, 32),
            // 关键：credited=0 —— 原站已把奖励计入 transfer_enable，此处绝不能再加
            'credited' => 0,
            'source' => UserCheckinLog::SOURCE_MIGRATION,
            'created_at' => $createdAt,
        ]);
    }
    $imported++;
}

line('  可导入    : ' . $imported);
line('  已存在跳过: ' . $skippedExisting);
line('  异常      : ' . count($exceptions));

if ($exceptions) {
    $fp = fopen($options['exceptions'], 'w');
    foreach ($exceptions as $e) {
        fwrite($fp, json_encode($e, JSON_UNESCAPED_UNICODE) . "\n");
    }
    fclose($fp);
    line('  异常清单  : ' . $options['exceptions']);
}

if ($options['apply']) {
    $transferAfter = (int)DB::table('v2_user')->sum('transfer_enable');
    $userCountAfter = (int)DB::table('v2_user')->count();
    $logCountAfter = (int)UserCheckinLog::count();
    line('');
    line('--- 校验 ---');
    line('  v2_user 行数: ' . $userCountBefore . ' -> ' . $userCountAfter . ($userCountBefore === $userCountAfter ? '  OK' : '  FAIL'));
    line('  transfer_enable 合计: ' . $transferBefore . ' -> ' . $transferAfter . ($transferBefore === $transferAfter ? '  OK（未修改任何用户额度）' : '  FAIL（不得修改用户额度！）'));
    line('  签到日志行数: ' . $logCountBefore . ' -> ' . $logCountAfter);
    line('  新增日志中 credited=0 的行数: ' . (int)UserCheckinLog::where('source', UserCheckinLog::SOURCE_MIGRATION)->where('credited', 0)->count());
}

line('');
line($options['apply'] ? '导入完成。' : '预演完成（未写入任何数据）。加 --apply 执行导入。');
exit(0);
