<?php
/**
 * 子账号功能 — 独立实例的真实 HTTP 验收脚本（在测试实例目录内执行）。
 *
 *   php tools/subaccount-acceptance.php [baseUrl]
 *
 * 覆盖：真实登录、真实 HTTP（form-urlencoded 与 JSON 两种编码）、
 *       EZ-Theme 契约字段、越权与状态码、普通用户无回归、管理端页面与资源可加载、
 *       以及在结束时清理本次创建的全部测试数据。
 *
 * 只在独立测试实例上使用：脚本会创建/删除测试用户，禁止在生产实例执行。
 */

$base = isset($argv[1]) ? rtrim($argv[1], '/') : 'http://127.0.0.1:8085';

require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\SubAccountAuditLog;
use App\Models\SubAccountRelation;
use App\Models\User;

$db = config('database.connections.mysql.database');
if (strpos($db, 'test') === false && strpos($db, 'subacct') === false && strpos($db, 'subtest') === false) {
    fwrite(STDERR, "拒绝执行：当前数据库 {$db} 不像测试库，请显式确认实例。\n");
    exit(2);
}

$passed = 0;
$failed = 0;
$tag = 'e2e-' . substr(md5((string)microtime(true)), 0, 8);
$createdUserIds = [];

// 无论中途是否失败，都要清理本次创建的测试数据
register_shutdown_function(function () use (&$createdUserIds) {
    try {
        \App\Models\SubAccountAuditLog::whereIn('parent_user_id', $createdUserIds)
            ->orWhereIn('child_user_id', $createdUserIds)->delete();
        \App\Models\SubAccountRelation::whereIn('parent_user_id', $createdUserIds)
            ->orWhereIn('child_user_id', $createdUserIds)->delete();
        \App\Models\User::whereIn('id', $createdUserIds)->delete();
    } catch (\Throwable $e) {
    }
});

function check($name, $cond, $detail = '')
{
    global $passed, $failed;
    if ($cond) {
        $passed++;
        echo "  ✔ {$name}" . ($detail !== '' ? "  [{$detail}]" : '') . PHP_EOL;
    } else {
        $failed++;
        echo "  ✘ {$name}" . ($detail !== '' ? "  [{$detail}]" : '') . PHP_EOL;
    }
}

function http($method, $url, $body = null, $headers = [], $json = false)
{
    $ch = curl_init($url);
    $h = [];
    foreach ($headers as $k => $v) {
        $h[] = (is_int($k) ? $v : "{$k}: {$v}");
    }
    if ($body !== null) {
        if ($json) {
            $payload = json_encode($body, JSON_UNESCAPED_UNICODE);
            $h[] = 'Content-Type: application/json';
        } else {
            $payload = http_build_query($body);
            $h[] = 'Content-Type: application/x-www-form-urlencoded';
        }
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    }
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $h,
        CURLOPT_TIMEOUT => 30,
    ]);
    $raw = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['status' => $status, 'raw' => $raw, 'json' => json_decode((string)$raw, true)];
}

echo "===== 0. 实例可用性 =====\n";
$root = http('GET', $base . '/');
check('面板首页可访问', $root['status'] === 200, 'HTTP ' . $root['status']);
$adminPath = config('v2board.secure_path', config('v2board.frontend_admin_path', hash('crc32b', config('app.key'))));
$admin = http('GET', $base . '/' . $adminPath);
check('后台页面可访问', $admin['status'] === 200, 'HTTP ' . $admin['status'] . ' /' . $adminPath);
check('后台加载子账号 JS 资源', strpos((string)$admin['raw'], 'subaccount-admin-page.js') !== false);
check('后台加载子账号 CSS 资源', strpos((string)$admin['raw'], 'subaccount-admin-page.css') !== false);
$js = http('GET', $base . '/assets/admin/subaccount-admin-page.js');
check('子账号 JS 静态资源 200', $js['status'] === 200, 'HTTP ' . $js['status']);
$css = http('GET', $base . '/assets/admin/subaccount-admin-page.css');
check('子账号 CSS 静态资源 200', $css['status'] === 200, 'HTTP ' . $css['status']);
$noauth = http('GET', $base . '/api/v1/user/sub-account/list');
check('未登录访问子账号接口被拒绝', in_array($noauth['status'], [401, 403], true), 'HTTP ' . $noauth['status']);

echo "\n===== 1. 数据准备（父账号 / 普通用户）=====\n";
$parentEmail = "{$tag}-parent@example.com";
$normalEmail = "{$tag}-normal@example.com";
$password = 'Passw0rd!23';

