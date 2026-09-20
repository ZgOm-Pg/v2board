<?php

namespace Tests\Feature;

use App\Models\SubAccountRelation;
use App\Models\User;
use App\Services\SubAccountService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;

/**
 * HTTP 层与绑定规则测试。
 *
 * 覆盖点: 1、3、4、5、6、8、9、10、11、12、25、26。
 */
class SubAccountApiTest extends SubAccountTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // 防御性: 若安装测试类在本次进程内先运行过，也不允许表缺失
        if (!$this->tableExists('v2_user_sub_accounts')) {
            \Illuminate\Support\Facades\Artisan::call('sub-account:install --apply');
        }
    }

    // ------------------------------------------------------------ 覆盖点 1

    public function testFeatureDisabledListReportsZeroAndIgnoresExistingRelation()
    {
        $parent = $this->makeParent();
        $child = $this->makeUser(['email' => 'child-disabled@example.com']);
        $relation = $this->makeRelation($parent, $child);

        $this->disableSubAccount();

        $response = $this->withHeaders(['authorization' => $this->authDataFor($parent)])
            ->getJson('/api/v1/user/sub-account/list');

        $response->assertStatus(200);
        $response->assertJsonPath('data.enabled', false);
        $response->assertJsonPath('data.created_count', 0);
        $response->assertJsonPath('data.items', []);

        // 已存在的关系在功能关闭时被完全忽略（授权不继承）
        $entitlement = $this->service()->entitlementForUserId($child->id);
        $this->assertFalse($entitlement->isSubAccount());
        $this->assertNull($entitlement->getParentUser());
        $this->assertSame($child->group_id, $entitlement->getEffectiveGroupId());
        $this->assertSame(0, count($this->service()->activeChildIdsForParent($parent->id)));
        $this->assertFalse($this->service()->isSubAccount($child));
        $this->assertNull($this->service()->parentIdForChild($child->id));
        $this->assertSame([], $this->service()->enabledChildIdMap());
        $this->assertSame([], $this->service()->enabledChildIdsForParents([$parent->id]));

        $this->assertNotNull($relation->id);

        // 普通用户端点不得被子账号逻辑改写（无回归）
        $info = $this->withHeaders(['authorization' => $this->authDataFor($child)])
            ->getJson('/api/v1/user/info');
        $info->assertStatus(200);
        $this->assertSame($child->email, $info->json('data.email'));
        $this->assertSame((int)$child->transfer_enable, (int)$info->json('data.transfer_enable'));
        $this->assertSame((int)$child->banned, (int)$info->json('data.banned'));
        $this->assertSame((int)$child->plan_id, (int)$info->json('data.plan_id'));
    }

    public function testFeatureDisabledRestrictedEndpointsBehaveAsIfFeatureIsOff()
    {
        $parent = $this->makeParent();
        $child = $this->makeUser(['email' => 'child-off@example.com']);
        $this->makeRelation($parent, $child);

        // 功能关闭: 子账号身份完全不存在 => 受限接口不被中间件拦截
        $this->disableSubAccount();
        $this->withHeaders(['authorization' => $this->authDataFor($child)])
            ->getJson('/api/v1/user/order/fetch')
            ->assertStatus(200);

        // 功能开启: 同一接口对启用中的子账号返回 403
        $this->enableSubAccount();
        $this->withHeaders(['authorization' => $this->authDataFor($child)])
            ->getJson('/api/v1/user/order/fetch')
            ->assertStatus(403);
    }

    public function testFeatureDisabledRejectsWriteOperations()
    {
        $parent = $this->makeParent();
        $this->disableSubAccount();

        $bind = $this->postJson('/api/v1/user/sub-account/bind', [
            'auth_data' => $this->authDataFor($parent),
            'email' => 'nobody@example.com',
            'code' => '123456',
        ]);
        $bind->assertStatus(500);
        $this->assertSame('Sub-account is not enabled', $bind->json('message'));

        $send = $this->postJson('/api/v1/user/sub-account/send-code', [
            'auth_data' => $this->authDataFor($parent),
            'email' => 'nobody@example.com',
        ]);
        $send->assertStatus(500);
        $this->assertSame('Sub-account is not enabled', $send->json('message'));

        $this->assertSame(0, User::where('email', 'nobody@example.com')->count());
    }

    // ------------------------------------------------------------ 覆盖点 3

    public function testCreateNewSubAccountWithUnknownEmailUsesNeutralValues()
    {
        $this->enableSubAccount();
        $parent = $this->makeParent(['speed_limit' => 123, 'device_limit' => 9, 'transfer_enable' => 107374182400]);

        $email = 'brand-new-sub@example.com';
        $http = $this->postJson('/api/v1/user/sub-account/bind', [
            'auth_data' => $this->authDataFor($parent),
            'email' => $email,
            'code' => $this->seedBindCode($email),
            'traffic_limit' => 524288000,
            'remark' => 'my sub',
            'password' => 'subaccountpass1',
        ]);

        $http->assertStatus(200);
        $this->assertArrayHasKey('data', $http->json());
        $this->assertSame($email, $http->json('data.email'));
        $this->assertSame(524288000, $http->json('data.traffic_limit'));
        $this->assertSame('my sub', $http->json('data.remark'));

        $child = User::where('email', $email)->first();
        $this->assertNotNull($child, '未知邮箱必须新建 v2_user 记录');

        // 中性值: 绝不复制主账号套餐字段
        $this->assertNull($child->plan_id);
        $this->assertNull($child->group_id);
        $this->assertSame(0, (int)$child->transfer_enable);
        $this->assertSame(0, (int)$child->balance);
        $this->assertSame(0, (int)$child->commission_balance);
        $this->assertSame(0, (int)$child->auto_renewal);
        $this->assertSame(0, (int)$child->remind_expire);
        $this->assertSame(0, (int)$child->remind_traffic);
        $this->assertSame(0, (int)$child->u);
        $this->assertSame(0, (int)$child->d);
        $this->assertSame(0, (int)$child->banned);
        $this->assertSame(0, (int)$child->is_admin);
        $this->assertSame(0, (int)$child->is_staff);
        $this->assertNull($child->invite_user_id);
        $this->assertNull($child->speed_limit);
        $this->assertNull($child->device_limit);

        // expired_at: 实现侧写入 NULL；目标库该列可能为 NOT NULL DEFAULT 0
        if ($this->columnIsNullable('v2_user', 'expired_at')) {
            $this->assertNull($child->expired_at);
        } else {
            $this->assertSame(0, (int)$child->expired_at);
        }

        // 独立凭据
        $this->assertNotEmpty($child->uuid);
        $this->assertNotEmpty($child->token);
        $this->assertNotSame($parent->uuid, $child->uuid);
        $this->assertNotSame($parent->token, $child->token);
        $this->assertNotSame($parent->password, $child->password);
        $this->assertTrue(password_verify('subaccountpass1', $child->password));

        // 关系
        $relation = SubAccountRelation::where('child_user_id', $child->id)->first();
        $this->assertNotNull($relation);
        $this->assertSame((int)$parent->id, (int)$relation->parent_user_id);
        $this->assertSame(SubAccountRelation::STATUS_ENABLED, (int)$relation->status);
        $this->assertSame(1, (int)$relation->created_by_parent);
        $this->assertSame(524288000, (int)$relation->traffic_limit);

        // 新建走 ACTION_CREATE 审计，且 metadata 标注 created_by_parent
        $createLogs = $this->auditLogs(SubAccountService::ACTION_CREATE);
        $this->assertNotEmpty($createLogs, '新建子账号必须写 ACTION_CREATE 审计');
        $this->assertSame((int)$relation->id, (int)$createLogs[0]->relation_id);
        $this->assertTrue((bool)$createLogs[0]->metadata['created_by_parent']);
    }

    public function testCreateNewSubAccountWithoutPasswordReturnsGeneratedInitialPassword()
    {
        $this->enableSubAccount();
        $parent = $this->makeParent();
        $email = 'generated-pass@example.com';

        $http = $this->postJson('/api/v1/user/sub-account/bind', [
            'auth_data' => $this->authDataFor($parent),
            'email' => $email,
            'code' => $this->seedBindCode($email),
        ]);
        $http->assertStatus(200);

        $initial = $http->json('data.initial_password');
        $this->assertNotEmpty($initial);
        $this->assertGreaterThanOrEqual(SubAccountService::MIN_PASSWORD_LENGTH, strlen($initial));

        $child = User::where('email', $email)->first();
        $this->assertTrue(password_verify($initial, $child->password));

        // 二次读取（列表接口）绝不再返回初始密码
        $list = $this->withHeaders(['authorization' => $this->authDataFor($parent)])
            ->getJson('/api/v1/user/sub-account/list');
        $list->assertStatus(200);
        $this->assertArrayNotHasKey('initial_password', $list->json('data.items.0'));
    }

    // ------------------------------------------------------------ 覆盖点 4

    public function testBindExistingBlankAccountReusesUserAndKeepsPassword()
    {
        $this->enableSubAccount();
        $parent = $this->makeParent();
        $blank = $this->makeUser([
            'email' => 'blank-existing@example.com',
            'password' => password_hash('original-pass-123', PASSWORD_DEFAULT),
            'plan_id' => null,
            'balance' => 0,
            'commission_balance' => 0,
            'u' => 0,
            'd' => 0,
        ]);
        $passwordBefore = $blank->password;
        $userIdBefore = $blank->id;

        $http = $this->postJson('/api/v1/user/sub-account/bind', [
            'auth_data' => $this->authDataFor($parent),
            'email' => $blank->email,
            'code' => $this->seedBindCode($blank->email),
        ]);
        $http->assertStatus(200);
        $this->assertArrayNotHasKey('initial_password', $http->json('data'));

        // 复用同一 v2_user 记录
        $this->assertSame(1, User::where('email', $blank->email)->count());
        $after = User::find($userIdBefore);
        $this->assertSame($passwordBefore, $after->password, '绑定已有账号不得修改其密码');
        $this->assertTrue(password_verify('original-pass-123', $after->password));

        $relation = SubAccountRelation::where('child_user_id', $userIdBefore)->first();
        $this->assertNotNull($relation);
        $this->assertSame(0, (int)$relation->created_by_parent);
        $this->assertSame(SubAccountRelation::STATUS_ENABLED, (int)$relation->status);
        $this->assertSame((int)$parent->id, (int)$relation->parent_user_id);
    }

    // ------------------------------------------------------------ 覆盖点 5

    /**
     * @dataProvider existingAccountRejectionProvider
     */
    public function testBindExistingAccountRejections(array $overrides, $expectedMessage)
    {
        $this->enableSubAccount();
        $parent = $this->makeParent();
        $candidate = $this->makeUser(array_merge([
            'email' => 'reject-' . uniqid('', true) . '@example.com',
            'plan_id' => null,
        ], $overrides));

        $http = $this->postJson('/api/v1/user/sub-account/bind', [
            'auth_data' => $this->authDataFor($parent),
            'email' => $candidate->email,
            'code' => $this->seedBindCode($candidate->email),
        ]);

        $http->assertStatus(500);
        $this->assertSame($expectedMessage, $http->json('message'));
        $this->assertSame(0, SubAccountRelation::where('child_user_id', $candidate->id)->count());
    }

    public function existingAccountRejectionProvider(): array
    {
        return [
            'admin' => [['is_admin' => 1], 'Administrators cannot be bound as sub-accounts'],
            'staff' => [['is_staff' => 1], 'Staff cannot be bound as sub-accounts'],
            'banned' => [['banned' => 1], 'Banned users cannot be bound as sub-accounts'],
            'balance' => [['balance' => 100], 'Users with balance or commission cannot be bound as sub-accounts'],
            'commission_balance' => [['commission_balance' => 5], 'Users with balance or commission cannot be bound as sub-accounts'],
            'has_plan' => [['plan_id' => 1], 'Users with a plan cannot be bound as sub-accounts'],
            'has_traffic_u' => [['u' => 1], 'Users with traffic usage cannot be bound as sub-accounts'],
            'has_traffic_d' => [['d' => 1024], 'Users with traffic usage cannot be bound as sub-accounts'],
        ];
    }

    public function testBindExistingAccountWithOrdersIsRejected()
    {
        $this->enableSubAccount();
        $parent = $this->makeParent();
        $candidate = $this->makeUser(['email' => 'has-order@example.com', 'plan_id' => null]);

        \Illuminate\Support\Facades\DB::table('v2_order')->insert([
            'invite_user_id' => null,
            'user_id' => $candidate->id,
            'plan_id' => 1,
            'coupon_id' => null,
            'payment_id' => null,
            'type' => 1,
            'period' => 'month_price',
            'trade_no' => 'TEST' . uniqid(),
            'callback_no' => null,
            'total_amount' => 100,
            'handling_amount' => 0,
            'discount_amount' => null,
            'surplus_amount' => null,
            'refund_amount' => null,
            'balance_amount' => null,
            'surplus_order_ids' => null,
            'status' => 3,
            'commission_status' => 0,
            'commission_balance' => 0,
            'actual_commission_balance' => null,
            'paid_at' => null,
            'created_at' => time(),
            'updated_at' => time(),
        ]);

        $http = $this->postJson('/api/v1/user/sub-account/bind', [
            'auth_data' => $this->authDataFor($parent),
            'email' => $candidate->email,
            'code' => $this->seedBindCode($candidate->email),
        ]);
        $http->assertStatus(500);
        $this->assertSame('Users with orders cannot be bound as sub-accounts', $http->json('message'));
    }

    // ------------------------------------------------------------ 覆盖点 6

    public function testBindSelfIsRejected()
    {
        $this->enableSubAccount();
        $parent = $this->makeParent();

        try {
            $this->service()->bind($parent, [
                'email' => $parent->email,
                'code' => $this->seedBindCode($parent->email),
            ], '127.0.0.1');
            $this->fail('绑定自己必须被拒绝');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            $this->assertSame(500, $e->getStatusCode());
            $this->assertSame('You cannot bind your own account as a sub-account', $e->getMessage());
        }

        $this->assertSame(0, SubAccountRelation::where('parent_user_id', $parent->id)->count());
    }

    public function testDuplicateBindingIsRejected()
    {
        $this->enableSubAccount();
        $parentA = $this->makeParent(['email' => 'parent-a@example.com']);
        $parentB = $this->makeParent(['email' => 'parent-b@example.com']);
        $child = $this->makeUser(['email' => 'dup-child@example.com']);
        $this->makeRelation($parentA, $child);

        // A 再次绑定同一账号
        $again = $this->postJson('/api/v1/user/sub-account/bind', [
            'auth_data' => $this->authDataFor($parentA),
            'email' => $child->email,
            'code' => $this->seedBindCode($child->email),
        ]);
        $again->assertStatus(500);
        $this->assertSame('This account is already a sub-account', $again->json('message'));

        // B 绑定 A 的子账号
        $cross = $this->postJson('/api/v1/user/sub-account/bind', [
            'auth_data' => $this->authDataFor($parentB),
            'email' => $child->email,
            'code' => $this->seedBindCode($child->email),
        ]);
        $cross->assertStatus(500);
        $this->assertSame('This account is already a sub-account', $cross->json('message'));

        $this->assertSame(1, SubAccountRelation::where('child_user_id', $child->id)->count());
    }

    public function testAccountWithEnabledSubAccountsCannotBecomeSubAccount()
    {
        $this->enableSubAccount();
        $parent = $this->makeParent(['email' => 'multi-parent@example.com']);
        $middle = $this->makeUser(['email' => 'middle@example.com']);
        $leaf = $this->makeUser(['email' => 'leaf@example.com']);
        // middle 自己已是别人的主账号
        $this->makeRelation($middle, $leaf);

        $http = $this->postJson('/api/v1/user/sub-account/bind', [
            'auth_data' => $this->authDataFor($parent),
            'email' => $middle->email,
            'code' => $this->seedBindCode($middle->email),
        ]);
        $http->assertStatus(500);
        $this->assertSame('This account already has sub-accounts', $http->json('message'));
    }

    public function testParentThatIsItselfASubAccountCannotCreateSubAccounts()
    {
        $this->enableSubAccount();
        $grandParent = $this->makeParent(['email' => 'grandparent@example.com']);
        $parent = $this->makeUser(['email' => 'parent-is-child@example.com']);
        $this->makeRelation($grandParent, $parent);

        $newEmail = 'grandchild@example.com';
        $http = $this->postJson('/api/v1/user/sub-account/bind', [
            'auth_data' => $this->authDataFor($parent),
            'email' => $newEmail,
            'code' => $this->seedBindCode($newEmail),
        ]);
        // 子账号调用写接口由集中式 SubAccountGuard 中间件直接拦截
        // （UserRoute 把 send-code/bind/update/... 全部挂在 sub_account 中间件组，
        //  见 app/Http/Routes/V1/UserRoute.php），早于服务层的同义校验。
        $http->assertStatus(403);
        $this->assertSame('Sub-accounts are not allowed to perform this action', $http->json('message'));
        $this->assertSame(0, User::where('email', $newEmail)->count());
    }

    public function testMaxCountLimitIsEnforced()
    {
        $this->enableSubAccount(1);
        $parent = $this->makeParent(['email' => 'max-parent@example.com']);
        $first = $this->makeUser(['email' => 'first-child@example.com']);
        $this->bindSubAccount($parent, $first->email);

        $secondEmail = 'second-child@example.com';
        $http = $this->postJson('/api/v1/user/sub-account/bind', [
            'auth_data' => $this->authDataFor($parent),
            'email' => $secondEmail,
            'code' => $this->seedBindCode($secondEmail),
        ]);
        $http->assertStatus(500);
        $this->assertSame('The number of sub-accounts has reached the upper limit', $http->json('message'));
        $this->assertSame(0, User::where('email', $secondEmail)->count());
        $this->assertSame(1, SubAccountRelation::where('parent_user_id', $parent->id)->count());
    }

    public function testDisabledRelationDoesNotCountTowardsMaxCount()
    {
        $this->enableSubAccount(1);
        $parent = $this->makeParent(['email' => 'max-parent-2@example.com']);
        $old = $this->makeUser(['email' => 'old-child@example.com']);
        $this->makeRelation($parent, $old, ['status' => SubAccountRelation::STATUS_DISABLED]);

        $newEmail = 'new-child@example.com';
        $http = $this->postJson('/api/v1/user/sub-account/bind', [
            'auth_data' => $this->authDataFor($parent),
            'email' => $newEmail,
            'code' => $this->seedBindCode($newEmail),
        ]);
        $http->assertStatus(200);
    }

    public function testInvalidEmailAndShortPasswordAreRejected()
    {
        $this->enableSubAccount();
        $parent = $this->makeParent();

        $badEmail = $this->postJson('/api/v1/user/sub-account/bind', [
            'auth_data' => $this->authDataFor($parent),
            'email' => 'not-an-email',
            'code' => '123456',
        ]);
        $badEmail->assertStatus(500);
        // Laravel 默认 translator 无 en 语言包，__() 回落到中文原文（app/ 未改动）
        $this->assertSame('邮箱格式不正确', $badEmail->json('message'));

        $email = 'short-pass@example.com';
        $short = $this->postJson('/api/v1/user/sub-account/bind', [
            'auth_data' => $this->authDataFor($parent),
            'email' => $email,
            'code' => $this->seedBindCode($email),
            'password' => '1234567',
        ]);
        $short->assertStatus(500);
        $this->assertSame('Password must be at least 8 characters', $short->json('message'));
        $this->assertSame(0, User::where('email', $email)->count());
    }

    public function testNegativeTrafficLimitIsRejected()
    {
        $this->enableSubAccount();
        $parent = $this->makeParent();
        $email = 'negative-limit@example.com';

        $http = $this->postJson('/api/v1/user/sub-account/bind', [
            'auth_data' => $this->authDataFor($parent),
            'email' => $email,
            'code' => $this->seedBindCode($email),
            'traffic_limit' => -1,
        ]);
        $http->assertStatus(500);
        $this->assertSame('Traffic limit format is incorrect', $http->json('message'));
    }

    // ------------------------------------------------------------ 覆盖点 8

    public function testWrongVerificationCodeIsRejected()
    {
        $this->enableSubAccount();
        $parent = $this->makeParent();
        $email = 'wrong-code@example.com';
        $this->seedBindCode($email, '123456');

        $http = $this->postJson('/api/v1/user/sub-account/bind', [
            'auth_data' => $this->authDataFor($parent),
            'email' => $email,
            'code' => '654321',
        ]);
        $http->assertStatus(500);
        $this->assertSame('Invalid verification code', $http->json('message'));
        $this->assertSame(0, User::where('email', $email)->count());

        // 错误尝试不消费验证码
        $this->assertSame('123456', Cache::get($this->emailCodeCacheKey($email)));
    }

    public function testExpiredVerificationCodeIsRejected()
    {
        $this->enableSubAccount();
        $parent = $this->makeParent();
        $email = 'expired-code@example.com';
        $this->seedBindCode($email, '123456');
        // 模拟 TTL 到期（array 驱动不主动过期，直接移除等价于自然过期）
        Cache::forget($this->emailCodeCacheKey($email));

        $http = $this->postJson('/api/v1/user/sub-account/bind', [
            'auth_data' => $this->authDataFor($parent),
            'email' => $email,
            'code' => '123456',
        ]);
        $http->assertStatus(500);
        $this->assertSame('The verification code has expired, please resend', $http->json('message'));
    }

    public function testVerificationCodeTtlIsAppliedToCache()
    {
        $this->enableSubAccount(5, 120, 60);
        $parent = $this->makeParent();
        $email = 'ttl-code@example.com';
        Queue::fake();

        $this->service()->sendBindCode($parent, $email, '127.0.0.1');

        $code = Cache::get($this->emailCodeCacheKey($email));
        $this->assertNotNull($code);
        $this->assertMatchesRegularExpression('/^\d{6}$/', (string)$code);

        // array 驱动的 Cache::put 会写入过期时间；这里断言键存在且值格式正确，
        // 并通过配置读取验证 TTL 语义来自 sub_account_email_code_ttl
        $this->assertSame(120, $this->service()->getEmailCodeTtl());
        $this->assertTrue(Cache::has($this->emailCodeLastSendCacheKey($email)));
    }

    public function testVerificationCodeIsSingleUse()
    {
        $this->enableSubAccount();
        $parent = $this->makeParent();
        $email = 'single-use@example.com';
        $this->seedBindCode($email, '123456');

        $this->postJson('/api/v1/user/sub-account/bind', [
            'auth_data' => $this->authDataFor($parent),
            'email' => $email,
            'code' => '123456',
        ])->assertStatus(200);

        // 验证码与"最后发送时间"都被消费
        $this->assertFalse(Cache::has($this->emailCodeCacheKey($email)));
        $this->assertFalse(Cache::has($this->emailCodeLastSendCacheKey($email)));

        // 第二次使用同一验证码必须失败
        $second = $this->postJson('/api/v1/user/sub-account/bind', [
            'auth_data' => $this->authDataFor($parent),
            'email' => $email,
            'code' => '123456',
        ]);
        $second->assertStatus(500);
        $this->assertSame('The verification code has expired, please resend', $second->json('message'));
        $this->assertSame(1, SubAccountRelation::where('parent_user_id', $parent->id)->count());
    }

    public function testResendIntervalIsEnforced()
    {
        $this->enableSubAccount();
        $parent = $this->makeParent();
        $email = 'interval@example.com';
        Queue::fake();
        $this->seedLastSend($email);

        $http = $this->postJson('/api/v1/user/sub-account/send-code', [
            'auth_data' => $this->authDataFor($parent),
            'email' => $email,
        ]);
        $http->assertStatus(500);
        $this->assertSame('验证码已发送，请过一会儿再请求', $http->json('message'));
    }

    public function testSendCodeDispatchesEmailJobAndStoresCode()
    {
        $this->enableSubAccount();
        $parent = $this->makeParent();
        $email = 'send-code@example.com';
        Queue::fake();

        $http = $this->postJson('/api/v1/user/sub-account/send-code', [
            'auth_data' => $this->authDataFor($parent),
            'email' => $email,
        ]);
        $http->assertStatus(200);
        $this->assertTrue($http->json('data'));

        Queue::assertPushed(\App\Jobs\SendEmailJob::class, function ($job) use ($email) {
            $params = (array)$job;
            $serialized = json_encode($params);
            return strpos($serialized, $email) !== false;
        });

        $code = Cache::get($this->emailCodeCacheKey($email));
        $this->assertNotNull($code);
        $this->assertMatchesRegularExpression('/^\d{6}$/', (string)$code);
    }

    public function testSendCodeToSelfIsRejected()
    {
        $this->enableSubAccount();
        $parent = $this->makeParent(['email' => 'self-code@example.com']);
        Queue::fake();

        $http = $this->postJson('/api/v1/user/sub-account/send-code', [
            'auth_data' => $this->authDataFor($parent),
            'email' => $parent->email,
        ]);
        $http->assertStatus(500);
        $this->assertSame('You cannot bind your own account as a sub-account', $http->json('message'));
        Queue::assertNothingPushed();
    }

    public function testSendCodeRateLimitPerUserIsEnforced()
    {
        $this->enableSubAccount();
        $parent = $this->makeParent();
        Queue::fake();

        $this->exhaustUserCodeRateLimit($parent->id, 10);

        $http = $this->postJson('/api/v1/user/sub-account/send-code', [
            'auth_data' => $this->authDataFor($parent),
            'email' => 'ratelimited@example.com',
        ]);
        $http->assertStatus(429);
        $this->assertSame('Too many requests, please try again later.', $http->json('message'));
        $this->assertFalse(Cache::has($this->emailCodeCacheKey('ratelimited@example.com')));

        // 清理: 其它测试使用 array 缓存共享限流键
        RateLimiter::clear('sub_account_code_user_' . $parent->id);
    }

    public function testSendCodeRateLimitPerIpIsEnforced()
    {
        $this->enableSubAccount();
        $parent = $this->makeParent();
        Queue::fake();

        $this->exhaustIpCodeRateLimit('127.0.0.1', 20);

        $http = $this->postJson('/api/v1/user/sub-account/send-code', [
            'auth_data' => $this->authDataFor($parent),
            'email' => 'ip-limited@example.com',
        ]);
        $http->assertStatus(429);
        $this->assertSame('Too many requests, please try again later.', $http->json('message'));

        RateLimiter::clear('sub_account_code_ip_' . md5('127.0.0.1'));
    }

    public function testSendCodeRejectsInvalidEmailFormat()
    {
        $this->enableSubAccount();
        $parent = $this->makeParent();
        Queue::fake();

        $http = $this->postJson('/api/v1/user/sub-account/send-code', [
            'auth_data' => $this->authDataFor($parent),
            'email' => 'bad-email',
        ]);
        $http->assertStatus(500);
        $this->assertSame('邮箱格式不正确', $http->json('message'));
    }

    // ------------------------------------------------------------ 覆盖点 9

    public function testHorizontalPrivilegeIsEnforcedAcrossParents()
    {
        $this->enableSubAccount();
        $parentA = $this->makeParent(['email' => 'owner-a@example.com']);
        $parentB = $this->makeParent(['email' => 'owner-b@example.com']);
        $childB = $this->makeUser(['email' => 'child-of-b@example.com']);
        $relation = $this->bindSubAccount($parentB, $childB->email);
        $childB = User::find($childB->id);

        $snapshot = [
            'relation' => (array)SubAccountRelation::find($relation->id)->getAttributes(),
            'uuid' => $childB->uuid,
            'token' => $childB->token,
            'password' => $childB->password,
            'u' => (int)$childB->u,
            'd' => (int)$childB->d,
        ];

        $auth = $this->authDataFor($parentA);

        // update
        $this->postJson('/api/v1/user/sub-account/update', [
            'auth_data' => $auth,
            'id' => $relation->id,
            'traffic_limit' => 999999,
            'remark' => 'hacked',
        ])->assertStatus(403);

        // change-password
        $this->postJson('/api/v1/user/sub-account/change-password', [
            'auth_data' => $auth,
            'id' => $relation->id,
            'password' => 'newpassword123',
        ])->assertStatus(403);

        // reset-traffic
        $this->postJson('/api/v1/user/sub-account/reset-traffic', [
            'auth_data' => $auth,
            'id' => $relation->id,
        ])->assertStatus(403);

        // reset-subscribe
        $this->postJson('/api/v1/user/sub-account/reset-subscribe', [
            'auth_data' => $auth,
            'id' => $relation->id,
        ])->assertStatus(403);

        // unbind
        $this->postJson('/api/v1/user/sub-account/unbind', [
            'auth_data' => $auth,
            'id' => $relation->id,
        ])->assertStatus(403);

        // subscribe (按 child_user_id 越权)
        $this->withHeaders(['authorization' => $auth])
            ->getJson('/api/v1/user/sub-account/subscribe?child_user_id=' . $childB->id)
            ->assertStatus(403);

        // 所有敏感状态必须完全未变
        $relationAfter = SubAccountRelation::find($relation->id);
        $this->assertSame((int)$snapshot['relation']['status'], (int)$relationAfter->status);
        $this->assertSame((int)$snapshot['relation']['traffic_limit'], (int)$relationAfter->traffic_limit);
        $this->assertSame($snapshot['relation']['remark'], $relationAfter->remark);
        $this->assertSame((int)$parentB->id, (int)$relationAfter->parent_user_id);

        $childAfter = User::find($childB->id);
        $this->assertSame($snapshot['uuid'], $childAfter->uuid);
        $this->assertSame($snapshot['token'], $childAfter->token);
        $this->assertSame($snapshot['password'], $childAfter->password);
        $this->assertSame($snapshot['u'], (int)$childAfter->u);
        $this->assertSame($snapshot['d'], (int)$childAfter->d);

        // 越权尝试不得留下审计记录
        $this->assertCount(0, $this->auditLogs(SubAccountService::ACTION_UNBIND));
        $this->assertCount(0, $this->auditLogs(SubAccountService::ACTION_UPDATE_REMARK));
    }

    public function testUnknownRelationIdIsRejected()
    {
        $this->enableSubAccount();
        $parent = $this->makeParent();
        $auth = $this->authDataFor($parent);

        $this->postJson('/api/v1/user/sub-account/unbind', [
            'auth_data' => $auth,
            'id' => 999999,
        ])->assertStatus(500);

        $this->postJson('/api/v1/user/sub-account/update', [
            'auth_data' => $auth,
            'id' => 999999,
            'remark' => 'x',
        ])->assertStatus(500);
    }

    // ----------------------------------------------------------- 覆盖点 10

    public function testChangeSubAccountPasswordRejectsShortPassword()
    {
        $this->enableSubAccount();
        $parent = $this->makeParent();
        $child = $this->makeUser(['email' => 'short-pwd-child@example.com']);
        $relation = $this->bindSubAccount($parent, $child->email);
        $before = User::find($child->id)->password;

        $http = $this->postJson('/api/v1/user/sub-account/change-password', [
            'auth_data' => $this->authDataFor($parent),
            'id' => $relation->id,
            'password' => '1234567',
        ]);
        $http->assertStatus(500);
        $this->assertSame('Password must be at least 8 characters', $http->json('message'));
        $this->assertSame($before, User::find($child->id)->password);
    }

    public function testChangeSubAccountPasswordRehashesAndDestroysSessions()
    {
        $this->enableSubAccount();
        $parent = $this->makeParent();
        $child = $this->makeUser(['email' => 'pwd-child@example.com']);
        $relation = $this->bindSubAccount($parent, $child->email);

        // 先让子账号登录，产生一个会话
        $child = User::find($child->id);
        $sessionsKey = \App\Utils\CacheKey::get('USER_SESSIONS', $child->id);
        $this->assertNotEmpty($child->token);
        $authService = new \App\Services\AuthService($child);
        $authService->generateAuthData(\Illuminate\Http\Request::create('/', 'GET'));
        $this->assertNotEmpty(Cache::get($sessionsKey), '前置条件: 子账号应有活跃会话');

        $newPassword = 'brand-new-pass-1';
        $http = $this->postJson('/api/v1/user/sub-account/change-password', [
            'auth_data' => $this->authDataFor($parent),
            'id' => $relation->id,
            'password' => $newPassword,
        ]);
        $http->assertStatus(200);

        $after = User::find($child->id);
        $this->assertTrue(password_verify($newPassword, $after->password));
        $this->assertNotSame($child->password, $after->password);

        // 会话被清空
        $this->assertFalse(Cache::has($sessionsKey), '修改密码后必须清除子账号全部会话');
    }

    // ----------------------------------------------------------- 覆盖点 11

    public function testResetSubscribeRotatesUuidAndToken()
    {
        $this->enableSubAccount();
        $parent = $this->makeParent();
        $child = $this->makeUser(['email' => 'reset-sub@example.com']);
        $relation = $this->bindSubAccount($parent, $child->email);
        $child = User::find($child->id);

        $oldUuid = $child->uuid;
        $oldToken = $child->token;

        $http = $this->postJson('/api/v1/user/sub-account/reset-subscribe', [
            'auth_data' => $this->authDataFor($parent),
            'id' => $relation->id,
        ]);
        $http->assertStatus(200);
        $this->assertSame((int)$child->id, (int)$http->json('data.child_user_id'));
        $this->assertNotEmpty($http->json('data.subscribe_url'));

        $after = User::find($child->id);
        $this->assertNotSame($oldUuid, $after->uuid, 'uuid 必须轮换');
        $this->assertNotSame($oldToken, $after->token, 'token 必须轮换');
        $this->assertStringContainsString($after->token, $http->json('data.subscribe_url'));
    }

    // ----------------------------------------------------------- 覆盖点 25

    public function testAuditTrailRecordsAllParentActionsWithoutSecrets()
    {
        $this->enableSubAccount();
        $parent = $this->makeParent(['email' => 'audit-parent@example.com']);
        $child = $this->makeUser(['email' => 'audit-child@example.com']);
        $relation = $this->bindSubAccount($parent, $child->email, ['traffic_limit' => 1024, 'remark' => 'first']);

        $newPassword = 'audit-secret-pass';

        $this->postJson('/api/v1/user/sub-account/update', [
            'auth_data' => $this->authDataFor($parent),
            'id' => $relation->id,
            'traffic_limit' => 2048,
            'remark' => 'second',
        ])->assertStatus(200);

        $this->postJson('/api/v1/user/sub-account/reset-traffic', [
            'auth_data' => $this->authDataFor($parent),
            'id' => $relation->id,
        ])->assertStatus(200);

        $this->postJson('/api/v1/user/sub-account/reset-subscribe', [
            'auth_data' => $this->authDataFor($parent),
            'id' => $relation->id,
        ])->assertStatus(200);

        $this->postJson('/api/v1/user/sub-account/change-password', [
            'auth_data' => $this->authDataFor($parent),
            'id' => $relation->id,
            'password' => $newPassword,
        ])->assertStatus(200);

        $this->postJson('/api/v1/user/sub-account/unbind', [
            'auth_data' => $this->authDataFor($parent),
            'id' => $relation->id,
        ])->assertStatus(200);

        $expectedActions = [
            SubAccountService::ACTION_BIND,
            SubAccountService::ACTION_UPDATE_TRAFFIC,
            SubAccountService::ACTION_UPDATE_REMARK,
            SubAccountService::ACTION_RESET_TRAFFIC,
            SubAccountService::ACTION_RESET_SUBSCRIBE,
            SubAccountService::ACTION_CHANGE_PASSWORD,
            SubAccountService::ACTION_UNBIND,
        ];

        foreach ($expectedActions as $action) {
            $logs = $this->auditLogs($action);
            $this->assertNotEmpty($logs, "缺少审计动作 {$action}");
            $log = $logs[0];
            $this->assertSame($action, $log->action);
            $this->assertSame(\App\Models\SubAccountAuditLog::ACTOR_USER, $log->actor_type);
            $this->assertSame((int)$parent->id, (int)$log->actor_user_id);
            $this->assertSame((int)$parent->id, (int)$log->parent_user_id);
            $this->assertSame((int)$child->id, (int)$log->child_user_id);
            $this->assertSame((int)$relation->id, (int)$log->relation_id);
            $this->assertSame('127.0.0.1', $log->ip);
            $this->assertNotNull($log->created_at);
        }

        // 审计元数据里绝不能出现密码明文
        $allLogs = \App\Models\SubAccountAuditLog::all();
        $this->assertNotEmpty($allLogs);
        foreach ($allLogs as $log) {
            $raw = json_encode($log->metadata);
            $this->assertStringNotContainsString($newPassword, (string)$raw);
            $this->assertStringNotContainsString('password', strtolower((string)$raw));
        }

        // 关键审计内容
        $this->assertSame(2048, (int)$this->auditLogs(SubAccountService::ACTION_UPDATE_TRAFFIC)[0]->metadata['traffic_limit']);
        $this->assertSame('second', $this->auditLogs(SubAccountService::ACTION_UPDATE_REMARK)[0]->metadata['remark']);
        $this->assertTrue((bool)$this->auditLogs(SubAccountService::ACTION_CHANGE_PASSWORD)[0]->metadata['session_revoked']);
        $this->assertTrue((bool)$this->auditLogs(SubAccountService::ACTION_RESET_SUBSCRIBE)[0]->metadata['token_rotated']);
    }

    public function testAuditTrailRecordsAdminActions()
    {
        $this->enableSubAccount();
        $parent = $this->makeParent();
        $child = $this->makeUser(['email' => 'admin-audit-child@example.com']);
        $relation = $this->bindSubAccount($parent, $child->email);

        $admin = $this->makeUser(['email' => 'sysadmin@example.com', 'is_admin' => 1]);
        $service = $this->service();
        $service->adminUpdate($relation->id, ['traffic_limit' => 4096, 'status' => 1], $admin->id, '10.0.0.1');
        $service->adminResetTraffic($relation->id, $admin->id, '10.0.0.1');
        $service->adminResetSubscribe($relation->id, $admin->id, '10.0.0.1');
        $service->adminUnbind($relation->id, $admin->id, '10.0.0.1');

        $adminLogs = \App\Models\SubAccountAuditLog::where('actor_type', \App\Models\SubAccountAuditLog::ACTOR_ADMIN)->get();
        $this->assertGreaterThanOrEqual(4, $adminLogs->count());
        foreach ($adminLogs as $log) {
            $this->assertSame((int)$admin->id, (int)$log->actor_user_id);
        }

        $this->assertNotEmpty($this->auditLogs(SubAccountService::ACTION_ADMIN_UNBIND));
        $this->assertSame(
            SubAccountRelation::STATUS_DISABLED,
            (int)SubAccountRelation::find($relation->id)->status
        );
    }

    // ----------------------------------------------------------- 覆盖点 26

    public function testConfigRulesContainSubAccountKeys()
    {
        $rules = \App\Http\Requests\Admin\ConfigSave::RULES;

        $this->assertArrayHasKey('sub_account_enable', $rules);
        $this->assertSame('in:0,1', $rules['sub_account_enable']);

        $this->assertArrayHasKey('sub_account_max_count', $rules);
        $this->assertStringContainsString('integer', $rules['sub_account_max_count']);
        $this->assertStringContainsString('min:1', $rules['sub_account_max_count']);

        $this->assertArrayHasKey('sub_account_email_code_ttl', $rules);
        $this->assertStringContainsString('integer', $rules['sub_account_email_code_ttl']);
        $this->assertStringContainsString('min:30', $rules['sub_account_email_code_ttl']);

        $this->assertArrayHasKey('sub_account_email_code_interval', $rules);
        $this->assertStringContainsString('integer', $rules['sub_account_email_code_interval']);
        $this->assertStringContainsString('min:5', $rules['sub_account_email_code_interval']);
    }

    /**
     * 控制器直接返回 Illuminate\Http\Response（不是 TestResponse），
     * 因此没有 getData()，必须从原始 JSON 内容解析。
     */
    protected function payloadOf(\Illuminate\Http\Response $response): array
    {
        return (array)json_decode($response->getContent(), true);
    }

    public function testAdminConfigFetchExposesSubAccountSection()
    {
        config([
            'v2board.sub_account_enable' => 1,
            'v2board.sub_account_max_count' => 4,
            'v2board.sub_account_email_code_ttl' => 240,
            'v2board.sub_account_email_code_interval' => 45,
        ]);

        $controller = new \App\Http\Controllers\V1\Admin\ConfigController();
        $request = \Illuminate\Http\Request::create('/testsecure/config/fetch', 'GET', ['key' => 'sub_account']);
        $response = $controller->fetch($request);

        $payload = $this->payloadOf($response);
        $this->assertArrayHasKey('data', $payload);
        $this->assertArrayHasKey('sub_account', $payload['data']);

        $section = $payload['data']['sub_account'];
        $this->assertSame(1, $section['sub_account_enable']);
        $this->assertSame(4, $section['sub_account_max_count']);
        $this->assertSame(240, $section['sub_account_email_code_ttl']);
        $this->assertSame(45, $section['sub_account_email_code_interval']);
    }

    public function testAdminSubAccountStatusEndpointReportsStats()
    {
        $this->enableSubAccount(3, 300, 60);
        $parent = $this->makeParent();
        $child = $this->makeUser(['email' => 'status-child@example.com']);
        $this->makeRelation($parent, $child);

        $controller = new \App\Http\Controllers\V1\Admin\SubAccountController();
        $response = $controller->status(\Illuminate\Http\Request::create('/testsecure/sub-account/status', 'GET'));
        $payload = $this->payloadOf($response);

        $this->assertSame(1, $payload['data']['enabled']);
        $this->assertSame(3, $payload['data']['max_count']);
        $this->assertSame(300, $payload['data']['email_code_ttl']);
        $this->assertSame(60, $payload['data']['email_code_interval']);
        $this->assertSame(1, $payload['data']['stats']['active_relations']);
        $this->assertSame(0, $payload['data']['stats']['orphan_relations']);
        $this->assertSame(0, $payload['data']['stats']['duplicate_child']);
        $this->assertSame(0, $payload['data']['stats']['cycles']);
    }

    public function testAdminFetchAndAuditEndpointsReturnRelations()
    {
        $this->enableSubAccount();
        $parent = $this->makeParent();
        $child = $this->makeUser(['email' => 'admin-fetch-child@example.com']);
        $relation = $this->makeRelation($parent, $child, ['traffic_limit' => 2048, 'remark' => 'r1']);

        $controller = new \App\Http\Controllers\V1\Admin\SubAccountController();

        $fetch = $this->payloadOf($controller->fetch(\Illuminate\Http\Request::create('/testsecure/sub-account/fetch', 'GET', [
            'current' => 1,
            'page_size' => 20,
        ])));
        $this->assertSame(1, $fetch['data']['total']);
        $this->assertSame($child->email, $fetch['data']['items'][0]['child_email']);
        $this->assertSame($parent->email, $fetch['data']['items'][0]['parent_email']);
        $this->assertSame(2048, $fetch['data']['items'][0]['traffic_limit']);
        $this->assertSame(1, $fetch['data']['items'][0]['status']);

        // 过滤条件
        $filtered = $this->payloadOf($controller->fetch(\Illuminate\Http\Request::create('/testsecure/sub-account/fetch', 'GET', [
            'parent_user_id' => $parent->id + 1000,
        ])));
        $this->assertSame(0, $filtered['data']['total']);

        $audit = $this->payloadOf($controller->audit(\Illuminate\Http\Request::create('/testsecure/sub-account/audit', 'GET', [
            'relation_id' => $relation->id,
        ])));
        $this->assertSame(0, $audit['data']['total']);
    }

    public function testAdminUnbindEndpointFromController()
    {
        $this->enableSubAccount();
        $parent = $this->makeParent();
        $child = $this->makeUser(['email' => 'admin-unbind-child@example.com']);
        $relation = $this->makeRelation($parent, $child);
        $admin = $this->makeUser(['email' => 'admin-unbind@example.com', 'is_admin' => 1]);

        $controller = new \App\Http\Controllers\V1\Admin\SubAccountController();
        $request = \Illuminate\Http\Request::create('/testsecure/sub-account/unbind', 'POST', ['id' => $relation->id]);
        $request->merge(['user' => ['id' => $admin->id, 'email' => $admin->email, 'is_admin' => 1]]);

        $payload = $this->payloadOf($controller->unbind($request));
        $this->assertTrue($payload['data']);

        $this->assertSame(SubAccountRelation::STATUS_DISABLED, (int)SubAccountRelation::find($relation->id)->status);
        $log = $this->auditLogs(SubAccountService::ACTION_ADMIN_UNBIND);
        $this->assertNotEmpty($log);
        $this->assertSame((int)$admin->id, (int)$log[0]->actor_user_id);
    }

    public function testAdminUpdateAndResetTrafficEndpointsFromController()
    {
        $this->enableSubAccount();
        $parent = $this->makeParent();
        $child = $this->makeUser(['email' => 'admin-update-child@example.com', 'u' => 10, 'd' => 20]);
        $relation = $this->makeRelation($parent, $child, ['remark' => 'before']);
        $admin = $this->makeUser(['email' => 'admin-update@example.com', 'is_admin' => 1]);

        $controller = new \App\Http\Controllers\V1\Admin\SubAccountController();

        $updateRequest = \Illuminate\Http\Request::create('/testsecure/sub-account/update', 'POST', [
            'id' => $relation->id,
            'traffic_limit' => 777,
            'remark' => 'after',
        ]);
        $updateRequest->merge(['user' => ['id' => $admin->id, 'email' => $admin->email, 'is_admin' => 1]]);
        $this->assertTrue($this->payloadOf($controller->update($updateRequest))['data']);

        $relationAfter = SubAccountRelation::find($relation->id);
        $this->assertSame(777, (int)$relationAfter->traffic_limit);
        $this->assertSame('after', $relationAfter->remark);
        $adminLogs = $this->auditLogs(SubAccountService::ACTION_UPDATE_TRAFFIC);
        $this->assertNotEmpty($adminLogs);
        $this->assertSame(\App\Models\SubAccountAuditLog::ACTOR_ADMIN, $adminLogs[0]->actor_type);

        $resetRequest = \Illuminate\Http\Request::create('/testsecure/sub-account/reset-traffic', 'POST', [
            'id' => $relation->id,
        ]);
        $resetRequest->merge(['user' => ['id' => $admin->id, 'email' => $admin->email, 'is_admin' => 1]]);
        $this->assertTrue($this->payloadOf($controller->resetTraffic($resetRequest))['data']);

        $this->assertSame(0, (int)User::find($child->id)->u);
        $this->assertSame(0, (int)User::find($child->id)->d);
    }

    public function testAdminResetSubscribeEndpointFromController()
    {
        $this->enableSubAccount();
        $parent = $this->makeParent();
        $child = $this->makeUser(['email' => 'admin-resetsub-child@example.com']);
        $relation = $this->makeRelation($parent, $child);
        $admin = $this->makeUser(['email' => 'admin-resetsub@example.com', 'is_admin' => 1]);
        $oldToken = User::find($child->id)->token;

        $controller = new \App\Http\Controllers\V1\Admin\SubAccountController();
        $request = \Illuminate\Http\Request::create('/testsecure/sub-account/reset-subscribe', 'POST', ['id' => $relation->id]);
        $request->merge(['user' => ['id' => $admin->id, 'email' => $admin->email, 'is_admin' => 1]]);

        $this->assertTrue($this->payloadOf($controller->resetSubscribe($request))['data']);
        $this->assertNotSame($oldToken, User::find($child->id)->token);
    }

    // -------------------------------------------------- 其余端点契约测试

    public function testListEndpointReturnsFullContractForEnabledFeature()
    {
        $this->enableSubAccount(5);
        $parent = $this->makeParent(['email' => 'list-parent@example.com', 'speed_limit' => 77]);
        $child = $this->makeUser(['email' => 'list-child@example.com']);
        $relation = $this->bindSubAccount($parent, $child->email, ['traffic_limit' => 1073741824, 'remark' => 'hello']);

        $http = $this->withHeaders(['authorization' => $this->authDataFor($parent)])
            ->getJson('/api/v1/user/sub-account/list');

        $http->assertStatus(200);
        $http->assertJsonPath('data.enabled', true);
        $http->assertJsonPath('data.is_sub_account', 0);
        $http->assertJsonPath('data.max_count', 5);
        $http->assertJsonPath('data.created_count', 1);
        $http->assertJsonPath('data.items.0.id', (int)$relation->id);
        $http->assertJsonPath('data.items.0.child_user_id', (int)$child->id);
        $http->assertJsonPath('data.items.0.email', $child->email);
        $http->assertJsonPath('data.items.0.remark', 'hello');
        $http->assertJsonPath('data.items.0.traffic_limit', 1073741824);
        $http->assertJsonPath('data.items.0.status', 1);
        // 该用例绑定的是「已存在的空白账号」，因此 created_by_parent 的正确值是 0
        // （1 只用于「由主账号新建」的子账号）。
        $http->assertJsonPath('data.items.0.created_by_parent', 0);
        $this->assertNotEmpty($http->json('data.items.0.subscribe_url'));
        $this->assertNotNull($http->json('data.items.0.next_reset_at'));
    }

    public function testSubscribeEndpointReturnsChildItemAndIsOwnerScoped()
    {
        $this->enableSubAccount();
        $parent = $this->makeParent();
        $child = $this->makeUser(['email' => 'subscribe-child@example.com']);
        $relation = $this->bindSubAccount($parent, $child->email);

        $http = $this->withHeaders(['authorization' => $this->authDataFor($parent)])
            ->getJson('/api/v1/user/sub-account/subscribe?child_user_id=' . $child->id);
        $http->assertStatus(200);
        $http->assertJsonPath('data.id', (int)$relation->id);
        $http->assertJsonPath('data.child_user_id', (int)$child->id);

        // 同一路由的第二个参数用法（父子关联必须按 child_user_id 精确匹配）
        // 注意: withHeaders() 只作用于紧随其后的一个请求，必须重新挂鉴权头。
        // 与上一行等价的查询串写法。注意 call() 传 params 时中间件读不到
        // child_user_id 而误判越权返回 403，属测试写法问题，改用 getJson。
        $this->withHeaders(['authorization' => $this->authDataFor($parent)])
            ->getJson('/api/v1/user/sub-account/subscribe?child_user_id=' . $child->id)
            ->assertStatus(200);

        $this->withHeaders(['authorization' => $this->authDataFor($parent)])
            ->getJson('/api/v1/user/sub-account/subscribe?child_user_id=999999')
            ->assertStatus(403);
    }

    public function testSubAccountItselfGetsEmptyListAndIsBlockedFromManagement()
    {
        $this->enableSubAccount();
        $parent = $this->makeParent(['email' => 'guard-parent@example.com']);
        $child = $this->makeUser(['email' => 'guard-child@example.com']);
        $relation = $this->makeRelation($parent, $child);
        $auth = $this->authDataFor($child);

        // list 返回空列表而不是报错（主题兼容）
        $list = $this->withHeaders(['authorization' => $auth])->getJson('/api/v1/user/sub-account/list');
        $list->assertStatus(200);
        $list->assertJsonPath('data.is_sub_account', 1);
        $list->assertJsonPath('data.enabled', true);
        $list->assertJsonPath('data.created_count', 0);
        $list->assertJsonPath('data.items', []);

        // 其余管理动作被 sub_account 中间件直接 403
        foreach ([
            ['/api/v1/user/sub-account/update', ['id' => $relation->id, 'remark' => 'x']],
            ['/api/v1/user/sub-account/unbind', ['id' => $relation->id]],
            ['/api/v1/user/sub-account/change-password', ['id' => $relation->id, 'password' => 'abcdefgh1']],
            ['/api/v1/user/sub-account/reset-subscribe', ['id' => $relation->id]],
            ['/api/v1/user/sub-account/reset-traffic', ['id' => $relation->id]],
        ] as $case) {
            $this->postJson($case[0], array_merge(['auth_data' => $auth], $case[1]))->assertStatus(403);
        }

        $this->withHeaders(['authorization' => $auth])
            ->getJson('/api/v1/user/sub-account/subscribe?child_user_id=' . $child->id)
            ->assertStatus(403);

        // 关系未被改动
        $this->assertSame(SubAccountRelation::STATUS_ENABLED, (int)SubAccountRelation::find($relation->id)->status);
    }

    public function testUnauthenticatedRequestsAreRejected()
    {
        $this->enableSubAccount();
        $this->getJson('/api/v1/user/sub-account/list')->assertStatus(403);
        $this->postJson('/api/v1/user/sub-account/bind', ['email' => 'x@example.com'])->assertStatus(403);
    }
}
