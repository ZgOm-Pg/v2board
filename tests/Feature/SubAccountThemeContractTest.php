<?php

namespace Tests\Feature;

use App\Models\SubAccountAuditLog;
use App\Models\SubAccountRelation;
use App\Models\User;
use App\Services\SubAccountService;
use Illuminate\Http\Request;

/**
 * EZ-Theme 用户端契约兼容测试。
 *
 * 依据桌面主题源码（src/api/subAccount.js、src/api/user.js、
 * src/views/subAccount/SubAccountManager.vue）逐条核对，覆盖：
 *   - 8 个 fetch 端点使用 application/x-www-form-urlencoded，2 个走 JSON body；
 *   - list 的 data.enabled 必须是 JSON 布尔（只有严格 false 才代表未启用）；
 *   - items 必须包含 id / child_user_id / email / remark /
 *     traffic_limit_gb / used_traffic_text / subscribe_url；
 *   - bind 只提交 email / email_code / traffic_limit_gb / remark；
 *   - update 同时提交 traffic_limit_gb 与 remark（备注为空串表示清空）；
 *   - change-password 提交 id + child_user_id + new_password + password；
 *   - subscribe 用关系 id 请求；
 *   - reset-subscribe 返回 data.subscribe_url。
 */
class SubAccountThemeContractTest extends SubAccountTestCase
{
    protected function service(): SubAccountService
    {
        return new SubAccountService();
    }

    public function testListReturnsThemeCompatibleShape()
    {
        $this->enableSubAccount(5);
        $parent = $this->makeParent();
        $child = $this->makeUser(['email' => 'theme-child@example.com', 'group_id' => 1]);
        $this->seedChildTraffic($child, 1024, 2048);
        $relation = $this->makeRelation($parent, $child, [
            'traffic_limit' => 1073741824,
            'remark' => 'theme-remark',
        ]);

        $response = $this->withHeaders(['authorization' => $this->authDataFor($parent)])
            ->getJson('/api/v1/user/sub-account/list');

        $response->assertStatus(200);
        // 只有严格 false 才代表未启用
        $response->assertJsonPath('data.enabled', true);
        $response->assertJsonPath('data.max_count', 5);
        $response->assertJsonPath('data.created_count', 1);

        $item = $response->json('data.items.0');
        $this->assertSame((int)$relation->id, $item['id']);
        $this->assertSame((int)$child->id, $item['child_user_id']);
        $this->assertSame('theme-child@example.com', $item['email']);
        $this->assertSame('theme-remark', $item['remark']);
        $this->assertEquals(1, $item['traffic_limit_gb']);
        $this->assertSame(3072, $item['used_traffic']);
        $this->assertIsString($item['used_traffic_text']);
        $this->assertNotSame('', $item['used_traffic_text']);
        $this->assertStringContainsString('/api/v1/client/subscribe?token=', $item['subscribe_url']);
    }

    public function testListReturnsBooleanFalseWhenDisabled()
    {
        $parent = $this->makeParent();
        $this->disableSubAccount();

        $response = $this->withHeaders(['authorization' => $this->authDataFor($parent)])
            ->getJson('/api/v1/user/sub-account/list');

        $response->assertStatus(200);
        $response->assertJsonPath('data.enabled', false);
        $response->assertJsonPath('data.items', []);
    }

    public function testBindAcceptsEmailCodeAndTrafficLimitGb()
    {
        $this->enableSubAccount(5);
        $parent = $this->makeParent();
        $email = 'theme-bind@example.com';
        $this->seedBindCode($parent, $email, '012345');

        // 主题用 form-urlencoded 提交，且 email_code 是字符串（可能带前导零）
        $response = $this->post('/api/v1/user/sub-account/bind', [
            'email' => $email,
            'email_code' => '012345',
            'traffic_limit_gb' => '2',
            'remark' => 'theme bind',
        ], ['authorization' => $this->authDataFor($parent)]);

        $response->assertStatus(200);

        $child = User::where('email', $email)->first();
        $this->assertNotNull($child, '绑定应创建子账号用户');

        $relation = SubAccountRelation::where('child_user_id', $child->id)->first();
        $this->assertNotNull($relation);
        $this->assertSame((int)$parent->id, (int)$relation->parent_user_id);
        // 2 GB -> 字节
        $this->assertSame(2 * 1073741824, (int)$relation->traffic_limit);
        $this->assertSame('theme bind', $relation->remark);
        $this->assertSame(SubAccountRelation::STATUS_ENABLED, (int)$relation->status);
    }

