<?php

namespace Tests\Feature;

use App\Models\ServerVless;
use App\Models\SubAccountAuditLog;
use App\Models\SubAccountRelation;
use App\Models\User;
use App\Services\ServerService;
use App\Services\SubAccountService;
use Illuminate\Support\Facades\Queue;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * 子账号四项后端加固的回归测试：
 *
 *   1. 主账号绑定资格（统一 assertEligibleParent；bind 事务内二次校验）
 *   2. 验证码作用域（parent_user_id + 邮箱 + APP_KEY；冷却独立；一次性）
 *   3. 归档关系权限（用户端所有关系操作都必须要求 status=1）
 *   4. 永久有效主账号（expired_at 为 NULL）的子账号应出现在节点用户名单
 */
class SubAccountHardeningTest extends SubAccountTestCase
{
    /**
     * @param string|null $messageKey 与 Service 内 __('...') 相同的英文原文；
     *                                 断言时再翻译，避免 data provider 阶段访问容器。
     */
    private function assertHttpFailure(callable $fn, int $status, string $messageKey = null)
    {
        try {
            $fn();
        } catch (HttpException $e) {
            $this->assertSame($status, $e->getStatusCode(), '状态码不符：' . $e->getMessage());
            if ($messageKey !== null) {
                $this->assertSame(__($messageKey), $e->getMessage());
            }
            return;
        }
        $this->fail('预期抛出 HttpException(' . $status . ')，但没有抛出');
    }

    // ===================================================== 1. 主账号绑定资格

    public function ineligibleParentProvider(): array
    {
        return [
            '封禁' => [['banned' => 1], 500, 'The account has been banned'],
            '无套餐' => [['plan_id' => null], 500, 'The parent account has no active plan'],
            '已过期' => [['expired_at' => time() - 60], 500, 'The parent account plan has expired'],
            '零额度' => [['transfer_enable' => 0], 500, 'The parent account has no traffic quota'],
        ];
    }

    /** @dataProvider ineligibleParentProvider */
    public function testSendBindCodeRejectsIneligibleParent(array $override, int $status, string $message)
    {
        $this->enableSubAccount(5);
        Queue::fake();
        $parent = $this->makeParent($override);

        $this->assertHttpFailure(function () use ($parent) {
            $this->service()->sendBindCode($parent, 'target@example.com', '127.0.0.1');
        }, $status, $message);

        Queue::assertNothingPushed();
    }

    /** @dataProvider ineligibleParentProvider */
    public function testBindRejectsIneligibleParent(array $override, int $status, string $message)
    {
        $this->enableSubAccount(5);
        $parent = $this->makeParent($override);
        $this->seedBindCode($parent, 'target@example.com');

        $this->assertHttpFailure(function () use ($parent) {
            $this->service()->bind($parent, [
                'email' => 'target@example.com',
                'code' => '123456',
            ], '127.0.0.1');
        }, $status, $message);

        $this->assertSame(0, User::where('email', 'target@example.com')->count());
    }

    /**
     * 关键回归：验证码发出之后主账号状态发生变化（此处为套餐到期）。
     *
     * 传入一个"内存里仍是有效"的旧实例，使 bind 的前置校验通过，
     * 从而真正走到事务内 `lockForUpdate()` 之后的二次校验 —— 必须被拒绝。
     */
    public function testBindRevalidatesParentInsideTransactionAfterStateChange()
    {
        $this->enableSubAccount(5);
        $parent = $this->makeParent(['expired_at' => time() + 3600]);
        $this->seedBindCode($parent, 'after-change@example.com');

        // 取一个"过期前"的内存副本，然后把数据库里的主账号改为已过期
        $stale = User::find($parent->id);
        User::where('id', $parent->id)->update(['expired_at' => time() - 10]);
        $this->assertGreaterThan(time(), (int)$stale->expired_at, '内存副本应仍是有效状态（前置校验会通过）');

        $this->assertHttpFailure(function () use ($stale) {
            $this->service()->bind($stale, [
                'email' => 'after-change@example.com',
                'code' => '123456',
            ], '127.0.0.1');
        }, 500, 'The parent account plan has expired');

        $this->assertSame(0, User::where('email', 'after-change@example.com')->count());
        $this->assertSame(0, SubAccountRelation::where('parent_user_id', $parent->id)->count());
    }

