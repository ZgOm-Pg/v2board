<?php

namespace Tests\Feature;

use App\Console\Commands\CheckRenewal;
use App\Console\Commands\ResetTraffic;
use App\Console\Commands\SendRemindMail;
use App\Jobs\SendEmailJob;
use App\Models\Plan;
use App\Models\SubAccountAuditLog;
use App\Models\SubAccountRelation;
use App\Models\User;
use App\Services\ServerService;
use App\Services\SubAccountService;
use App\Utils\CacheKey;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

/**
 * 生命周期测试: 绑定/解绑/重绑、密码、订阅重置、周期重置、定时任务隔离、并发约束。
 *
 * 覆盖点: 7、10、11、12、19、20、21、22、25。
 */
class SubAccountLifecycleTest extends SubAccountTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (!$this->tableExists('v2_user_sub_accounts')) {
            \Illuminate\Support\Facades\Artisan::call('sub-account:install --apply');
        }
    }

    // ------------------------------------------------------------ 覆盖点 12

    public function testUnbindDisablesRelationRotatesCredentialsAndPreservesHistory()
    {
        $this->enableSubAccount();
        $parent = $this->makeParent(['email' => 'unbind-parent@example.com']);
        $child = $this->makeUser(['email' => 'unbind-child@example.com']);
        $relation = $this->bindSubAccount($parent, $child->email);
        // 绑定校验要求候选账号"零流量"，因此流量历史必须在绑定成功之后产生
        $child = $this->seedChildTraffic($child, 123, 456, 1);

        $oldUuid = $child->uuid;
        $oldToken = $child->token;
        $relationId = (int)$relation->id;

        $http = $this->postJson('/api/v1/user/sub-account/unbind', [
            'auth_data' => $this->authDataFor($parent),
            'id' => $relationId,
        ]);
        $http->assertStatus(200);

        $relationAfter = SubAccountRelation::find($relationId);
        $this->assertNotNull($relationAfter, '解绑不得删除关系记录');
        $this->assertSame(SubAccountRelation::STATUS_DISABLED, (int)$relationAfter->status);

        $childAfter = User::find($child->id);
        $this->assertNotNull($childAfter, '解绑不得删除 v2_user 记录');
        $this->assertNotSame($oldUuid, $childAfter->uuid, '解绑必须轮换 uuid');
        $this->assertNotSame($oldToken, $childAfter->token, '解绑必须轮换 token');

        // 流量历史保留
        $this->assertSame(123, (int)$childAfter->u);
        $this->assertSame(456, (int)$childAfter->d);
        $this->assertSame(1, (int)$childAfter->t);

        // 审计日志保留
        $logs = $this->auditLogs(SubAccountService::ACTION_UNBIND);
        $this->assertNotEmpty($logs);
        $this->assertSame($relationId, (int)$logs[0]->relation_id);
        $this->assertSame(SubAccountRelation::STATUS_DISABLED, (int)$logs[0]->metadata['status']);

        // 解绑后不再继承主账号授权。
        // 注意: 本夹具的子账号自身带有 group_id=1 与很大额度，解绑后它会以
        // 「普通用户」身份独立出现在节点用户列表中 —— 这正是「恢复为普通账号」
        // 的正确表现，不能断言它必须消失。
        $childAfterUnbind = User::find($child->id);
        $this->assertFalse($this->service()->isSubAccount($childAfterUnbind));
        $this->assertNull($this->service()->parentIdForChild($child->id));
        $ids = array_map(function ($user) { return (int)$user->id; }, (new ServerService())->getAvailableUsers('1')->all());
        $this->assertContains((int)$child->id, $ids, '解绑后按自身套餐以普通用户身份参与列表');

        // 反向证明「确实不再继承主账号额度」: 清零子账号自身额度后必须消失。
        User::where('id', $child->id)->update(['transfer_enable' => 0]);
        $idsAfterZeroQuota = array_map(function ($user) { return (int)$user->id; }, (new ServerService())->getAvailableUsers('1')->all());
        $this->assertNotContains((int)$child->id, $idsAfterZeroQuota, '不再继承主账号额度');

        // 二次解绑必须失败
        $this->postJson('/api/v1/user/sub-account/unbind', [
            'auth_data' => $this->authDataFor($parent),
            'id' => $relationId,
        ])->assertStatus(500);
    }

    public function testRebindReusesSameRelationRowAndRestoresStatus()
    {
        $this->enableSubAccount();
        $parent = $this->makeParent(['email' => 'rebind-parent@example.com']);
        $child = $this->makeUser(['email' => 'rebind-child@example.com']);
        $relation = $this->bindSubAccount($parent, $child->email);
        $relationId = (int)$relation->id;
        $childId = (int)$child->id;

        $this->postJson('/api/v1/user/sub-account/unbind', [
            'auth_data' => $this->authDataFor($parent),
            'id' => $relationId,
        ])->assertStatus(200);

        $uuidAfterUnbind = User::find($childId)->uuid;
        $tokenAfterUnbind = User::find($childId)->token;

        $http = $this->postJson('/api/v1/user/sub-account/bind', [
            'auth_data' => $this->authDataFor($parent),
            'email' => $child->email,
            'code' => $this->seedBindCode($parent, $child->email),
            'traffic_limit' => 2048,
            'remark' => 'rebound',
        ]);
        $http->assertStatus(200);
        $this->assertSame($relationId, (int)$http->json('data.id'), '重新绑定必须复用同一关系记录 (id)');

        $relationAfter = SubAccountRelation::find($relationId);
        $this->assertSame(SubAccountRelation::STATUS_ENABLED, (int)$relationAfter->status);
        $this->assertSame(2048, (int)$relationAfter->traffic_limit);
        $this->assertSame('rebound', $relationAfter->remark);
        $this->assertSame((int)$parent->id, (int)$relationAfter->parent_user_id);

        // 同一个 child_user_id 只允许一行
        $this->assertSame(1, SubAccountRelation::where('child_user_id', $childId)->count());
        $this->assertSame(1, User::where('email', $child->email)->count());

        // 重新绑定不重置流量历史，但凭据沿用解绑时轮换后的值
        $childAfter = User::find($childId);
        $this->assertSame($uuidAfterUnbind, $childAfter->uuid);
        $this->assertSame($tokenAfterUnbind, $childAfter->token);

        // 绑定动作留下了 bind 审计（已存在的账号 => ACTION_BIND）
        $this->assertNotEmpty($this->auditLogs(SubAccountService::ACTION_BIND));
    }

    public function testRebindAfterUnbindIsBlockedWhileChildHasOwnSubAccounts()
    {
        $this->enableSubAccount();
        $parent = $this->makeParent(['email' => 'cycle-parent@example.com']);
        $child = $this->makeUser(['email' => 'cycle-child@example.com']);
        $relation = $this->bindSubAccount($parent, $child->email);

        // 解绑
        $this->postJson('/api/v1/user/sub-account/unbind', [
            'auth_data' => $this->authDataFor($parent),
            'id' => $relation->id,
        ])->assertStatus(200);

        // 子账号自己又成了别人的主账号（结构上模拟异常数据）
        $grandChild = $this->makeUser(['email' => 'cycle-grandchild@example.com']);
        $this->makeRelation(User::find($child->id), $grandChild);

        $http = $this->postJson('/api/v1/user/sub-account/bind', [
            'auth_data' => $this->authDataFor($parent),
            'email' => $child->email,
            'code' => $this->seedBindCode($parent, $child->email),
        ]);
        $http->assertStatus(500);
        $this->assertSame('This account already has sub-accounts', $http->json('message'));
        $this->assertSame(SubAccountRelation::STATUS_DISABLED, (int)SubAccountRelation::find($relation->id)->status);
    }

    // ------------------------------------------------------------ 覆盖点 7

    public function testChildUserIdUniqueIndexExistsAndBlocksDuplicateInsert()
    {
        $this->enableSubAccount();
        $parentA = $this->makeParent(['email' => 'uniq-parent-a@example.com']);
        $parentB = $this->makeParent(['email' => 'uniq-parent-b@example.com']);
        $child = $this->makeUser(['email' => 'uniq-child@example.com']);
        $this->makeRelation($parentA, $child);

        // 唯一索引必须存在（并发下防止同一账号挂到两个主账号）
        $rows = DB::select(
            'SELECT INDEX_NAME, COLUMN_NAME, NON_UNIQUE FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            ['v2_user_sub_accounts', 'child_user_id']
        );
        $unique = null;
        foreach ($rows as $row) {
            if ((int)$row->NON_UNIQUE === 0) {
                $unique = $row->INDEX_NAME;
            }
        }
        $this->assertNotNull($unique, 'v2_user_sub_accounts.child_user_id 必须有唯一索引');
        $this->assertSame('v2_user_sub_accounts_child_user_id_unique', $unique);

        // 绕过服务层直接插入重复 child_user_id 必须被数据库拒绝
        $duplicateFailed = false;
        try {
            DB::table('v2_user_sub_accounts')->insert([
                'parent_user_id' => $parentB->id,
                'child_user_id' => $child->id,
                'traffic_limit' => 0,
                'remark' => null,
                'status' => SubAccountRelation::STATUS_ENABLED,
                'created_by_parent' => 0,
                'created_at' => time(),
                'updated_at' => time(),
            ]);
        } catch (\Illuminate\Database\QueryException $e) {
            $duplicateFailed = true;
        }
        $this->assertTrue($duplicateFailed, '重复 child_user_id 的插入必须失败');
        $this->assertSame(1, SubAccountRelation::where('child_user_id', $child->id)->count());
        $this->assertSame((int)$parentA->id, (int)SubAccountRelation::where('child_user_id', $child->id)->first()->parent_user_id);
    }

    public function testMaxCountBoundaryAllowsExactlyMaxCount()
    {
        $this->enableSubAccount(2);
        $parent = $this->makeParent(['email' => 'boundary-parent@example.com']);

        $first = $this->makeUser(['email' => 'boundary-1@example.com']);
        $second = $this->makeUser(['email' => 'boundary-2@example.com']);
        $this->bindSubAccount($parent, $first->email);
        $this->bindSubAccount($parent, $second->email);
        $this->assertSame(2, count($this->service()->activeChildIdsForParent($parent->id)));

        $thirdEmail = 'boundary-3@example.com';
        $http = $this->postJson('/api/v1/user/sub-account/bind', [
            'auth_data' => $this->authDataFor($parent),
            'email' => $thirdEmail,
            'code' => $this->seedBindCode($parent, $thirdEmail),
        ]);
        $http->assertStatus(500);
        $this->assertSame(2, count($this->service()->activeChildIdsForParent($parent->id)));
    }

    public function testConcurrentBindOfSameChildIsPreventedByUniqueIndex()
    {
        $this->enableSubAccount(5);
        $parent = $this->makeParent(['email' => 'concurrent-parent@example.com']);
        $child = $this->makeUser(['email' => 'concurrent-child@example.com']);

        // 模拟并发: 两条关系插入竞争同一 child_user_id
        $this->makeRelation($parent, $child);
        $this->assertSame(1, SubAccountRelation::where('child_user_id', $child->id)->count());

        $service = $this->service();
        try {
            $service->bind($parent, [
                'email' => $child->email,
                'code' => $this->seedBindCode($parent, $child->email),
            ], '127.0.0.1');
            $this->fail('并发/重复绑定必须失败');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            $this->assertSame(500, $e->getStatusCode());
        }
        $this->assertSame(1, SubAccountRelation::where('child_user_id', $child->id)->count());
    }

    // ----------------------------------------------------------- 覆盖点 10

    public function testChangePasswordIsOwnerScopedAndSessionInvalidationIsExact()
    {
        $this->enableSubAccount();
        $parent = $this->makeParent(['email' => 'pwd-owner-parent@example.com']);
        $otherParent = $this->makeParent(['email' => 'pwd-other-parent@example.com']);
        $child = $this->makeUser(['email' => 'pwd-owner-child@example.com']);
        $relation = $this->bindSubAccount($parent, $child->email);
        $child = User::find($child->id);

        // 子账号有两个会话
        $authService = new \App\Services\AuthService($child);
        $authService->generateAuthData(\Illuminate\Http\Request::create('/', 'GET'));
        $authService->generateAuthData(\Illuminate\Http\Request::create('/', 'GET'));
        $sessionsKey = CacheKey::get('USER_SESSIONS', $child->id);
        $this->assertCount(2, (array)Cache::get($sessionsKey));

        // 另一个主账号不能改这个密码
        $this->postJson('/api/v1/user/sub-account/change-password', [
            'auth_data' => $this->authDataFor($otherParent),
            'id' => $relation->id,
            'password' => 'shouldnotapply1',
        ])->assertStatus(403);
        $this->assertFalse(password_verify('shouldnotapply1', User::find($child->id)->password), '越权尝试不得修改密码');
        $this->assertCount(2, (array)Cache::get($sessionsKey), '越权尝试不得清空会话');

        // 真实主账号改密码
        $this->postJson('/api/v1/user/sub-account/change-password', [
            'auth_data' => $this->authDataFor($parent),
            'id' => $relation->id,
            'password' => 'brandnewpass1',
        ])->assertStatus(200);

        $after = User::find($child->id);
        $this->assertTrue(password_verify('brandnewpass1', $after->password));
        $this->assertFalse(Cache::has($sessionsKey), '改密码必须清空子账号全部会话');
        $this->assertNull($after->password_algo);
        $this->assertNull($after->password_salt);
    }

    // ----------------------------------------------------------- 覆盖点 19

    public function testPackagePeriodResetClearsParentAndEnabledChildrenOnly()
    {
        $this->enableSubAccount();
        $plan = $this->makePlan(['reset_traffic_method' => 0]);

        $parent = $this->makeParent([
            'email' => 'reset-parent@example.com', 'plan_id' => $plan->id, 'u' => 10, 'd' => 20,
        ]);
        $enabledChild = $this->makeUser([
            'email' => 'reset-enabled-child@example.com', 'plan_id' => $plan->id,
            'expired_at' => time() + 86400, 'u' => 30, 'd' => 40,
        ]);
        $disabledChild = $this->makeUser([
            'email' => 'reset-disabled-child@example.com', 'plan_id' => $plan->id,
            'expired_at' => time() + 86400, 'u' => 50, 'd' => 60,
        ]);
        $unrelated = $this->makeUser([
            'email' => 'reset-unrelated@example.com', 'plan_id' => $plan->id,
            'expired_at' => time() + 86400, 'u' => 70, 'd' => 80,
        ]);
        $this->makeRelation($parent, $enabledChild);
        $this->makeRelation($parent, $disabledChild, ['status' => SubAccountRelation::STATUS_DISABLED]);

        $this->invokeResetUsers([(int)$parent->id]);

        // 主账号与启用中的子账号被清零
        $this->assertSame(0, (int)User::find($parent->id)->u);
        $this->assertSame(0, (int)User::find($parent->id)->d);
        $this->assertSame(0, (int)User::find($enabledChild->id)->u, '启用中的子账号必须随主账号一起清零');
        $this->assertSame(0, (int)User::find($enabledChild->id)->d);

        // 停用子账号与无关联用户不受影响
        $this->assertSame(50, (int)User::find($disabledChild->id)->u, '停用子账号不得被清零');
        $this->assertSame(60, (int)User::find($disabledChild->id)->d);
        $this->assertSame(70, (int)User::find($unrelated->id)->u, '无关用户不得被清零');
        $this->assertSame(80, (int)User::find($unrelated->id)->d);
    }

    /**
     * reset:traffic 的日期闸门基于 date('d')（不受 Carbon::setTestNow 影响），
     * 因此这里只做命令级冒烟测试: 命令必须能正常跑完，且必须把主账号与启用中的
     * 子账号一起处理（每个 user 独立 SQL 更新，单独任一条失败都会抛出）。
     * 精确的"父+启用子清零、停用子不动"语义由上一个用例（反射调用 resetUsers）覆盖。
     */
    public function testPackagePeriodResetCommandRunsAndIsExposedByArtisan()
    {
        if (!$this->redisIsAvailable()) {
            $this->markTestSkipped('Redis 不可用: reset:traffic 依赖 Redis 锁（redis.setex/del），跳过命令级用例。');
        }
        $this->enableSubAccount();
        $plan = $this->makePlan(['reset_traffic_method' => 0]);

        $parent = $this->makeParent([
            'email' => 'artisan-reset-parent@example.com', 'plan_id' => $plan->id, 'u' => 11, 'd' => 22,
        ]);
        $enabledChild = $this->makeUser([
            'email' => 'artisan-reset-child@example.com', 'plan_id' => $plan->id,
            'expired_at' => time() + 86400, 'u' => 33, 'd' => 44,
        ]);
        $disabledChild = $this->makeUser([
            'email' => 'artisan-reset-disabled@example.com', 'plan_id' => $plan->id,
            'expired_at' => time() + 86400, 'u' => 55, 'd' => 66,
        ]);
        $this->makeRelation($parent, $enabledChild);
        $this->makeRelation($parent, $disabledChild, ['status' => SubAccountRelation::STATUS_DISABLED]);

        $exit = Artisan::call('reset:traffic');
        $this->assertSame(0, $exit, Artisan::output());

        // 主账号与启用中的子账号必须处于同一重置集合内:
        // 二者要么都被清零（今天是重置日），要么都保持原值（今天不是重置日）。
        $parentAfter = (int)User::find($parent->id)->u;
        $enabledAfter = (int)User::find($enabledChild->id)->u;
        $this->assertSame(
            $parentAfter === 0,
            $enabledAfter === 0,
            '主账号与启用中的子账号必须同进同退（同一事务/同一重置集合）'
        );

        // 停用子账号不参与主账号套餐周期重置
        $this->assertSame(55, (int)User::find($disabledChild->id)->u, '停用子账号不得被套餐周期重置清零');
        $this->assertSame(66, (int)User::find($disabledChild->id)->d);
    }

    // ----------------------------------------------------------- 覆盖点 20

    public function testManualSubAccountTrafficResetClearsOnlyChild()
    {
        $this->enableSubAccount();
        $parent = $this->makeParent(['email' => 'manual-parent@example.com', 'u' => 900, 'd' => 100]);
        $child = $this->makeUser(['email' => 'manual-child@example.com']);
        $relation = $this->bindSubAccount($parent, $child->email);
        // 绑定后子账号才产生个人用量（绑定校验拒绝带流量的候选账号）
        $this->seedChildTraffic($child, 500, 600);

        $this->postJson('/api/v1/user/sub-account/reset-traffic', [
            'auth_data' => $this->authDataFor($parent),
            'id' => $relation->id,
        ])->assertStatus(200);

        $childAfter = User::find($child->id);
        $this->assertSame(0, (int)$childAfter->u, '子账号 u 必须清零');
        $this->assertSame(0, (int)$childAfter->d, '子账号 d 必须清零');

        $parentAfter = User::find($parent->id);
        $this->assertSame(900, (int)$parentAfter->u, '不得回退主账号已累计用量');
        $this->assertSame(100, (int)$parentAfter->d);

        // 审计记录了清零前的值
        $logs = $this->auditLogs(SubAccountService::ACTION_RESET_TRAFFIC);
        $this->assertNotEmpty($logs);
        $this->assertSame(500, (int)$logs[0]->metadata['before']['u']);
        $this->assertSame(600, (int)$logs[0]->metadata['before']['d']);
    }

    public function testManualResetTrafficIsOwnerScoped()
    {
        $this->enableSubAccount();
        $parent = $this->makeParent(['email' => 'manual-owner-a@example.com']);
        $otherParent = $this->makeParent(['email' => 'manual-owner-b@example.com']);
        $child = $this->makeUser(['email' => 'manual-owner-child@example.com']);
        $relation = $this->bindSubAccount($parent, $child->email);
        // 绑定后子账号才产生个人用量（绑定校验拒绝带流量的候选账号）
        $this->seedChildTraffic($child, 1, 2);

        $this->postJson('/api/v1/user/sub-account/reset-traffic', [
            'auth_data' => $this->authDataFor($otherParent),
            'id' => $relation->id,
        ])->assertStatus(403);

        $this->assertSame(1, (int)User::find($child->id)->u);
        $this->assertSame(2, (int)User::find($child->id)->d);
    }

    // ----------------------------------------------------------- 覆盖点 21

    public function testCheckRenewalSkipsEnabledSubAccountsButNotNormalUsers()
    {
        $this->enableSubAccount();
        $plan = $this->makePlan(['renew' => 1, 'month_price' => 100]);

        // 启用中的子账号: 满足所有自动续费条件
        $parent = $this->makeParent(['email' => 'renew-parent@example.com']);
        $child = $this->makeUser([
            'email' => 'renew-child@example.com',
            'plan_id' => $plan->id,
            'expired_at' => time() + 3600, // 2 天内到期
            'auto_renewal' => 1,
            'balance' => 1000,
        ]);
        $this->makeRelation($parent, $child);
        $this->makeCompletedOrder($child, $plan, 'month_price');
        $childExpiredBefore = (int)User::find($child->id)->expired_at;

        // 普通用户（对照组）: 应被正常续费，证明命令确实执行过
        $normal = $this->makeUser([
            'email' => 'renew-normal@example.com',
            'plan_id' => $plan->id,
            'expired_at' => time() + 3600,
            'auto_renewal' => 1,
            'balance' => 1000,
        ]);
        $this->makeCompletedOrder($normal, $plan, 'month_price');

        $exit = Artisan::call('check:renewal');
        $this->assertSame(0, $exit, Artisan::output());

        // 子账号: 无订单、余额不变、到期时间不变
        $this->assertSame(0, DB::table('v2_order')->where('user_id', $child->id)->where('type', 2)->count(), '子账号不得创建续费订单');
        $childAfter = User::find($child->id);
        $this->assertSame(1000, (int)$childAfter->balance, '子账号余额不得变化');
        $this->assertSame($childExpiredBefore, (int)$childAfter->expired_at, '子账号到期时间不得变化');
        $this->assertSame(1, (int)$childAfter->auto_renewal, '子账号自动续费开关不得被关闭');

        // 对照组行为证明命令确实跑了
        $this->assertSame(1, DB::table('v2_order')->where('user_id', $normal->id)->where('type', 2)->count(), '普通用户应被续费');
        $this->assertSame(900, (int)User::find($normal->id)->balance);
    }

    public function testCheckRenewalIgnoresSubAccountsWhenFeatureDisabled()
    {
        $plan = $this->makePlan(['renew' => 1, 'month_price' => 100]);
        $parent = $this->makeParent(['email' => 'renew-off-parent@example.com']);
        $child = $this->makeUser([
            'email' => 'renew-off-child@example.com',
            'plan_id' => $plan->id,
            'expired_at' => time() + 3600,
            'auto_renewal' => 1,
            'balance' => 1000,
        ]);
        $this->makeRelation($parent, $child);
        $this->makeCompletedOrder($child, $plan, 'month_price');

        $this->disableSubAccount();
        Artisan::call('check:renewal');

        // 功能关闭时不存在"子账号"概念 => 按普通用户处理
        $this->assertSame(1, DB::table('v2_order')->where('user_id', $child->id)->where('type', 2)->count());
    }

    // ----------------------------------------------------------- 覆盖点 22

    public function testSendRemindMailSkipsEnabledSubAccounts()
    {
        $this->enableSubAccount();
        $parent = $this->makeParent(['email' => 'remind-parent@example.com']);

        // 子账号: remind_expire=1 且 1 小时内到期（若不跳过就会发信）
        $child = $this->makeUser([
            'email' => 'remind-child@example.com',
            'expired_at' => time() + 3600,
            'remind_expire' => 1,
            'remind_traffic' => 1,
            'transfer_enable' => 100,
            'u' => 96,
            'd' => 0,
        ]);
        $this->makeRelation($parent, $child);

        // 普通用户对照组
        $normal = $this->makeUser([
            'email' => 'remind-normal@example.com',
            'expired_at' => time() + 3600,
            'remind_expire' => 1,
            'remind_traffic' => 1,
            'transfer_enable' => 100,
            'u' => 96,
            'd' => 0,
        ]);

        Queue::fake();
        Bus::fake();
        $exit = Artisan::call('send:remindMail');
        $this->assertSame(0, $exit, Artisan::output());

        $emails = [];
        foreach ($this->dispatchedEmailJobs() as $job) {
            $params = $this->readJobProperty($job, 'params');
            if (is_array($params) && isset($params['email'])) {
                $emails[] = $params['email'];
            }
        }

        $this->assertNotContains($child->email, $emails, '启用中的子账号不得收到任何提醒邮件');
        $this->assertContains($normal->email, $emails, '普通用户仍应收到提醒邮件（证明命令执行过）');
    }

    public function testSendRemindMailRemindsChildWhenFeatureDisabled()
    {
        $parent = $this->makeParent(['email' => 'remind-off-parent@example.com']);
        $child = $this->makeUser([
            'email' => 'remind-off-child@example.com',
            'expired_at' => time() + 3600,
            'remind_expire' => 1,
            'remind_traffic' => 0,
        ]);
        $this->makeRelation($parent, $child);

        $this->disableSubAccount();
        Queue::fake();
        Bus::fake();
        Artisan::call('send:remindMail');

        $emails = [];
        foreach ($this->dispatchedEmailJobs() as $job) {
            $params = $this->readJobProperty($job, 'params');
            if (is_array($params) && isset($params['email'])) {
                $emails[] = $params['email'];
            }
        }
        $this->assertContains($child->email, $emails);
    }

    // ----------------------------------------------------------- 覆盖点 25

    public function testAuditLogIsWrittenForInternalSystemActions()
    {
        $this->enableSubAccount();
        $parent = $this->makeParent(['email' => 'system-audit-parent@example.com']);
        $child = $this->makeUser(['email' => 'system-audit-child@example.com']);
        $relation = $this->makeRelation($parent, $child);

        $this->service()->audit(SubAccountService::ACTION_ORPHAN_DEACTIVATE, [
            'relation_id' => $relation->id,
            'parent_user_id' => $parent->id,
            'child_user_id' => $child->id,
            'metadata' => ['reason' => 'orphan'],
        ]);

        $logs = $this->auditLogs(SubAccountService::ACTION_ORPHAN_DEACTIVATE);
        $this->assertNotEmpty($logs);
        $log = $logs[0];
        $this->assertSame(SubAccountAuditLog::ACTOR_SYSTEM, $log->actor_type);
        $this->assertNull($log->actor_user_id);
        $this->assertSame('orphan', $log->metadata['reason']);
        $this->assertNotNull($log->created_at);
    }

    // ---------------------------------------------------------------- 辅助

    protected function invokeResetUsers(array $ids): void
    {
        $command = new ResetTraffic();
        $reflection = new \ReflectionMethod($command, 'resetUsers');
        $reflection->setAccessible(true);
        $reflection->invoke($command, $ids);
    }

    protected function makePlan(array $attributes = []): Plan
    {
        $plan = new Plan();
        $plan->group_id = isset($attributes['group_id']) ? $attributes['group_id'] : 1;
        // v2_plan.transfer_enable 以 GB 为单位（OrderService 会 *1073741824 写入
        // v2_user.transfer_enable），且该列是 int(11)，绝不能写入字节数。
        $plan->transfer_enable = isset($attributes['transfer_enable']) ? $attributes['transfer_enable'] : 100;
        $plan->device_limit = isset($attributes['device_limit']) ? $attributes['device_limit'] : 3;
        $plan->name = isset($attributes['name']) ? $attributes['name'] : 'Test Plan ' . uniqid();
        $plan->speed_limit = isset($attributes['speed_limit']) ? $attributes['speed_limit'] : 100;
        $plan->show = isset($attributes['show']) ? $attributes['show'] : 1;
        $plan->sort = isset($attributes['sort']) ? $attributes['sort'] : 1;
        $plan->renew = isset($attributes['renew']) ? $attributes['renew'] : 1;
        $plan->content = isset($attributes['content']) ? $attributes['content'] : 'test';
        $plan->month_price = isset($attributes['month_price']) ? $attributes['month_price'] : 100;
        $plan->quarter_price = null;
        $plan->half_year_price = null;
        $plan->year_price = null;
        $plan->two_year_price = null;
        $plan->three_year_price = null;
        $plan->onetime_price = null;
        $plan->reset_price = null;
        $plan->reset_traffic_method = array_key_exists('reset_traffic_method', $attributes)
            ? $attributes['reset_traffic_method']
            : 2;
        $plan->capacity_limit = null;
        $plan->created_at = time();
        $plan->updated_at = time();
        $plan->save();

        return $plan->refresh();
    }

    protected function makeCompletedOrder(User $user, Plan $plan, string $period): void
    {
        DB::table('v2_order')->insert([
            'invite_user_id' => null,
            'user_id' => $user->id,
            'plan_id' => $plan->id,
            'coupon_id' => null,
            'payment_id' => null,
            'type' => 1,
            'period' => $period,
            'trade_no' => 'ORD' . uniqid(),
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
    }

    /**
     * SendEmailJob 的队列参数是 protected $params。
     *
     * SendEmailJob 通过 Dispatchable trait 经 Bus 派发，因此必须用 Bus::fake()
     * 拦截；BusFake 把已派发实例记录在 protected $commands[类名] 中。
     */
    protected function dispatchedEmailJobs(): array
    {
        $jobs = [];
        try {
            $jobs = array_merge(
                $jobs,
                \Illuminate\Support\Facades\Bus::dispatched(SendEmailJob::class)->all()
            );
        } catch (\Throwable $e) {
            // 退化为反射读取（兼容不同 Laravel 版本）
        }
        foreach ([\Illuminate\Support\Facades\Bus::class, \Illuminate\Support\Facades\Queue::class] as $facade) {
            try {
                $dispatcher = $facade::getFacadeRoot();
                if (!$dispatcher) continue;
                $reflection = new \ReflectionObject($dispatcher);
                foreach (['commands', 'dispatched'] as $property) {
                    if (!$reflection->hasProperty($property)) continue;
                    $prop = $reflection->getProperty($property);
                    $prop->setAccessible(true);
                    $recorded = (array)$prop->getValue($dispatcher);
                    foreach ($recorded as $value) {
                        if ($value instanceof SendEmailJob) {
                            $jobs[] = $value;
                        }
                    }
                }
            } catch (\Throwable $e) {
                continue;
            }
        }
        return $jobs;
    }

    protected function readJobProperty($job, string $property)
    {
        try {
            $reflection = new \ReflectionObject($job);
            while ($reflection && !$reflection->hasProperty($property)) {
                $reflection = $reflection->getParentClass();
            }
            if (!$reflection) return null;
            $prop = $reflection->getProperty($property);
            $prop->setAccessible(true);
            return $prop->getValue($job);
        } catch (\Throwable $e) {
            return null;
        }
    }
}