    public function testUpdateAcceptsTrafficLimitGbAndEmptyRemarkClearsRemark()
    {
        $this->enableSubAccount(5);
        $parent = $this->makeParent();
        $child = $this->makeUser(['email' => 'theme-update@example.com']);
        $relation = $this->makeRelation($parent, $child, ['traffic_limit' => 1048576, 'remark' => 'old']);

        $response = $this->post('/api/v1/user/sub-account/update', [
            'id' => $relation->id,
            'traffic_limit_gb' => '3',
            'remark' => '',
        ], ['authorization' => $this->authDataFor($parent)]);

        $response->assertStatus(200);

        $relation = $relation->fresh();
        $this->assertSame(3 * 1073741824, (int)$relation->traffic_limit);
        // 空串 = 清空备注（主题保存备注时会同时回传配额，不能因此把配额清零）
        $this->assertNull($relation->remark);
    }

    public function testChangePasswordAcceptsNewPasswordAndChildUserIdOnly()
    {
        $this->enableSubAccount(5);
        $parent = $this->makeParent();
        $child = $this->makeUser(['email' => 'theme-password@example.com']);
        $relation = $this->makeRelation($parent, $child);

        // 主题发 JSON，且同时带 new_password 与 password；这里只给 new_password + child_user_id
        $response = $this->postJson('/api/v1/user/sub-account/change-password', [
            'child_user_id' => $child->id,
            'email' => $child->email,
            'new_password' => 'newpassword123',
        ], ['authorization' => $this->authDataFor($parent)]);

        $response->assertStatus(200);

        $child = $child->fresh();
        $this->assertTrue(password_verify('newpassword123', $child->password));

        $log = SubAccountAuditLog::where('action', SubAccountService::ACTION_CHANGE_PASSWORD)
            ->orderBy('id', 'desc')
            ->first();
        $this->assertNotNull($log);
        $this->assertSame((int)$parent->id, (int)$log->actor_user_id);
        $this->assertSame((int)$relation->id, (int)$log->relation_id);
        $this->assertStringNotContainsString('newpassword123', json_encode($log->metadata));
    }

    public function testChangePasswordAcceptsIdAndPasswordAlias()
    {
        $this->enableSubAccount(5);
        $parent = $this->makeParent();
        $child = $this->makeUser(['email' => 'theme-password2@example.com']);
        $relation = $this->makeRelation($parent, $child);

        $response = $this->postJson('/api/v1/user/sub-account/change-password', [
            'id' => $relation->id,
            'child_user_id' => $child->id,
            'new_password' => 'anotherpass123',
            'password' => 'anotherpass123',
        ], ['authorization' => $this->authDataFor($parent)]);

        $response->assertStatus(200);
        $this->assertTrue(password_verify('anotherpass123', $child->fresh()->password));
    }

    public function testSubscribeResolvesByRelationId()
    {
        $this->enableSubAccount(5);
        $parent = $this->makeParent();
        $child = $this->makeUser(['email' => 'theme-sub@example.com']);
        $relation = $this->makeRelation($parent, $child);

        $response = $this->withHeaders(['authorization' => $this->authDataFor($parent)])
            ->getJson('/api/v1/user/sub-account/subscribe?id=' . $relation->id);

        $response->assertStatus(200);
        $url = $response->json('data.subscribe_url');
        $this->assertIsString($url);
        $this->assertStringContainsString($child->token, $url);
        // 兼容 data.url
        $this->assertSame($url, $response->json('data.url'));
    }

    public function testResetSubscribeReturnsSubscribeUrlInData()
    {
        $this->enableSubAccount(5);
        $parent = $this->makeParent();
        $child = $this->makeUser(['email' => 'theme-reset@example.com']);
        $relation = $this->makeRelation($parent, $child);
        $oldToken = $child->token;

        // 主题对 reset-subscribe 使用 JSON body
        $response = $this->postJson('/api/v1/user/sub-account/reset-subscribe', [
            'id' => $relation->id,
        ], ['authorization' => $this->authDataFor($parent)]);

        $response->assertStatus(200);
        $newToken = $child->fresh()->token;
        $this->assertNotSame($oldToken, $newToken);
        $this->assertSame(
            \App\Utils\Helper::getSubscribeUrl($newToken),
            $response->json('data.subscribe_url')
        );
    }

    public function testUnbindByRelationIdKeepsUserAndAudit()
    {
        $this->enableSubAccount(5);
        $parent = $this->makeParent();
        $child = $this->makeUser(['email' => 'theme-unbind@example.com']);
        $relation = $this->makeRelation($parent, $child, ['created_by_parent' => 1]);

        $response = $this->post('/api/v1/user/sub-account/unbind', [
            'id' => $relation->id,
        ], ['authorization' => $this->authDataFor($parent)]);

        $response->assertStatus(200);
        $this->assertSame(SubAccountRelation::STATUS_DISABLED, (int)$relation->fresh()->status);
        $this->assertNotNull(User::find($child->id), '解绑不得删除子账号用户');
        $this->assertNotNull(
            SubAccountAuditLog::where('action', SubAccountService::ACTION_UNBIND)->first(),
            '解绑必须写入审计'
        );
    }

