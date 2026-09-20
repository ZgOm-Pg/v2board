<?php
/**
 * 子账号 — 真实 Redis 流量记账测试（在独立测试实例中执行）。
 *
 *   php tools/subaccount-redis-traffic-test.php
 *
 * 流程：
 *   1. 建 1 个父账号 + 2 个子账号（A 有 5GB 独立额度，B 无独立额度），并建立关系；
 *   2. 记录执行前 Redis Hash（v2board_upload_traffic / v2board_download_traffic）
 *      与 v2_user.u/d；
 *   3. 同一批次上报：父(5,3)MB、子A(20,40)MB、子B(60,80)MB，rate=1.0；
 *   4. 校验 Redis 精确增量；
 *   5. 执行 traffic:update 落库，校验 v2_user.u/d 精确增量；
 *   6. 再执行一次 traffic:update，确认幂等（不重复累计）；
 *   7. 校验节点用户名单包含两个子账号（子账号身份 + 父账号限速/设备数）；
 *   8. 清理本次创建的全部测试数据（用户/关系/审计/Redis 字段）。
 *
 * 只允许在测试实例执行。
 */

require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Jobs\TrafficFetchJob;
use App\Models\SubAccountAuditLog;
use App\Models\SubAccountRelation;
use App\Models\User;
use App\Services\ServerService;
use App\Utils\Helper;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;

$db = config('database.connections.mysql.database');
if (strpos($db, 'test') === false && strpos($db, 'subacct') === false && strpos($db, 'subtest') === false) {
    fwrite(STDERR, "拒绝执行：当前数据库 {$db} 不像测试库。\n");
    exit(2);
}

const MB = 1048576;
$UP = 'v2board_upload_traffic';
$DOWN = 'v2board_download_traffic';
$passed = 0;
$failed = 0;

function check($name, $cond, $detail = '')
{
    global $passed, $failed;
    if ($cond) { $passed++; echo "  ✔ {$name}" . ($detail !== '' ? "  [{$detail}]" : '') . PHP_EOL; }
    else { $failed++; echo "  ✘ {$name}" . ($detail !== '' ? "  [{$detail}]" : '') . PHP_EOL; }
}

function makeUser(array $attrs = [])
{
    $u = new User();
    $defaults = [
        'email' => 'rt-' . substr(md5((string)microtime(true) . random_int(1, 99999)), 0, 10) . '@example.com',
        'password' => password_hash('Passw0rd!23', PASSWORD_DEFAULT),
        'uuid' => Helper::guid(true),
        'token' => Helper::guid(),
        'balance' => 0, 'commission_balance' => 0,
        't' => 0, 'u' => 0, 'd' => 0,
        'transfer_enable' => 0,
        'banned' => 0, 'is_admin' => 0, 'is_staff' => 0,
        'group_id' => 1, 'plan_id' => null, 'expired_at' => null,
        'auto_renewal' => 0, 'remind_expire' => 0, 'remind_traffic' => 0,
        'speed_limit' => null, 'device_limit' => null,
        'created_at' => time(), 'updated_at' => time(),
    ];
    foreach (array_merge($defaults, $attrs) as $k => $v) { $u->{$k} = $v; }
    $u->save();
    return $u;
}

$created = [];
$tag = 'redis-' . substr(md5((string)microtime(true)), 0, 6);
echo "===== Redis 流量测试（tag={$tag}）=====\n";

$planId = DB::table('v2_plan')->value('id');
if ($planId === null) {
    DB::table('v2_plan')->insert([
        'id' => 1, 'group_id' => 1, 'transfer_enable' => 102400, 'device_limit' => 3,
        'name' => 'Traffic Test Plan', 'speed_limit' => 100, 'show' => 1, 'sort' => 1,
        'renew' => 1, 'content' => 'test', 'reset_traffic_method' => 0,
        'created_at' => time(), 'updated_at' => time(),
    ]);
    $planId = 1;
}
$parent = makeUser([
    'email' => "{$tag}-parent@example.com", 'group_id' => 1, 'plan_id' => $planId,
    'expired_at' => time() + 86400 * 30, 'transfer_enable' => 100 * 1024 * MB,
    'speed_limit' => 100, 'device_limit' => 3,
]);
$childA = makeUser(['email' => "{$tag}-a@example.com", 'group_id' => null, 'transfer_enable' => 0]);
$childB = makeUser(['email' => "{$tag}-b@example.com", 'group_id' => null, 'transfer_enable' => 0]);
$created = [$parent->id, $childA->id, $childB->id];

$relA = new SubAccountRelation();
$relA->parent_user_id = $parent->id; $relA->child_user_id = $childA->id;
$relA->traffic_limit = 5 * 1024 * MB; $relA->status = 1; $relA->created_by_parent = 0;
$relA->created_at = time(); $relA->updated_at = time(); $relA->save();

$relB = new SubAccountRelation();
$relB->parent_user_id = $parent->id; $relB->child_user_id = $childB->id;
$relB->traffic_limit = 0; $relB->status = 1; $relB->created_by_parent = 0;
$relB->created_at = time(); $relB->updated_at = time(); $relB->save();

$ids = [(string)$parent->id, (string)$childA->id, (string)$childB->id];
$snapshot = function () use ($UP, $DOWN, $ids) {
    $out = [];
    foreach ($ids as $id) {
        $out[$id] = ['u' => (int)(Redis::hget($UP, $id) ?: 0), 'd' => (int)(Redis::hget($DOWN, $id) ?: 0)];
    }
    return $out;
};
$dbSnapshot = function () use ($created) {
    $out = [];
    foreach ($created as $id) {
        $u = User::find($id);
        $out[(string)$id] = ['u' => (int)$u->u, 'd' => (int)$u->d];
    }
    return $out;
};

