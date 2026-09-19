<?php

namespace Tests\Feature;

use App\Jobs\StatUserJob;
use App\Jobs\TrafficFetchJob;
use App\Models\ServerAnytls;
use App\Models\ServerHysteria;
use App\Models\ServerShadowsocks;
use App\Models\ServerTrojan;
use App\Models\ServerTuic;
use App\Models\ServerV2node;
use App\Models\ServerVless;
use App\Models\ServerVmess;
use App\Models\SubAccountRelation;
use App\Models\User;
use App\Services\ServerService;
use App\Services\SubAccountEntitlement;
use App\Services\SubAccountService;
use App\Services\UserService;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Redis;

/**
 * 流量聚合、节点下发、有效授权（继承）测试。
 *
 * 覆盖点: 1、2、13、14、15、16、17、18。
 */
class SubAccountTrafficTest extends SubAccountTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (!$this->tableExists('v2_user_sub_accounts')) {
            \Illuminate\Support\Facades\Artisan::call('sub-account:install --apply');
        }
    }

    // ------------------------------------------------------------ 覆盖点 2

    public function testForNormalUserMirrorsOwnFieldsAndIsAvailableSemantics()
    {
        $this->enableSubAccount();
        $user = $this->makeUser([
            'email' => 'normal-user@example.com',
            'plan_id' => 1,
            'group_id' => 3,
            'expired_at' => time() + 86400,
            'speed_limit' => 42,
            'device_limit' => 7,
            'transfer_enable' => 1000,
            'u' => 100,
            'd' => 50,
        ]);

        $entitlement = SubAccountEntitlement::forNormalUser($user);
        $this->assertFalse($entitlement->isSubAccount());
        $this->assertNull($entitlement->getParentUser());
        $this->assertNull($entitlement->getRelation());
        $this->assertTrue($entitlement->isRelationEnabled());

        // 恰好是自身字段
        $this->assertSame($user->plan_id, $entitlement->getEffectivePlanId());
        $this->assertSame($user->group_id, $entitlement->getEffectiveGroupId());
        $this->assertSame($user->expired_at, $entitlement->getEffectiveExpiredAt());
        $this->assertSame($user->speed_limit, $entitlement->getEffectiveSpeedLimit());
        $this->assertSame($user->device_limit, $entitlement->getEffectiveDeviceLimit());
        $this->assertSame((int)$user->transfer_enable, $entitlement->getChildTrafficLimit());
        $this->assertSame(150, $entitlement->getChildUsed());
        $this->assertSame(150, $entitlement->getParentUsed());
        $this->assertSame((int)$user->transfer_enable, $entitlement->getParentTransferEnable());

        // service 入口在功能开启/关闭下都返回同一语义
        $this->assertInstanceOf(SubAccountEntitlement::class, $this->service()->resolveEntitlement($user));
        $this->assertFalse($this->service()->resolveEntitlement($user)->isSubAccount());
        $this->disableSubAccount();
        $this->assertFalse($this->service()->resolveEntitlement($user)->isSubAccount());

        // canConnect 与改动前的 UserService::isAvailable 语义一致
        $userService = new UserService();
        $cases = [
            // [属性覆盖, 说明]
            [[], 'active user'],
            [['banned' => 1], 'banned'],
            [['transfer_enable' => 0, 'u' => 0, 'd' => 0], 'zero transfer_enable'],
            [['u' => 950, 'd' => 50], 'quota reached'],
            [['u' => 949, 'd' => 50], 'quota almost reached'],
            [['expired_at' => time() + 10], 'not expired'],
            [['expired_at' => time() - 10], 'expired'],
            [['plan_id' => null], 'no plan'],
        ];
        foreach ($cases as $case) {
            $probe = $this->makeUser(array_merge([
                'email' => 'probe-' . uniqid('', true) . '@example.com',
                'plan_id' => 1,
                'group_id' => 1,
                'expired_at' => time() + 86400,
                'transfer_enable' => 1000,
                'u' => 100,
                'd' => 50,
            ], $case[0]));
            $probe = User::find($probe->id);
            $legacyAvailable = (int)(bool)$userService->isAvailable($probe);
            $this->assertSame(
                $legacyAvailable,
                (int)(bool)SubAccountEntitlement::forNormalUser($probe)->canConnect(),
                '普通用户 canConnect 必须等价于 isAvailable（' . $case[1] . '）'
            );
            $this->assertSame(
                $legacyAvailable,
                (int)(bool)$this->service()->resolveEntitlement($probe)->canConnect(),
                'service 入口的普通用户授权必须等价于 isAvailable（' . $case[1] . '）'
            );
        }
    }

    public function testGetAvailableUsersReturnsSameNormalUsersRegardlessOfFeatureToggle()
    {
        $normalA1 = $this->makeUser([
            'email' => 'normal-a1@example.com', 'group_id' => 1, 'plan_id' => 1,
            'expired_at' => time() + 86400, 'transfer_enable' => 1000, 'u' => 1, 'd' => 1,
        ]);
        $normalA2 = $this->makeUser([
            'email' => 'normal-a2@example.com', 'group_id' => 1, 'plan_id' => 1,
            'expired_at' => time() + 86400, 'transfer_enable' => 1000, 'u' => 1, 'd' => 1,
        ]);
        $normalB = $this->makeUser([
            'email' => 'normal-b@example.com', 'group_id' => 2, 'plan_id' => 1,
            'expired_at' => time() + 86400, 'transfer_enable' => 1000, 'u' => 1, 'd' => 1,
        ]);
        $exhausted = $this->makeUser([
            'email' => 'normal-exhausted@example.com', 'group_id' => 1, 'plan_id' => 1,
            'expired_at' => time() + 86400, 'transfer_enable' => 1000, 'u' => 1000, 'd' => 0,
        ]);
        $expired = $this->makeUser([
            'email' => 'normal-expired@example.com', 'group_id' => 1, 'plan_id' => 1,
            'expired_at' => time() - 100, 'transfer_enable' => 1000, 'u' => 0, 'd' => 0,
        ]);
        $banned = $this->makeUser([
            'email' => 'normal-banned@example.com', 'group_id' => 1, 'plan_id' => 1,
            'expired_at' => time() + 86400, 'transfer_enable' => 1000, 'u' => 0, 'd' => 0, 'banned' => 1,
        ]);

        $serverService = new ServerService();

        $this->disableSubAccount();
        $beforeGroup1 = $serverService->getAvailableUsers('1');
        $beforeGroup2 = $serverService->getAvailableUsers('2');

        $this->enableSubAccount();
        $afterGroup1 = $serverService->getAvailableUsers('1');
        $afterGroup2 = $serverService->getAvailableUsers('2');

        $ids = function ($users) {
            $ids = [];
            foreach ($users as $user) {
                $ids[] = (int)$user->id;
            }
            sort($ids);
            return $ids;
        };
        $uuids = function ($users) {
            $map = [];
            foreach ($users as $user) {
                $map[(int)$user->id] = $user->uuid;
            }
            ksort($map);
            return $map;
        };

        $this->assertSame($ids($beforeGroup1), $ids($afterGroup1), '无子账号时启用功能不得改变普通用户集合');
        $this->assertSame($uuids($beforeGroup1), $uuids($afterGroup1));

        $expectedGroup1 = [$normalA1->id, $normalA2->id];
        sort($expectedGroup1);
        $this->assertSame($expectedGroup1, $ids($afterGroup1));

        $this->assertSame([(int)$normalB->id], $ids($afterGroup2));

        // 被排除的普通用户
        foreach ([$exhausted->id, $expired->id, $banned->id] as $excludedId) {
            $this->assertNotContains((int)$excludedId, $ids($afterGroup1));
        }
    }

    // ----------------------------------------------------------- 覆盖点 13

    /**
     * @dataProvider protocolProvider
     */
    public function testSubAccountInheritsParentGroupForAllProtocols(string $protocol, string $modelClass, array $extra)
    {
        $this->enableSubAccount();

        $parent = $this->makeParent([
            'email' => 'group-parent-' . $protocol . '@example.com',
            'group_id' => 11,
            'transfer_enable' => 107374182400,
            'expired_at' => time() + 86400,
        ]);
        $child = $this->makeUser([
            'email' => 'group-child-' . $protocol . '@example.com',
            'group_id' => null, // 子账号自身没有权限组
        ]);
        $this->makeRelation($parent, $child, ['traffic_limit' => 0]);

        $parentServer = $this->makeServer($modelClass, $extra, 11, 'parent-group-' . $protocol, 1);
        $otherServer = $this->makeServer($modelClass, $extra, 22, 'other-group-' . $protocol, 2);

        // 前置: 子账号自身 group_id 为 NULL
        $this->assertNull(User::find($child->id)->group_id);

        // 授权解析: 继承主账号 group
        $entitlement = $this->service()->resolveEntitlement(User::find($child->id));
        $this->assertTrue($entitlement->isSubAccount());
        $this->assertSame(11, (int)$entitlement->getEffectiveGroupId());
        $this->assertSame((int)$parent->plan_id, (int)$entitlement->getEffectivePlanId());
        $this->assertSame((int)$parent->speed_limit, (int)$entitlement->getEffectiveSpeedLimit());
        $this->assertSame((int)$parent->device_limit, (int)$entitlement->getEffectiveDeviceLimit());

        $serverService = new ServerService();

        // 只返回主账号所在组的节点
        $servers = $serverService->getAvailableServers(User::find($child->id));
        $ids = array_map(function ($server) { return (int)$server['id']; }, $servers);
        $this->assertContains((int)$parentServer->id, $ids, "{$protocol}: 应下发主账号组的节点");
        $this->assertNotContains((int)$otherServer->id, $ids, "{$protocol}: 不得下发其它组的节点");

        // 单协议 getter 带显式 groupId 时行为一致
        $byGroup = $serverService->{'getAvailable' . $this->getterSuffix($protocol)}(User::find($child->id), 11);
        $groupIds = array_map(function ($server) { return (int)$server['id']; }, $byGroup);
        $this->assertContains((int)$parentServer->id, $groupIds);
        $this->assertNotContains((int)$otherServer->id, $groupIds);

        // 子账号自身 group_id 为 NULL 时，直连不应拿到任何组节点
        $withNullGroup = $serverService->{'getAvailable' . $this->getterSuffix($protocol)}(User::find($child->id), null);
        $this->assertSame([], array_filter($withNullGroup, function ($server) use ($parentServer, $otherServer) {
            return in_array((int)$server['id'], [(int)$parentServer->id, (int)$otherServer->id], true);
        }));
    }

    public function protocolProvider(): array
    {
        return [
            'shadowsocks' => ['shadowsocks', ServerShadowsocks::class, [
                'cipher' => 'aes-256-gcm', 'obfs' => null, 'obfs_settings' => null,
            ]],
            'vmess' => ['vmess', ServerVmess::class, [
                'tls' => 0, 'network' => 'tcp',
            ]],
            'trojan' => ['trojan', ServerTrojan::class, [
                'allow_insecure' => 0, 'network' => 'tcp',
            ]],
            'tuic' => ['tuic', ServerTuic::class, [
                'server_name' => 'example.com', 'insecure' => 0,
            ]],
            'hysteria' => ['hysteria', ServerHysteria::class, [
                'version' => 2, 'up_mbps' => 100, 'down_mbps' => 100,
            ]],
            'vless' => ['vless', ServerVless::class, [
                'tls' => 0, 'network' => 'tcp',
            ]],
            'anytls' => ['anytls', ServerAnytls::class, [
                'server_name' => 'example.com', 'insecure' => 0,
            ]],
            'v2node' => ['v2node', ServerV2node::class, [
                'protocol' => 'vmess', 'tls' => 0, 'network' => 'tcp',
                'up_mbps' => 100, 'down_mbps' => 100,
            ]],
        ];
    }

    public function testDisabledAndOrphanRelationsFallBackToOwnGroup()
    {
        $this->enableSubAccount();
        $parent = $this->makeParent(['email' => 'fallback-parent@example.com', 'group_id' => 11]);
        $child = $this->makeUser(['email' => 'fallback-child@example.com', 'group_id' => 22]);
        $this->makeRelation($parent, $child, ['status' => SubAccountRelation::STATUS_DISABLED]);

        $parentServer = $this->makeServer(ServerShadowsocks::class, ['cipher' => 'aes-256-gcm', 'obfs' => null, 'obfs_settings' => null], 11, 'fallback-parent-node', 1);
        $childGroupServer = $this->makeServer(ServerShadowsocks::class, ['cipher' => 'aes-256-gcm', 'obfs' => null, 'obfs_settings' => null], 22, 'fallback-child-node', 2);

        $entitlement = $this->service()->resolveEntitlement(User::find($child->id));
        $this->assertFalse($entitlement->isSubAccount(), '停用关系不得产生继承');
        $this->assertSame(22, (int)$entitlement->getEffectiveGroupId());

        $servers = (new ServerService())->getAvailableServers(User::find($child->id));
        $ids = array_map(function ($server) { return (int)$server['id']; }, $servers);
        $this->assertContains((int)$childGroupServer->id, $ids);
        $this->assertNotContains((int)$parentServer->id, $ids);
    }

    public function testFeatureDisabledNeverInheritsEvenWithEnabledRelation()
    {
        $parent = $this->makeParent(['email' => 'disabled-parent@example.com', 'group_id' => 11]);
        $child = $this->makeUser(['email' => 'disabled-child@example.com', 'group_id' => 22]);
        $this->makeRelation($parent, $child);

        $parentServer = $this->makeServer(ServerShadowsocks::class, ['cipher' => 'aes-256-gcm', 'obfs' => null, 'obfs_settings' => null], 11, 'disabled-parent-node', 1);
        $childGroupServer = $this->makeServer(ServerShadowsocks::class, ['cipher' => 'aes-256-gcm', 'obfs' => null, 'obfs_settings' => null], 22, 'disabled-child-node', 2);

        $this->disableSubAccount();

        $entitlement = $this->service()->resolveEntitlement(User::find($child->id));
        $this->assertFalse($entitlement->isSubAccount());
        $this->assertSame(22, (int)$entitlement->getEffectiveGroupId());

        $servers = (new ServerService())->getAvailableServers(User::find($child->id));
        $ids = array_map(function ($server) { return (int)$server['id']; }, $servers);
        $this->assertContains((int)$childGroupServer->id, $ids);
        $this->assertNotContains((int)$parentServer->id, $ids);
    }

    public function testNullGroupAndNoPlanSubAccountInheritsNothing()
    {
        $this->enableSubAccount();
        $parent = $this->makeParent(['email' => 'noplan-parent@example.com', 'group_id' => 11, 'plan_id' => null]);
        $child = $this->makeUser(['email' => 'noplan-child@example.com', 'group_id' => null]);
        $this->makeRelation($parent, $child);

        $server = $this->makeServer(ServerShadowsocks::class, ['cipher' => 'aes-256-gcm', 'obfs' => null, 'obfs_settings' => null], 11, 'noplan-node', 1);

        $entitlement = $this->service()->resolveEntitlement(User::find($child->id));
        $this->assertTrue($entitlement->isSubAccount());
        $this->assertSame(11, (int)$entitlement->getEffectiveGroupId(), 'group 仍然继承（是否可用由 canConnect 决定）');
        $this->assertFalse($entitlement->canConnect(), '主账号没有套餐 => 子账号不可连接');

        $servers = (new ServerService())->getAvailableServers(User::find($child->id));
        $this->assertContains((int)$server->id, array_map(function ($s) { return (int)$s['id']; }, $servers));
    }

    // ----------------------------------------------------------- 覆盖点 14

    public function testEnabledSubAccountAppearsInNodeUserListWithChildIdentityAndParentLimits()
    {
        $this->enableSubAccount();
        $parent = $this->makeParent([
            'email' => 'node-parent@example.com',
            'group_id' => 1,
            'speed_limit' => 55,
            'device_limit' => 6,
            'transfer_enable' => 100000,
        ]);
        $child = $this->makeUser([
            'email' => 'node-child@example.com',
            'group_id' => 1, // 与主账号同组，但自身没有任何套餐/额度
            'speed_limit' => 1,
            'device_limit' => 1,
            'transfer_enable' => 0,
        ]);
        $this->makeRelation($parent, $child);

        $users = (new ServerService())->getAvailableUsers('1');

        $found = null;
        $count = 0;
        foreach ($users as $user) {
            if ((int)$user->id === (int)$child->id) {
                $found = $user;
                $count++;
            }
        }
        $this->assertNotNull($found, '启用中的子账号必须出现在主账号组的节点用户列表中');
        $this->assertSame(1, $count, '子账号不得重复出现');
        $this->assertSame($child->uuid, $found->uuid, '必须使用子账号自己的 uuid');
        $this->assertSame((int)$parent->speed_limit, (int)$found->speed_limit, '必须继承主账号 speed_limit');
        $this->assertSame((int)$parent->device_limit, (int)$found->device_limit, '必须继承主账号 device_limit');

        // 主账号自身也应在列表中（普通用户路径）
        $parentIds = array_map(function ($user) { return (int)$user->id; }, $users->all());
        $this->assertContains((int)$parent->id, $parentIds);

        // 子账号只继承主账号的组，不会出现在其它组的节点用户列表中
        $groupTwo = (new ServerService())->getAvailableUsers('2');
        $groupTwoIds = array_map(function ($user) { return (int)$user->id; }, $groupTwo->all());
        $this->assertNotContains((int)$child->id, $groupTwoIds);
    }

    /**
     * @dataProvider exclusionProvider
     */
    public function testDisallowedSubAccountsAreExcludedFromNodeUserList(callable $mutate, string $reason, bool $expectParentVisible = true, bool $expectChildVisible = false)
    {
        $this->enableSubAccount();
        $parent = $this->makeParent([
            'email' => 'excl-parent-' . uniqid('', true) . '@example.com',
            'group_id' => 1,
            'transfer_enable' => 100000,
        ]);
        $child = $this->makeUser([
            'email' => 'excl-child-' . uniqid('', true) . '@example.com',
            'group_id' => 1,
            'transfer_enable' => 100000,
        ]);
        $relation = $this->makeRelation($parent, $child);

        $mutate($parent, $child, $relation);
        $parent = User::find($parent->id);
        $child = User::find($child->id);

        $users = (new ServerService())->getAvailableUsers('1');
        $ids = array_map(function ($user) { return (int)$user->id; }, $users->all());

        if ($expectChildVisible) {
            // 关系停用 => 子账号退回普通账号，按普通用户规则（自身组/额度/未过期/未封禁）
            // 独立判断，满足条件时就应当出现在列表里（需求书第十三节的排除项不含"关系停用"）。
            $this->assertContains((int)$child->id, $ids, $reason . ': 停用后应按普通用户规则返回');
        } else {
            $this->assertNotContains((int)$child->id, $ids, $reason . ': 子账号必须被排除');
        }
        if ($expectParentVisible) {
            $this->assertContains((int)$parent->id, $ids, $reason . ': 主账号应仍然可见');
        }
    }

    public function exclusionProvider(): array
    {
        return [
            'relation disabled' => [
                function (User $parent, User $child, SubAccountRelation $relation) {
                    $relation->status = SubAccountRelation::STATUS_DISABLED;
                    $relation->save();
                },
                '关系停用',
                true,
                true,
            ],
            'parent banned' => [
                function (User $parent) {
                    $parent->banned = 1;
                    $parent->save();
                },
                '主账号封禁',
                false,
            ],
            'child banned' => [
                function (User $parent, User $child) {
                    $child->banned = 1;
                    $child->save();
                },
                '子账号封禁',
            ],
            'child personal quota exceeded' => [
                function (User $parent, User $child, SubAccountRelation $relation) {
                    $relation->traffic_limit = 100;
                    $relation->save();
                    $child->u = 100;
                    $child->d = 0;
                    $child->save();
                },
                '子账号个人额度超限',
            ],
            'parent shared quota exceeded' => [
                function (User $parent, User $child) {
                    $parent->transfer_enable = 100;
                    $parent->u = 100;
                    $parent->d = 0;
                    $parent->save();
                },
                '主账号共享额度超限',
                false,
            ],
            'parent has no plan' => [
                function (User $parent) {
                    $parent->plan_id = null;
                    $parent->save();
                },
                '主账号无套餐',
            ],
            'parent expired' => [
                function (User $parent) {
                    $parent->expired_at = time() - 100;
                    $parent->save();
                },
                '主账号已过期',
                false,
            ],
        ];
    }

    public function testFeatureDisabledExcludesNothingFromNodeUserList()
    {
        $parent = $this->makeParent(['email' => 'off-parent@example.com', 'group_id' => 1]);
        $child = $this->makeUser(['email' => 'off-child@example.com', 'group_id' => 1, 'transfer_enable' => 100000]);
        $this->makeRelation($parent, $child);

        $this->disableSubAccount();
        $ids = array_map(function ($user) { return (int)$user->id; }, (new ServerService())->getAvailableUsers('1')->all());

        // 功能关闭: 子账号被当作普通用户处理（自身满足条件即返回），但绝不带上主账号的限制值
        $this->assertContains((int)$child->id, $ids);
        $found = null;
        foreach ((new ServerService())->getAvailableUsers('1') as $user) {
            if ((int)$user->id === (int)$child->id) $found = $user;
        }
        $this->assertNotNull($found);
        $this->assertSame((int)$child->speed_limit, (int)$found->speed_limit);
        $this->assertSame((int)$child->device_limit, (int)$found->device_limit);
        $this->assertSame($child->uuid, $found->uuid);
    }

    // ------------------------------------------------------ 覆盖点 15 / 16

    public function testPersonalQuotaControlsCanConnect()
    {
        $this->enableSubAccount();
        $parent = $this->makeParent([
            'email' => 'quota-parent@example.com',
            'transfer_enable' => 1000000,
        ]);

        $cases = [
            ['limit' => 100, 'u' => 0, 'd' => 0, 'expected' => true],
            ['limit' => 100, 'u' => 99, 'd' => 0, 'expected' => true],
            ['limit' => 100, 'u' => 100, 'd' => 0, 'expected' => false],
            ['limit' => 100, 'u' => 50, 'd' => 50, 'expected' => false],
            ['limit' => 100, 'u' => 1000, 'd' => 0, 'expected' => false],
            ['limit' => 0, 'u' => 999999, 'd' => 0, 'expected' => true], // 0 = 不设个人额度
        ];

        foreach ($cases as $case) {
            $child = $this->makeUser([
                'email' => 'quota-child-' . uniqid('', true) . '@example.com',
                'u' => $case['u'],
                'd' => $case['d'],
            ]);
            $relation = $this->makeRelation($parent, $child, ['traffic_limit' => $case['limit']]);

            $entitlement = $this->service()->resolveEntitlement(User::find($child->id));
            $this->assertTrue($entitlement->isSubAccount());
            $this->assertSame(
                $case['expected'],
                $entitlement->canConnect(),
                sprintf('limit=%d u=%d d=%d', $case['limit'], $case['u'], $case['d'])
            );
            $this->assertSame($case['expected'], $entitlement->isAvailable());
            $this->assertSame((int)$case['limit'], $entitlement->getChildTrafficLimit());

            $ids = array_map(function ($user) { return (int)$user->id; }, (new ServerService())->getAvailableUsers('1')->all());
            if ($case['expected']) {
                $this->assertContains((int)$child->id, $ids);
            } else {
                $this->assertNotContains((int)$child->id, $ids);
            }

            $relation->delete();
            $child->delete();
        }
    }

    public function testSharedQuotaControlsCanConnectAndNodeList()
    {
        $this->enableSubAccount();
        $parent = $this->makeParent([
            'email' => 'shared-parent@example.com',
            'transfer_enable' => 1000,
            'u' => 0,
            'd' => 0,
        ]);
        $child = $this->makeUser(['email' => 'shared-child@example.com']);
        $this->makeRelation($parent, $child, ['traffic_limit' => 0]);

        $entitlement = $this->service()->resolveEntitlement(User::find($child->id));
        $this->assertTrue($entitlement->canConnect());
        $this->assertContains(
            (int)$child->id,
            array_map(function ($user) { return (int)$user->id; }, (new ServerService())->getAvailableUsers('1')->all())
        );

        // 主账号自身用量打满共享额度
        $parent->u = 999;
        $parent->d = 1;
        $parent->save();
        $parent = User::find($parent->id);

        $entitlement = $this->service()->resolveEntitlement(User::find($child->id));
        $this->assertSame(1000, $entitlement->getParentUsed());
        $this->assertTrue($entitlement->isSubAccount());
        $this->assertTrue($entitlement->getChildTrafficLimit() === 0);
        $this->assertFalse($entitlement->canConnect(), '主账号共享额度耗尽 => 子账号不可连接');
        $this->assertNotContains(
            (int)$child->id,
            array_map(function ($user) { return (int)$user->id; }, (new ServerService())->getAvailableUsers('1')->all())
        );
    }

    // ----------------------------------------------------------- 覆盖点 17

    public function testTrafficFetchJobAggregatesChildTrafficIntoParent()
    {
        $this->enableSubAccount();
        $parent = $this->makeParent(['email' => 'traffic-parent@example.com']);
        $childOne = $this->makeUser(['email' => 'traffic-child-1@example.com']);
        $childTwo = $this->makeUser(['email' => 'traffic-child-2@example.com']);
        $this->makeRelation($parent, $childOne, ['traffic_limit' => 0]);
        $this->makeRelation($parent, $childTwo, ['traffic_limit' => 0]);

        // 批次: 主账号自身 + 两个子账号（rate=1 便于断言精确增量）
        $data = [
            (string)$parent->id => [1, 2],      // 主账号自身: u+1 d+2
            (string)$childOne->id => [3, 4],    // 子账号1: u+3 d+4
            (string)$childTwo->id => [5, 6],    // 子账号2: u+5 d+6
        ];
        $server = ['rate' => 1];

        if ($this->redisIsAvailable()) {
            $this->flushTrafficKeys();

            (new TrafficFetchJob($data, $server, 'shadowsocks'))->handle();

            $this->assertSame(1 + 3 + 5, (int)Redis::hget(TrafficFetchJob::UPLOAD_KEY, (string)$parent->id), '主账号上传 = 自身 + 全部子账号');
            $this->assertSame(2 + 4 + 6, (int)Redis::hget(TrafficFetchJob::DOWNLOAD_KEY, (string)$parent->id), '主账号下载 = 自身 + 全部子账号');
            $this->assertSame(3, (int)Redis::hget(TrafficFetchJob::UPLOAD_KEY, (string)$childOne->id));
            $this->assertSame(4, (int)Redis::hget(TrafficFetchJob::DOWNLOAD_KEY, (string)$childOne->id));
            $this->assertSame(5, (int)Redis::hget(TrafficFetchJob::UPLOAD_KEY, (string)$childTwo->id));
            $this->assertSame(6, (int)Redis::hget(TrafficFetchJob::DOWNLOAD_KEY, (string)$childTwo->id));

            $this->flushTrafficKeys();
        } else {
            // Redis 不可用: 用门面 mock 断言精确的 HINCRBY 调用序列与增量
            $this->expectRedisIncrements([
                [TrafficFetchJob::UPLOAD_KEY, (string)$parent->id, 1],
                [TrafficFetchJob::DOWNLOAD_KEY, (string)$parent->id, 2],
                [TrafficFetchJob::UPLOAD_KEY, (string)$childOne->id, 3],
                [TrafficFetchJob::DOWNLOAD_KEY, (string)$childOne->id, 4],
                [TrafficFetchJob::UPLOAD_KEY, (string)$childTwo->id, 5],
                [TrafficFetchJob::DOWNLOAD_KEY, (string)$childTwo->id, 6],
                [TrafficFetchJob::UPLOAD_KEY, (string)$parent->id, 8],
                [TrafficFetchJob::DOWNLOAD_KEY, (string)$parent->id, 10],
            ]);

            (new TrafficFetchJob($data, $server, 'shadowsocks'))->handle();
        }
    }

    public function testTrafficFetchJobAppliesRateMultiplier()
    {
        $this->enableSubAccount();
        $parent = $this->makeParent(['email' => 'rate-parent@example.com']);
        $child = $this->makeUser(['email' => 'rate-child@example.com']);
        $this->makeRelation($parent, $child);

        $data = [
            (string)$parent->id => [2, 3],
            (string)$child->id => [10, 20],
        ];
        $rate = 3;

        $this->expectRedisIncrements([
            [TrafficFetchJob::UPLOAD_KEY, (string)$parent->id, 2 * $rate],
            [TrafficFetchJob::DOWNLOAD_KEY, (string)$parent->id, 3 * $rate],
            [TrafficFetchJob::UPLOAD_KEY, (string)$child->id, 10 * $rate],
            [TrafficFetchJob::DOWNLOAD_KEY, (string)$child->id, 20 * $rate],
            [TrafficFetchJob::UPLOAD_KEY, (string)$parent->id, 10 * $rate],
            [TrafficFetchJob::DOWNLOAD_KEY, (string)$parent->id, 20 * $rate],
        ]);

        (new TrafficFetchJob($data, ['rate' => $rate], 'vmess'))->handle();
    }

    public function testTrafficFetchJobDoesNotAggregateWhenFeatureDisabled()
    {
        $this->disableSubAccount();
        $parent = $this->makeParent(['email' => 'off-traffic-parent@example.com']);
        $child = $this->makeUser(['email' => 'off-traffic-child@example.com']);
        $this->makeRelation($parent, $child);

        $data = [
            (string)$parent->id => [1, 1],
            (string)$child->id => [4, 4],
        ];

        // 功能关闭: 只有"按真实上报账号累加"的原行为，没有任何父账号聚合
        $this->expectRedisIncrements([
            [TrafficFetchJob::UPLOAD_KEY, (string)$parent->id, 1],
            [TrafficFetchJob::DOWNLOAD_KEY, (string)$parent->id, 1],
            [TrafficFetchJob::UPLOAD_KEY, (string)$child->id, 4],
            [TrafficFetchJob::DOWNLOAD_KEY, (string)$child->id, 4],
        ]);

        (new TrafficFetchJob($data, ['rate' => 1], 'shadowsocks'))->handle();
    }

    public function testTrafficFetchJobOnlyAggregatesEnabledRelations()
    {
        $this->enableSubAccount();
        $parent = $this->makeParent(['email' => 'enabled-only-parent@example.com']);
        $enabledChild = $this->makeUser(['email' => 'enabled-only-child@example.com']);
        $disabledChild = $this->makeUser(['email' => 'enabled-only-disabled@example.com']);
        $this->makeRelation($parent, $enabledChild);
        $this->makeRelation($parent, $disabledChild, ['status' => SubAccountRelation::STATUS_DISABLED]);

        $data = [
            (string)$parent->id => [1, 1],
            (string)$enabledChild->id => [2, 2],
            (string)$disabledChild->id => [8, 8],
        ];

        // 只有启用的子账号被聚合到主账号
        $this->expectRedisIncrements([
            [TrafficFetchJob::UPLOAD_KEY, (string)$parent->id, 1],
            [TrafficFetchJob::DOWNLOAD_KEY, (string)$parent->id, 1],
            [TrafficFetchJob::UPLOAD_KEY, (string)$enabledChild->id, 2],
            [TrafficFetchJob::DOWNLOAD_KEY, (string)$enabledChild->id, 2],
            [TrafficFetchJob::UPLOAD_KEY, (string)$disabledChild->id, 8],
            [TrafficFetchJob::DOWNLOAD_KEY, (string)$disabledChild->id, 8],
            [TrafficFetchJob::UPLOAD_KEY, (string)$parent->id, 2],
            [TrafficFetchJob::DOWNLOAD_KEY, (string)$parent->id, 2],
        ]);

        (new TrafficFetchJob($data, ['rate' => 1], 'shadowsocks'))->handle();
    }

    // ----------------------------------------------------------- 覆盖点 18

    public function testUserServiceTrafficFetchPassesOriginalDataToStatUserJob()
    {
        $this->enableSubAccount();
        $parent = $this->makeParent(['email' => 'stat-parent@example.com']);
        $childOne = $this->makeUser(['email' => 'stat-child-1@example.com']);
        $childTwo = $this->makeUser(['email' => 'stat-child-2@example.com']);
        $this->makeRelation($parent, $childOne);
        $this->makeRelation($parent, $childTwo);

        $data = [
            (string)$parent->id => [1, 2],
            (string)$childOne->id => [3, 4],
            (string)$childTwo->id => [5, 6],
        ];
        $server = ['rate' => 1, 'id' => 1];
        $protocol = 'shadowsocks';

        Bus::fake();

        (new UserService())->trafficFetch($server, $protocol, $data);

        Bus::assertDispatched(TrafficFetchJob::class, 1);
        Bus::assertDispatched(StatUserJob::class, 1);
        Bus::assertDispatched(\App\Jobs\StatServerJob::class, 1);

        $captured = null;
        foreach (Bus::dispatched(StatUserJob::class) as $job) {
            $captured = $this->readJobProperty($job, 'data');
            break;
        }
        $this->assertNotNull($captured, 'StatUserJob 必须被派发且可读取入参');

        // StatUserJob 必须拿到"原样的"上报数据: 不含主账号聚合后的增量，
        // 也不能出现父账号额外记录
        $this->assertSame($data, $captured, 'StatUserJob 入参必须与原始上报数据完全一致');
        $this->assertArrayNotHasKey('parent_aggregate', (array)$captured);
        $this->assertSame(
            [(string)$parent->id, (string)$childOne->id, (string)$childTwo->id],
            array_map('strval', array_keys($captured))
        );
        $this->assertSame([1, 2], $captured[(string)$parent->id], '主账号只记录自身流量');
        $this->assertSame([3, 4], $captured[(string)$childOne->id]);
        $this->assertSame([5, 6], $captured[(string)$childTwo->id]);
    }

    public function testStatUserJobOnlyReceivesActualReportingUsers()
    {
        $this->disableSubAccount();
        $parent = $this->makeParent(['email' => 'stat2-parent@example.com']);
        $child = $this->makeUser(['email' => 'stat2-child@example.com']);
        $this->makeRelation($parent, $child);

        // 只有子账号真实上报（主账号不在批次里）
        $data = [(string)$child->id => [7, 8]];
        Bus::fake();

        (new UserService())->trafficFetch(['rate' => 1, 'id' => 1], 'vmess', $data);

        $captured = null;
        foreach (Bus::dispatched(StatUserJob::class) as $job) {
            $captured = $this->readJobProperty($job, 'data');
            break;
        }
        $this->assertNotNull($captured, 'StatUserJob 必须被派发且可读取入参');
        $this->assertSame($data, $captured);
        // PHP 会把数字字符串下标规范化为 int，这里显式统一为字符串再比较
        $this->assertSame([(string)$child->id], array_map('strval', array_keys($captured)));
        $this->assertArrayNotHasKey((string)$parent->id, $captured, '不得把主账号聚合流量写入统计数据');
    }

    // ---------------------------------------------------------------- 辅助

    protected function getterSuffix(string $protocol): string
    {
        $map = [
            'shadowsocks' => 'Shadowsocks',
            'vmess' => 'Vmess',
            'trojan' => 'Trojan',
            'tuic' => 'Tuic',
            'hysteria' => 'Hysteria',
            'vless' => 'Vless',
            'anytls' => 'AnyTLS',
            'v2node' => 'V2node',
        ];
        return $map[$protocol];
    }

    protected function makeServer(string $modelClass, array $extra, int $groupId, string $name, int $sort)
    {
        $attributes = array_merge([
            'group_id' => [(string)$groupId],
            'route_id' => null,
            'name' => $name,
            'parent_id' => null,
            'host' => '127.0.0.1',
            'port' => '8388',
            'server_port' => 8388,
            'tags' => null,
            'rate' => '1',
            'show' => 1,
            'sort' => $sort,
            'created_at' => time(),
            'updated_at' => time(),
        ], $extra);

        /** @var \Illuminate\Database\Eloquent\Model $server */
        $server = new $modelClass();
        foreach ($attributes as $key => $value) {
            $server->{$key} = $value;
        }
        $server->save();

        return $server->refresh();
    }

    protected function redisIsAvailable(): bool
    {
        try {
            Redis::connection()->ping();
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    protected function flushTrafficKeys(): void
    {
        $keys = [TrafficFetchJob::UPLOAD_KEY, TrafficFetchJob::DOWNLOAD_KEY];
        foreach ($keys as $key) {
            $existing = Redis::hkeys($key);
            foreach ((array)$existing as $field) {
                Redis::hdel($key, $field);
            }
        }
    }

    /**
     * 用门面 mock 精确断言 HINCRBY 调用序列（Redis 不可用时的等价覆盖）。
     *
     * @param array $calls [[key, field, increment], ...]
     */
    protected function expectRedisIncrements(array $calls): void
    {
        foreach ($calls as $call) {
            Redis::shouldReceive('hincrby')
                ->once()
                ->with($call[0], $call[1], $call[2]);
        }
        Redis::shouldReceive('hincrby')->zeroOrMoreTimes();
    }

    protected function dispatchedJobs(): array
    {
        $jobs = [];
        foreach ([\Illuminate\Support\Facades\Bus::class, \Illuminate\Support\Facades\Queue::class] as $facade) {
            try {
                $dispatcher = $facade::getFacadeRoot();
            } catch (\Throwable $e) {
                continue;
            }
            if (!$dispatcher) continue;
            try {
                $reflection = new \ReflectionObject($dispatcher);
                if ($reflection->hasProperty('jobs')) {
                    $prop = $reflection->getProperty('jobs');
                    $prop->setAccessible(true);
                    $jobs = array_merge($jobs, (array)$prop->getValue($dispatcher));
                    continue;
                }
                if ($reflection->hasProperty('dispatched')) {
                    $prop = $reflection->getProperty('dispatched');
                    $prop->setAccessible(true);
                    $jobs = array_merge($jobs, (array)$prop->getValue($dispatcher));
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