    public function testAdminDetailEndpointReturnsParentChildAndHealth()
    {
        $this->enableSubAccount(5);
        $parent = $this->makeParent();
        $child = $this->makeUser(['email' => 'theme-detail@example.com']);
        $relation = $this->makeRelation($parent, $child, ['traffic_limit' => 2147483648]);

        $controller = new \App\Http\Controllers\V1\Admin\SubAccountController();
        $request = Request::create('/testsecure/sub-account/detail', 'GET', ['id' => $relation->id]);
        $payload = json_decode($controller->detail($request)->getContent(), true);

        $data = $payload['data'];
        $this->assertSame((int)$relation->id, $data['id']);
        $this->assertSame($parent->email, $data['parent']['email']);
        $this->assertSame($child->email, $data['child']['email']);
        $this->assertEquals(2, $data['traffic_limit_gb']);
        $this->assertTrue($data['health']['healthy']);
        $this->assertTrue($data['health']['can_connect']);
        $this->assertSame((int)$parent->group_id, $data['health']['effective_group_id']);
        $this->assertIsArray($data['audit']);
    }

    public function testAdminListResolvesEmailKeywordFilters()
    {
        $this->enableSubAccount(5);
        $parent = $this->makeParent(['email' => 'keyword-parent@example.com']);
        $child = $this->makeUser(['email' => 'keyword-child@example.com']);
        $this->makeRelation($parent, $child);

        $controller = new \App\Http\Controllers\V1\Admin\SubAccountController();

        $byParentEmail = json_decode(
            $controller->fetch(Request::create('/testsecure/sub-account/fetch', 'GET', [
                'parent_user_id' => 'keyword-parent@example.com',
            ]))->getContent(),
            true
        );
        $this->assertSame(1, $byParentEmail['data']['total']);

        $byChildEmail = json_decode(
            $controller->fetch(Request::create('/testsecure/sub-account/fetch', 'GET', [
                'child_user_id' => 'keyword-child@example.com',
            ]))->getContent(),
            true
        );
        $this->assertSame(1, $byChildEmail['data']['total']);

        $noMatch = json_decode(
            $controller->fetch(Request::create('/testsecure/sub-account/fetch', 'GET', [
                'child_user_id' => 'nobody@example.com',
            ]))->getContent(),
            true
        );
        $this->assertSame(0, $noMatch['data']['total']);
    }

    public function testSubscriptionUsesParentEntitlement()
    {
        $this->enableSubAccount(5);
        $parent = $this->makeParent(['group_id' => 7, 'expired_at' => time() + 86400 * 10]);
        $child = $this->makeUser(['email' => 'theme-entitlement@example.com', 'group_id' => null]);
        $this->makeRelation($parent, $child);

        $entitlement = $this->service()->resolveEntitlement($child);
        $this->assertTrue($entitlement->isSubAccount());
        $this->assertSame(7, (int)$entitlement->getEffectiveGroupId());
        $this->assertSame((int)$parent->expired_at, (int)$entitlement->getEffectiveExpiredAt());
        $this->assertSame(100, (int)$entitlement->getEffectiveSpeedLimit());
        $this->assertSame(3, (int)$entitlement->getEffectiveDeviceLimit());
        $this->assertTrue($entitlement->isSubscriptionAvailable());
        $this->assertSame((int)$parent->plan_id, (int)$entitlement->getEffectivePlanId());

        // 订阅渲染视图: 凭据仍为子账号自身，权益来自父账号
        $renderUser = $this->service()->effectiveSubscriptionUser($child);
        $this->assertSame((int)$child->id, (int)$renderUser->id);
        $this->assertSame($child->token, $renderUser->token);
        $this->assertSame(7, (int)$renderUser->group_id);
        $this->assertSame((int)$parent->expired_at, (int)$renderUser->expired_at);
        $this->assertSame($child->uuid, User::find($child->id)->uuid, '原模型不得被污染');
    }

    public function testSubscriptionRenderingClonesWithoutTouchingDatabase()
    {
        $this->enableSubAccount(5);
        $parent = $this->makeParent(['group_id' => 4]);
        $child = $this->makeUser(['email' => 'theme-clone@example.com', 'group_id' => null, 'transfer_enable' => 0]);
        $this->makeRelation($parent, $child, ['traffic_limit' => 1073741824]);

        $before = User::find($child->id)->toArray();
        $this->service()->effectiveSubscriptionUser($child);
        $after = User::find($child->id)->toArray();

        $this->assertSame($before, $after, '有效订阅视图不得写库');
    }
}