    /** 流量耗尽**不影响**绑定（只影响子账号能否连接节点） */
    public function testExhaustedParentCanStillBindButChildrenCannotConnect()
    {
        $this->enableSubAccount(5);
        $parent = $this->makeParent(['transfer_enable' => 1000, 'u' => 1000, 'd' => 0]);
        $this->seedBindCode($parent, 'exhausted-parent-child@example.com');

        $result = $this->service()->bind($parent, [
            'email' => 'exhausted-parent-child@example.com',
            'code' => '123456',
        ], '127.0.0.1');
        $this->assertArrayHasKey('data', $result);

        $child = User::where('email', 'exhausted-parent-child@example.com')->first();
        $this->assertNotNull($child);

        $entitlement = $this->service()->resolveEntitlement($child);
        $this->assertFalse($entitlement->canConnect(), '主账号流量耗尽时子账号不得连接节点');
        $this->assertFalse($entitlement->isSubscriptionAvailable());
    }

    // ===================================================== 2. 验证码作用域

    public function testVerificationCodeIsScopedToParent()
    {
        $this->enableSubAccount(5);
        $parentA = $this->makeParent(['email' => 'scope-a@example.com']);
        $parentB = $this->makeParent(['email' => 'scope-b@example.com']);
        $email = 'shared-target@example.com';

        // A 的验证码
        $this->seedBindCode($parentA, $email, '111111');

        // B 用 A 的验证码绑定同一邮箱 → 必须失败
        $this->assertHttpFailure(function () use ($parentB, $email) {
            $this->service()->bind($parentB, [
                'email' => $email,
                'code' => '111111',
            ], '127.0.0.1');
        }, 500, 'The verification code has expired, please resend');

        $this->assertSame(0, User::where('email', $email)->count(), 'B 不得用 A 的验证码创建/绑定子账号');

        // A 用同一个验证码绑定 → 成功
        $result = $this->service()->bind($parentA, [
            'email' => $email,
            'code' => '111111',
        ], '127.0.0.1');
        $this->assertArrayHasKey('data', $result);

        // 两个父账号的缓存键互不相同
        $this->assertNotSame(
            $this->emailCodeCacheKey($parentA, $email),
            $this->emailCodeCacheKey($parentB, $email)
        );
    }

    public function testCooldownIsIndependentPerParent()
    {
        $this->enableSubAccount(5, 300, 60);
        Queue::fake();
        $parentA = $this->makeParent(['email' => 'cooldown-a@example.com']);
        $parentB = $this->makeParent(['email' => 'cooldown-b@example.com']);
        $email = 'cooldown-target@example.com';

        // A 发送成功后进入冷却
        $this->service()->sendBindCode($parentA, $email, '127.0.0.1');
        $this->assertHttpFailure(function () use ($parentA, $email) {
            $this->service()->sendBindCode($parentA, $email, '127.0.0.1');
        }, 500, 'Email verification code has been sent, please request again later');

        // B 对同一邮箱不受 A 的冷却影响
        $result = $this->service()->sendBindCode($parentB, $email, '127.0.0.1');
        $this->assertSame(['data' => true], $result);
    }

    public function testScopedVerificationCodeIsStillSingleUse()
    {
        $this->enableSubAccount(5);
        $parent = $this->makeParent(['email' => 'single-use-scope@example.com']);
        $email = 'single-use-target@example.com';
        $this->seedBindCode($parent, $email, '123456');

        $this->service()->bind($parent, ['email' => $email, 'code' => '123456'], '127.0.0.1');

        $this->assertHttpFailure(function () use ($parent, $email) {
            $this->service()->bind($parent, ['email' => $email, 'code' => '123456'], '127.0.0.1');
        }, 500, 'The verification code has expired, please resend');
    }