$parent = new User();
$parent->email = $parentEmail;
$parent->password = password_hash($password, PASSWORD_DEFAULT);
$parent->uuid = App\Utils\Helper::guid(true);
$parent->token = App\Utils\Helper::guid();
$parent->balance = 0;
$parent->commission_balance = 0;
$parent->t = 0; $parent->u = 0; $parent->d = 0;
$parent->transfer_enable = 107374182400;
$parent->banned = 0; $parent->is_admin = 0; $parent->is_staff = 0;
$parent->group_id = 1; $parent->plan_id = null; $parent->expired_at = time() + 86400 * 30;
$parent->auto_renewal = 0; $parent->remind_expire = 0; $parent->remind_traffic = 0;
$parent->created_at = time(); $parent->updated_at = time();
$parent->save();
$createdUserIds[] = $parent->id;

$normal = new User();
$normal->email = $normalEmail;
$normal->password = password_hash($password, PASSWORD_DEFAULT);
$normal->uuid = App\Utils\Helper::guid(true);
$normal->token = App\Utils\Helper::guid();
$normal->balance = 0; $normal->commission_balance = 0;
$normal->t = 0; $normal->u = 0; $normal->d = 0;
$normal->transfer_enable = 107374182400;
$normal->banned = 0; $normal->is_admin = 0; $normal->is_staff = 0;
$normal->group_id = 1; $normal->plan_id = null; $normal->expired_at = time() + 86400 * 30;
$normal->auto_renewal = 0; $normal->remind_expire = 0; $normal->remind_traffic = 0;
$normal->created_at = time(); $normal->updated_at = time();
$normal->save();
$createdUserIds[] = $normal->id;

// 目标库是否已有可用套餐（测试实例如为空则补一条测试套餐）
$planId = Illuminate\Support\Facades\DB::table('v2_plan')->value('id');
if ($planId === null) {
    Illuminate\Support\Facades\DB::table('v2_plan')->insert([
        'id' => 1, 'group_id' => 1, 'transfer_enable' => 102400, 'device_limit' => 3,
        'name' => 'Acceptance Test Plan', 'speed_limit' => 100, 'show' => 1, 'sort' => 1,
        'renew' => 1, 'content' => 'test', 'reset_traffic_method' => 0,
        'created_at' => time(), 'updated_at' => time(),
    ]);
    $planId = 1;
}
check('存在可用套餐(用于父账号)', $planId !== null, 'plan_id=' . var_export($planId, true));
$parent->plan_id = $planId;
$parent->save();

echo "\n===== 2. 真实登录 =====\n";
$login = http('POST', $base . '/api/v1/passport/auth/login', [
    'email' => $parentEmail,
    'password' => $password,
], [], true);
check('父账号登录成功', $login['status'] === 200 && !empty($login['json']['data']['auth_data']), 'HTTP ' . $login['status']);
$token = $login['json']['data']['auth_data'] ?? '';
$auth = ['authorization' => $token];

echo "\n===== 3. 列表契约 =====\n";
$list = http('GET', $base . '/api/v1/user/sub-account/list', null, $auth);
check('list 200', $list['status'] === 200, 'HTTP ' . $list['status']);
check('list data.enabled 为布尔 true', ($list['json']['data']['enabled'] ?? null) === true, json_encode($list['json']['data']['enabled'] ?? null));
check('list items 是数组', is_array($list['json']['data']['items'] ?? null));
check('list 含 max_count / created_count', isset($list['json']['data']['max_count'], $list['json']['data']['created_count']));

echo "\n===== 4. 验证码（表单编码端点）=====\n";
$childEmail = "{$tag}-child@example.com";
$send = http('POST', $base . '/api/v1/user/sub-account/send-code', ['email' => $childEmail], $auth);
check('send-code 返回 200', $send['status'] === 200, 'HTTP ' . $send['status']);

echo "\n===== 5. 绑定（email_code + traffic_limit_gb）=====\n";
// 直接从缓存取出验证码（测试实例专用捷径；生产由邮件投递）
$hash = hash('sha256', strtolower(trim($childEmail)) . '|' . config('app.key'));
$cacheKey = App\Utils\CacheKey::get('SUB_ACCOUNT_EMAIL_CODE', $hash);
$code = Illuminate\Support\Facades\Cache::get($cacheKey);
check('验证码已写入缓存', !empty($code), 'code=' . ($code ? '******' : '空'));