$redisBefore = $snapshot();
$dbBefore = $dbSnapshot();

echo "\n--- 批次上报: 父(5,3)MB 子A(20,40)MB 子B(60,80)MB rate=1.0 ---\n";
$batch = [
    $parent->id => [5 * MB, 3 * MB],
    $childA->id => [20 * MB, 40 * MB],
    $childB->id => [60 * MB, 80 * MB],
];
(new TrafficFetchJob($batch, ['rate' => 1.0], 'vless'))->handle();

$redisAfter = $snapshot();
$du = fn($id) => $redisAfter[(string)$id]['u'] - $redisBefore[(string)$id]['u'];
$dd = fn($id) => $redisAfter[(string)$id]['d'] - $redisBefore[(string)$id]['d'];

check('子账号A 个人上传 +20MB', $du($childA->id) === 20 * MB, $du($childA->id) . ' B');
check('子账号A 个人下载 +40MB', $dd($childA->id) === 40 * MB, $dd($childA->id) . ' B');
check('子账号B 个人上传 +60MB', $du($childB->id) === 60 * MB, $du($childB->id) . ' B');
check('子账号B 个人下载 +80MB', $dd($childB->id) === 80 * MB, $dd($childB->id) . ' B');
check('父账号上传 = 自身5MB + 子A20MB + 子B60MB', $du($parent->id) === 85 * MB, $du($parent->id) . ' B');
check('父账号下载 = 自身3MB + 子A40MB + 子B80MB', $dd($parent->id) === 123 * MB, $dd($parent->id) . ' B');
check('子账号未被写入父账号聚合值(无重复记账)', $du($childA->id) === 20 * MB && $du($childB->id) === 60 * MB);

echo "\n--- traffic:update 落库 ---\n";
Artisan::call('traffic:update');
$dbAfter = $dbSnapshot();
$ddu = fn($id) => $dbAfter[(string)$id]['u'] - $dbBefore[(string)$id]['u'];
$ddd = fn($id) => $dbAfter[(string)$id]['d'] - $dbBefore[(string)$id]['d'];

check('落库后 子账号A u/d = +20/40MB', $ddu($childA->id) === 20 * MB && $ddd($childA->id) === 40 * MB, $ddu($childA->id) . '/' . $ddd($childA->id));
check('落库后 子账号B u/d = +60/80MB', $ddu($childB->id) === 60 * MB && $ddd($childB->id) === 80 * MB, $ddu($childB->id) . '/' . $ddd($childB->id));
check('落库后 父账号 u/d = +85/123MB', $ddu($parent->id) === 85 * MB && $ddd($parent->id) === 123 * MB, $ddu($parent->id) . '/' . $ddd($parent->id));

$redisEmpty = $snapshot();
check('Redis Hash 已被 traffic:update 消费清零', $redisEmpty[(string)$parent->id]['u'] === 0 && $redisEmpty[(string)$childA->id]['u'] === 0);

Artisan::call('traffic:update');
$dbAgain = $dbSnapshot();
check('再次执行 traffic:update 不重复累计', $dbAgain[(string)$parent->id]['u'] === $dbAfter[(string)$parent->id]['u'], $dbAgain[(string)$parent->id]['u'] . ' B');

echo "\n--- 节点用户名单 ---\n";
$parent->refresh();
$members = (new ServerService())->getAvailableUsers([1]);
$byId = [];
foreach ($members as $m) { $byId[(int)$m->id] = $m; }
check('子账号A 出现在节点名单', isset($byId[$childA->id]));
check('子账号B 出现在节点名单', isset($byId[$childB->id]));
if (isset($byId[$childA->id])) {
    check('节点名单使用子账号自身 uuid', $byId[$childA->id]->uuid === $childA->uuid);
    check('节点名单使用父账号限速/设备数', (int)$byId[$childA->id]->speed_limit === 100 && (int)$byId[$childA->id]->device_limit === 3,
        $byId[$childA->id]->speed_limit . '/' . $byId[$childA->id]->device_limit);
}

echo "\n--- 额度耗尽联动 ---\n";
// 让子账号A 个人额度耗尽（5GB）
DB::table('v2_user')->where('id', $childA->id)->update(['u' => 5 * 1024 * MB, 'd' => 0]);
$members2 = (new ServerService())->getAvailableUsers([1]);
$ids2 = array_map(function ($m) { return (int)$m->id; }, $members2->all());
check('个人额度耗尽后子账号A 从节点名单移除', !in_array($childA->id, $ids2, true));
check('子账号B 仍在节点名单', in_array($childB->id, $ids2, true));
$ent = (new App\Services\SubAccountService())->resolveEntitlement(User::find($childA->id));
check('个人额度耗尽后 canConnect=false', $ent->canConnect() === false);

echo "\n--- 清理测试数据 ---\n";
foreach (array_unique(array_merge($ids, [(string)$parent->id, (string)$childA->id, (string)$childB->id])) as $field) {
    Redis::hdel($UP, (string)$field);
    Redis::hdel($DOWN, (string)$field);
}
SubAccountAuditLog::whereIn('parent_user_id', $created)->orWhereIn('child_user_id', $created)->delete();
SubAccountRelation::whereIn('parent_user_id', $created)->orWhereIn('child_user_id', $created)->delete();
User::whereIn('id', $created)->delete();
check('测试数据已清理', User::whereIn('id', $created)->count() === 0 && SubAccountRelation::whereIn('parent_user_id', $created)->count() === 0);

echo "\n===== 结果 =====\nPASS={$passed} FAIL={$failed}\n";
echo $failed === 0 ? "REDIS_TRAFFIC_OK\n" : "REDIS_TRAFFIC_FAILED\n";
exit($failed === 0 ? 0 : 1);