    // ===================================================== 3. 归档关系权限

    /**
     * @return array<string, array{0:string,1:string}>
     */
    public function archivedRelationEndpointProvider(): array
    {
        return [
            'update' => ['/api/v1/user/sub-account/update', 'postJson', ['id' => ':id', 'traffic_limit_gb' => 2]],
            'change-password' => ['/api/v1/user/sub-account/change-password', 'postJson', ['id' => ':id', 'new_password' => 'newpassword123']],
            'reset-traffic' => ['/api/v1/user/sub-account/reset-traffic', 'postJson', ['id' => ':id']],
            'reset-subscribe' => ['/api/v1/user/sub-account/reset-subscribe', 'postJson', ['id' => ':id']],
            'unbind' => ['/api/v1/user/sub-account/unbind', 'postJson', ['id' => ':id']],
        ];
    }

    /** @dataProvider archivedRelationEndpointProvider */
    public function testArchivedRelationIsDeniedOnEveryUserEndpoint(string $uri, string $method, array $payload)
    {
        $this->enableSubAccount(5);
        $parent = $this->makeParent(['email' => 'archived-' . md5($uri) . '@example.com']);
        $child = $this->makeUser(['email' => 'archived-child-' . md5($uri) . '@example.com']);
        $relation = $this->makeRelation($parent, $child, ['traffic_limit' => 1073741824]);

        // 解绑 → 关系归档（status=0），用户与审计保留
        $this->service()->unbind($parent, ['id' => $relation->id], '127.0.0.1');
        $this->assertSame(0, (int)$relation->fresh()->status);

        $payload = array_map(function ($v) use ($relation) {
            return $v === ':id' ? $relation->id : $v;
        }, $payload);

        $http = $this->postJson($uri, array_merge($payload, [
            'auth_data' => $this->authDataFor($parent),
        ]));

        $http->assertStatus(500);
        $this->assertSame(__('The sub-account has been unbound'), $http->json('message'));
    }

    public function testArchivedRelationSubscribeIsDeniedByIdAndByChildId()
    {
        $this->enableSubAccount(5);
        $parent = $this->makeParent(['email' => 'archived-sub@example.com']);
        $child = $this->makeUser(['email' => 'archived-sub-child@example.com']);
        $relation = $this->makeRelation($parent, $child);

        $this->service()->unbind($parent, ['id' => $relation->id], '127.0.0.1');

        // 按关系 id
        $byId = $this->withHeaders(['authorization' => $this->authDataFor($parent)])
            ->getJson('/api/v1/user/sub-account/subscribe?id=' . $relation->id);
        $byId->assertStatus(500);
        $this->assertSame(__('The sub-account has been unbound'), $byId->json('message'));

        // 按 child_user_id
        $byChild = $this->withHeaders(['authorization' => $this->authDataFor($parent)])
            ->getJson('/api/v1/user/sub-account/subscribe?child_user_id=' . $child->id);
        $byChild->assertStatus(403);
        $this->assertSame(__('You do not have permission to operate this sub-account'), $byChild->json('message'));
    }

    public function testAdminEndpointsStillAcceptArchivedRelations()
    {
        $this->enableSubAccount(5);
        $parent = $this->makeParent(['email' => 'admin-archived@example.com']);
        $child = $this->makeUser(['email' => 'admin-archived-child@example.com']);
        $relation = $this->makeRelation($parent, $child, ['remark' => 'before']);

        $this->service()->unbind($parent, ['id' => $relation->id], '127.0.0.1');

        // 管理端可读取归档关系详情
        $detail = $this->service()->adminDetail($relation->id);
        $this->assertSame(0, (int)$detail['data']['status']);
        $this->assertSame($child->email, $detail['data']['child']['email']);

        // 管理端可修改归档关系的备注/状态（包含归档）
        $this->service()->adminUpdate($relation->id, ['remark' => 'after'], 1, '127.0.0.1');
        $this->assertSame('after', SubAccountRelation::find($relation->id)->remark);

        // 审计保留
        $this->assertTrue(
            SubAccountAuditLog::where('relation_id', $relation->id)->where('action', SubAccountService::ACTION_UNBIND)->exists()
        );
    }