$bind = http('POST', $base . '/api/v1/user/sub-account/bind', [
    'email' => $childEmail,
    'email_code' => (string)$code,
    'traffic_limit_gb' => '2',
    'remark' => 'acceptance',
], $auth);
check('bind 200', $bind['status'] === 200, 'HTTP ' . $bind['status'] . ' ' . substr((string)$bind['raw'], 0, 120));
$child = User::where('email', $childEmail)->first();
check('子账号用户已创建', $child !== null);
if ($child) {
    $createdUserIds[] = $child->id;
    check('子账号字段中性(plan_id/expired_at/balance)', $child->plan_id === null && $child->expired_at === null && (int)$child->balance === 0);
    check('子账号 uuid/token 独立', $child->uuid !== $parent->uuid && $child->token !== $parent->token);
}
$relation = $child ? SubAccountRelation::where('child_user_id', $child->id)->first() : null;
check('关系已建立', $relation !== null);
check('2GB 换算为字节', $relation && (int)$relation->traffic_limit === 2 * 1073741824, $relation ? (string)$relation->traffic_limit : '-');

echo "\n===== 6. 列表项契约字段 =====\n";
$list2 = http('GET', $base . '/api/v1/user/sub-account/list', null, $auth);
$item = $list2['json']['data']['items'][0] ?? null;
check('items[0].id 存在', isset($item['id']));
check('items[0].child_user_id 存在', isset($item['child_user_id']));
check('items[0].used_traffic_text 为非空字符串', isset($item['used_traffic_text']) && is_string($item['used_traffic_text']) && $item['used_traffic_text'] !== '');
check('items[0].traffic_limit_gb 为 2', isset($item['traffic_limit_gb']) && (float)$item['traffic_limit_gb'] === 2.0);
check('items[0].subscribe_url 存在', !empty($item['subscribe_url']));
check('created_count 为 1', ($list2['json']['data']['created_count'] ?? null) === 1);

echo "\n===== 7. 修改额度/备注（form）=====\n";
$upd = http('POST', $base . '/api/v1/user/sub-account/update', [
    'id' => $relation->id,
    'traffic_limit_gb' => '3',
    'remark' => '',
], $auth);
check('update 200', $upd['status'] === 200, 'HTTP ' . $upd['status']);
$relation->refresh();
check('额度更新为 3GB', (int)$relation->traffic_limit === 3 * 1073741824, (string)$relation->traffic_limit);
check('空备注被清空', $relation->remark === null, var_export($relation->remark, true));

echo "\n===== 8. 改密（JSON，new_password + child_user_id）=====\n";
$newPassword = 'NewPassw0rd!23';
$cp = http('POST', $base . '/api/v1/user/sub-account/change-password', [
    'id' => $relation->id,
    'child_user_id' => $child->id,
    'email' => $child->email,
    'new_password' => $newPassword,
    'password' => $newPassword,
], $auth, true);
check('change-password 200', $cp['status'] === 200, 'HTTP ' . $cp['status'] . ' ' . substr((string)$cp['raw'], 0, 120));
$childLogin = http('POST', $base . '/api/v1/passport/auth/login', ['email' => $childEmail, 'password' => $newPassword], [], true);
check('子账号可用新密码登录', $childLogin['status'] === 200 && !empty($childLogin['json']['data']['auth_data']), 'HTTP ' . $childLogin['status']);
$childToken = $childLogin['json']['data']['auth_data'] ?? '';

echo "\n===== 9. 订阅（按关系 id）=====\n";
$sub = http('GET', $base . '/api/v1/user/sub-account/subscribe?id=' . $relation->id, null, $auth);
check('subscribe 200', $sub['status'] === 200, 'HTTP ' . $sub['status']);
check('subscribe 返回 subscribe_url', !empty($sub['json']['data']['subscribe_url']));

echo "\n===== 10. 重置订阅（JSON）=====\n";
$oldToken = $child->token;
$rs = http('POST', $base . '/api/v1/user/sub-account/reset-subscribe', ['id' => $relation->id], $auth, true);
check('reset-subscribe 200', $rs['status'] === 200, 'HTTP ' . $rs['status']);
$child->refresh();
check('订阅 token 已轮换', $child->token !== $oldToken);
check('返回新的 subscribe_url', !empty($rs['json']['data']['subscribe_url']));