    // ===================================================== 4. 永久有效主账号

    private function makeVlessServerForGroup(int $groupId, string $name, int $sort): ServerVless
    {
        $server = new ServerVless();
        foreach ([
            'group_id' => [(string)$groupId],
            'route_id' => null,
            'name' => $name,
            'parent_id' => null,
            'host' => '127.0.0.1',
            'port' => '443',
            'server_port' => 443,
            'tags' => null,
            'rate' => '1',
            'show' => 1,
            'sort' => $sort,
            'tls' => 0,
            'flow' => null,
            'network' => 'tcp',
            'created_at' => time(),
            'updated_at' => time(),
        ] as $k => $v) {
            $server->{$k} = $v;
        }
        $server->save();
        return $server->refresh();
    }

    private function nodeUserIds(array $groupIds): array
    {
        $ids = [];
        foreach ((new ServerService())->getAvailableUsers($groupIds) as $user) {
            $ids[] = (int)$user->id;
        }
        sort($ids);
        return $ids;
    }

    public function testNonExpiringParentChildrenAppearInNodeUserList()
    {
        $this->enableSubAccount(5);
        $group = 771;
        $parent = $this->makeParent([
            'email' => 'forever-parent@example.com',
            'group_id' => $group,
            'expired_at' => null,
        ]);
        $child = $this->makeUser(['email' => 'forever-child@example.com', 'group_id' => null]);
        $this->makeRelation($parent, $child);

        $this->assertContains((int)$child->id, $this->nodeUserIds([$group]), '永久有效主账号的子账号应出现在节点用户名单');
    }

    public function testExpiredParentChildrenAreAbsentFromNodeUserList()
    {
        $this->enableSubAccount(5);
        $group = 772;
        $parent = $this->makeParent([
            'email' => 'expired-parent@example.com',
            'group_id' => $group,
            'expired_at' => time() - 60,
        ]);
        $child = $this->makeUser(['email' => 'expired-child@example.com', 'group_id' => null]);
        $this->makeRelation($parent, $child);

        $this->assertNotContains((int)$child->id, $this->nodeUserIds([$group]));
    }

    public function testExhaustedParentChildrenAreAbsentFromNodeUserList()
    {
        $this->enableSubAccount(5);
        $group = 773;
        $parent = $this->makeParent([
            'email' => 'exhausted-parent@example.com',
            'group_id' => $group,
            'expired_at' => time() + 86400,
            'transfer_enable' => 1000,
            'u' => 1000,
            'd' => 0,
        ]);
        $child = $this->makeUser(['email' => 'exhausted-child@example.com', 'group_id' => null]);
        $this->makeRelation($parent, $child);

        $this->assertNotContains((int)$child->id, $this->nodeUserIds([$group]));
    }

    public function testNonExpiringParentChildSeesParentGroupNodesInSubscription()
    {
        $this->enableSubAccount(5);
        $group = 774;
        $parent = $this->makeParent([
            'email' => 'forever-sub-parent@example.com',
            'group_id' => $group,
            'expired_at' => null,
        ]);
        $child = $this->makeUser(['email' => 'forever-sub-child@example.com', 'group_id' => null]);
        $this->makeRelation($parent, $child);
        $server = $this->makeVlessServerForGroup($group, 'forever-node', 1);

        $servers = (new ServerService())->getAvailableServers(User::find($child->id));
        $ids = array_map(function ($s) { return (int)$s['id']; }, $servers);
        $this->assertContains((int)$server->id, $ids, '永久有效主账号的子账号应拿到主账号权限组的节点');
    }
}