echo "\n===== 11. 子账号自身权限（中间件）=====\n";
if ($childToken) {
    $childList = http('GET', $base . '/api/v1/user/sub-account/list', null, ['authorization' => $childToken]);
    check('子账号 list 返回空 items', ($childList['json']['data']['items'] ?? null) === [], 'HTTP ' . $childList['status']);
    $childBind = http('POST', $base . '/api/v1/user/sub-account/send-code', ['email' => "{$tag}-x@example.com"], ['authorization' => $childToken]);
    check('子账号不能创建子账号(403)', $childBind['status'] === 403, 'HTTP ' . $childBind['status']);
    $childOrder = http('POST', $base . '/api/v1/user/order/save', ['plan_id' => 1, 'period' => 'monthly'], ['authorization' => $childToken]);
    check('子账号不能下单(403)', $childOrder['status'] === 403, 'HTTP ' . $childOrder['status']);
    $childInvite = http('GET', $base . '/api/v1/user/invite/fetch', null, ['authorization' => $childToken]);
    check('子账号不能访问邀请(403)', $childInvite['status'] === 403, 'HTTP ' . $childInvite['status']);
    $childInfo = http('GET', $base . '/api/v1/user/info', null, ['authorization' => $childToken]);
    check('子账号仍可查看个人信息', $childInfo['status'] === 200, 'HTTP ' . $childInfo['status']);
} else {
    check('子账号登录（前置）', false, '未取得子账号 token');
}

echo "\n===== 12. 普通用户无回归 =====\n";
$normalLogin = http('POST', $base . '/api/v1/passport/auth/login', ['email' => $normalEmail, 'password' => $password], [], true);
check('普通用户登录成功', $normalLogin['status'] === 200, 'HTTP ' . $normalLogin['status']);
$normalToken = $normalLogin['json']['data']['auth_data'] ?? '';
$normalAuth = ['authorization' => $normalToken];
$nList = http('GET', $base . '/api/v1/user/sub-account/list', null, $normalAuth);
check('普通用户可访问子账号列表', $nList['status'] === 200, 'HTTP ' . $nList['status']);
$nInvite = http('GET', $base . '/api/v1/user/invite/fetch', null, $normalAuth);
check('普通用户邀请接口未被拦截(非 403)', $nInvite['status'] !== 403, 'HTTP ' . $nInvite['status']);
$nPlan = http('GET', $base . '/api/v1/user/plan/fetch', null, $normalAuth);
check('普通用户套餐接口正常', $nPlan['status'] === 200, 'HTTP ' . $nPlan['status']);

echo "\n===== 13. 审计 =====\n";
$auditCount = SubAccountAuditLog::where('child_user_id', $child->id)->count();
check('审计日志已写入', $auditCount >= 3, '条数=' . $auditCount);
$hasSecret = SubAccountAuditLog::where('child_user_id', $child->id)
    ->get()
    ->contains(function ($log) use ($newPassword) {
        return strpos((string)json_encode($log->metadata), $newPassword) !== false;
    });
check('审计不包含密码明文', !$hasSecret);

echo "\n===== 14. 解绑 =====\n";
$unbind = http('POST', $base . '/api/v1/user/sub-account/unbind', ['id' => $relation->id], $auth);
check('unbind 200', $unbind['status'] === 200, 'HTTP ' . $unbind['status']);
$relation->refresh();
check('关系已停用(status=0)', (int)$relation->status === 0);
check('子账号用户未被删除', User::find($child->id) !== null);
$afterUnbind = http('GET', $base . '/api/v1/user/sub-account/list', null, $auth);
check('解绑后列表为空', ($afterUnbind['json']['data']['items'] ?? null) === []);

echo "\n===== 15. 清理本次测试数据 =====\n";
SubAccountAuditLog::whereIn('parent_user_id', $createdUserIds)->orWhereIn('child_user_id', $createdUserIds)->delete();
SubAccountRelation::whereIn('parent_user_id', $createdUserIds)->orWhereIn('child_user_id', $createdUserIds)->delete();
User::whereIn('id', $createdUserIds)->delete();
$leftUsers = User::whereIn('id', $createdUserIds)->count();
$leftRelations = SubAccountRelation::whereIn('parent_user_id', $createdUserIds)->count();
check('测试用户已清理', $leftUsers === 0 && $leftRelations === 0, "users={$leftUsers} relations={$leftRelations}");

echo "\n===== 结果 =====\n";
echo "PASS={$passed} FAIL={$failed}\n";
echo $failed === 0 ? "ACCEPTANCE_OK\n" : "ACCEPTANCE_FAILED\n";
exit($failed === 0 ? 0 : 1);
