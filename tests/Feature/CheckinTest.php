<?php

namespace Tests\Feature;

use App\Models\CheckinReward;
use App\Models\SubAccountRelation;
use App\Models\User;
use App\Models\UserCheckinLog;
use App\Services\ThemeCheckinService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * 每日签到功能测试。
 *
 * 覆盖：EZ-Theme 契约字段、同日重复/并发只奖励一次、30 天循环与断签、
 * 子账号拒领、历史导入记录不重复计入、时区计日。
 */
class CheckinTest extends CheckinPromotionTestCase
{
    private function yesterday()
    {
        return $this->checkin()->now()->subDay()->toDateString();
    }

    private function twoDaysAgo()
    {
        return $this->checkin()->now()->subDays(2)->toDateString();
    }

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

    // ============================================================ 契约与默认值

    public function testDefaultRulesAreTheOriginalFourMilestones()
    {
        $rules = CheckinReward::where('enabled', 1)->orderBy('day_index')->get();
        $mapped = [];
        foreach ($rules as $rule) {
            $mapped[(int)$rule->day_index] = (int)$rule->reward_bytes;
        }
        $this->assertSame(524288000, $mapped[7] ?? null, '第 7 天应为 500MB');
        $this->assertSame(2147483648, $mapped[14] ?? null, '第 14 天应为 2GB');
        $this->assertSame(4294967296, $mapped[21] ?? null, '第 21 天应为 4GB');
        $this->assertSame(7516192768, $mapped[30] ?? null, '第 30 天应为 7GB');
    }

    public function testStatusPayloadMatchesThemeContract()
    {
        $user = $this->makeUser();
        $status = $this->checkin()->status($user);

        // 严格布尔
        $this->assertIsBool($status['enabled']);
        $this->assertTrue($status['enabled']);
        $this->assertIsBool($status['checked_in']);
        $this->assertFalse($status['checked_in']);
        $this->assertSame(0, $status['continuous_days']);

        // rewards[] 元素必须是 {days,title,reward_text,claimed}
        $this->assertIsArray($status['rewards']);
        $this->assertNotEmpty($status['rewards']);
        foreach ($status['rewards'] as $reward) {
            $this->assertArrayHasKey('days', $reward);
            $this->assertArrayHasKey('title', $reward);
            $this->assertArrayHasKey('reward_text', $reward);
            $this->assertArrayHasKey('claimed', $reward);
            $this->assertIsInt($reward['days']);
            $this->assertGreaterThan(0, $reward['days']);
            $this->assertIsBool($reward['claimed']);
        }
        $days = array_column($status['rewards'], 'days');

        // next_reward 必须是对象（裸数字会导致主题显示 undefined 天）
        $this->assertIsArray($status['next_reward']);
        $this->assertArrayHasKey('days', $status['next_reward']);
        $this->assertArrayHasKey('reward_text', $status['next_reward']);
        $this->assertSame(7, $status['next_reward']['days']);

        // month_days[] 元素的 date 必须是 YYYY-MM-DD 字符串
        $this->assertIsArray($status['month_days']);
        foreach ($status['month_days'] as $day) {
            $this->assertArrayHasKey('date', $day);
            $this->assertArrayHasKey('status', $day);
            $this->assertArrayHasKey('checked', $day);
            $this->assertIsString($day['date']);
            $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', $day['date']);
            $this->assertIsBool($day['checked']);
        }

        $this->assertIsInt($status['reward_bytes']);
        $this->assertIsString($status['reward_text']);
        $this->assertIsString($status['today_reward_text']);
    }

    public function testDisabledCheckinReportsEnabledFalse()
    {
        $this->disableCheckin();
        $user = $this->makeUser();
        $status = $this->checkin()->status($user);
        $this->assertFalse($status['enabled'], 'enabled 必须是严格 false（0 不会关闭主题界面）');
    }

    public function testClaimIsRejectedWhenFeatureDisabled()
    {
        $this->disableCheckin();
        $user = $this->makeUser();
        $this->assertHttpFailure(function () use ($user) {
            $this->checkin()->claim($user, '127.0.0.1');
        }, 500, 'Check-in is not enabled');
        $this->assertSame(0, UserCheckinLog::where('user_id', $user->id)->count());
    }

    // ============================================================ 领取与奖励

    public function testClaimCreditsMilestoneRewardToTransferEnable()
    {
        $user = $this->makeUser(['transfer_enable' => 1000]);
        // 昨天已连续 6 天 -> 今天应为第 7 天（500MB）
        $this->seedCheckinLog($user, $this->yesterday(), 6);

        $result = $this->checkin()->claim($user, '127.0.0.1');

        $this->assertFalse($result['already']);
        $this->assertSame(7, $result['continuous_days']);
        $this->assertSame(524288000, $result['reward_bytes']);
        $this->assertSame(1000 + 524288000, (int)User::find($user->id)->transfer_enable);

        $log = UserCheckinLog::where('user_id', $user->id)->where('checkin_date', $this->checkin()->today())->first();
        $this->assertNotNull($log);
        $this->assertSame(7, (int)$log->day_index);
        $this->assertSame(524288000, (int)$log->reward_bytes);
        $this->assertSame(1, (int)$log->credited);
        $this->assertSame(UserCheckinLog::SOURCE_LOCAL, $log->source);
    }

    public function testSameDayDuplicateClaimRewardsOnlyOnce()
    {
        $user = $this->makeUser(['transfer_enable' => 0]);
        $this->seedCheckinLog($user, $this->yesterday(), 6);

        $first = $this->checkin()->claim($user, '127.0.0.1');
        $second = $this->checkin()->claim($user, '127.0.0.1');

        $this->assertFalse($first['already']);
        $this->assertTrue($second['already'], '第二次必须是 already_checked');
        $this->assertSame(524288000, (int)User::find($user->id)->transfer_enable, '同一天只能计入一次奖励');
        $this->assertSame(1, UserCheckinLog::where('user_id', $user->id)->where('checkin_date', $this->checkin()->today())->count());
    }

    /**
     * 并发/重复的数据库层兜底：(user_id, checkin_date) 唯一索引必须存在且生效。
     */
    public function testUniqueIndexBlocksSecondInsertForSameDay()
    {
        $user = $this->makeUser();
        $this->seedCheckinLog($user, $this->checkin()->today(), 1);

        $this->expectException(QueryException::class);
        DB::table('v2_user_checkin_logs')->insert([
            'user_id' => $user->id,
            'checkin_date' => $this->checkin()->today(),
            'continuous_days' => 2,
            'day_index' => 2,
            'reward_bytes' => 0,
            'reward_text' => '',
            'credited' => 1,
            'source' => UserCheckinLog::SOURCE_LOCAL,
            'created_at' => time(),
        ]);
    }

    /**
     * 已经有今天记录时（例如并发请求抢先写入），claim 必须返回 already 而不是重复发奖。
     */
    public function testClaimReturnsAlreadyWhenLogAlreadyExists()
    {
        $user = $this->makeUser(['transfer_enable' => 123]);
        $this->seedCheckinLog($user, $this->checkin()->today(), 3, 3, 0, UserCheckinLog::SOURCE_MIGRATION);

        $result = $this->checkin()->claim($user, '127.0.0.1');

        $this->assertTrue($result['already']);
        $this->assertSame(123, (int)User::find($user->id)->transfer_enable, '已有记录时不得再次加分');
        $this->assertSame(1, UserCheckinLog::where('user_id', $user->id)->count());
    }

    /**
     * 历史导入记录（credited=0 / source=migration）不会被再次计入 transfer_enable。
     */
    public function testMigrationLogIsNotRecredited()
    {
        $user = $this->makeUser(['transfer_enable' => 777]);
        $this->seedCheckinLog($user, $this->yesterday(), 5, 5, 0, UserCheckinLog::SOURCE_MIGRATION);

        $result = $this->checkin()->claim($user, '127.0.0.1');
        $this->assertFalse($result['already']);
        $this->assertSame(6, $result['continuous_days']);
        // 第 6 天没有规则 -> 0 奖励，且历史记录未被重新计入
        $this->assertSame(777, (int)User::find($user->id)->transfer_enable);
    }

    // ============================================================ 连续与循环

    public function testMissedDayResetsContinuousDays()
    {
        $user = $this->makeUser(['transfer_enable' => 0]);
        $this->seedCheckinLog($user, $this->twoDaysAgo(), 9);

        $result = $this->checkin()->claim($user, '127.0.0.1');

        $this->assertSame(1, $result['continuous_days'], '漏签必须从 1 重算');
        $this->assertSame(0, $result['reward_bytes'], '第 1 天没有奖励规则');
        $log = UserCheckinLog::where('user_id', $user->id)->where('checkin_date', $this->checkin()->today())->first();
        $this->assertSame(1, (int)$log->day_index);
    }

    public function testThirtyDayCycleWrapsToDayOne()
    {
        $user = $this->makeUser(['transfer_enable' => 0]);
        $this->seedCheckinLog($user, $this->yesterday(), 30, 30, 7516192768);

        $result = $this->checkin()->claim($user, '127.0.0.1');

        $this->assertSame(31, $result['continuous_days'], '连续天数继续累加');
        $log = UserCheckinLog::where('user_id', $user->id)->where('checkin_date', $this->checkin()->today())->first();
        $this->assertSame(1, (int)$log->day_index, '第 31 天应回到循环第 1 档');
        $this->assertSame(0, $result['reward_bytes']);

        // 下一奖励应指向下一循环的第一个里程碑（30 + 7 = 37 天）
        $status = $this->checkin()->status(User::find($user->id));
        $this->assertSame(37, $status['next_reward']['days']);
    }

    public function testDayIndexMapping()
    {
        $service = $this->checkin();
        $this->assertSame(1, $service->dayIndexFor(1));
        $this->assertSame(30, $service->dayIndexFor(30));
        $this->assertSame(1, $service->dayIndexFor(31));
        $this->assertSame(7, $service->dayIndexFor(37));
        $this->assertSame(1, $service->dayIndexFor(0));
    }

    public function testAllFourMilestonesRewardExpectedBytes()
    {
        foreach ([7 => 524288000, 14 => 2147483648, 21 => 4294967296, 30 => 7516192768] as $day => $bytes) {
            $user = $this->makeUser(['transfer_enable' => 0, 'email' => "milestone{$day}@example.com"]);
            $this->seedCheckinLog($user, $this->yesterday(), $day - 1, ((($day - 2) % 30) + 1));
            $result = $this->checkin()->claim($user, '127.0.0.1');
            $this->assertSame($bytes, $result['reward_bytes'], "第 {$day} 天奖励不符");
            $this->assertSame($bytes, (int)User::find($user->id)->transfer_enable);
        }
    }

    public function testStatusAfterClaimShowsCheckedInAndTodayReward()
    {
        $user = $this->makeUser(['transfer_enable' => 0]);
        $this->seedCheckinLog($user, $this->yesterday(), 6);
        $this->checkin()->claim($user, '127.0.0.1');

        $status = $this->checkin()->status(User::find($user->id));
        $this->assertTrue($status['checked_in']);
        $this->assertSame(7, $status['continuous_days']);
        $this->assertSame(524288000, $status['reward_bytes']);
        $this->assertSame('500MB', $status['reward_text']);
        $this->assertSame('500MB', $status['today_reward_text']);
        $this->assertTrue($status['already_checked']);

        // 里程碑 claimed 标记：第 7 天已达成
        $reached = [];
        foreach ($status['rewards'] as $reward) {
            if ($reward['claimed']) $reached[] = $reward['days'];
        }
        $this->assertContains(7, $reached);
        $this->assertNotContains(14, $reached);
    }

    public function testDisabledRuleIsNotOffered()
    {
        CheckinReward::where('day_index', 14)->update(['enabled' => 0]);
        $user = $this->makeUser();
        $status = $this->checkin()->status($user);
        $this->assertNotContains(14, array_column($status['rewards'], 'days'));

        // 第 13 天签到应跳过被停用的第 14 天规则
        $this->seedCheckinLog($user, $this->yesterday(), 13, 13);
        $result = $this->checkin()->claim($user, '127.0.0.1');
        $this->assertSame(0, $result['reward_bytes'], '停用规则不得发奖');
    }

    public function testMonthDaysMarksCheckedAndMissedDays()
    {
        $user = $this->makeUser();
        $today = $this->checkin()->today();
        $monthStart = substr($today, 0, 7) . '-01';
        if ($monthStart !== $today) {
            $this->seedCheckinLog($user, $monthStart, 1);
        }
        $this->seedCheckinLog($user, $today, 2, 2);

        $status = $this->checkin()->status(User::find($user->id));
        $map = [];
        foreach ($status['month_days'] as $day) {
            $map[$day['date']] = $day;
        }
        $this->assertArrayHasKey($today, $map);
        $this->assertTrue($map[$today]['checked']);
        $this->assertSame('checked', $map[$today]['status']);
        if ($monthStart !== $today) {
            $this->assertArrayHasKey($monthStart, $map);
            $this->assertTrue($map[$monthStart]['checked']);
        }
        foreach ($map as $date => $day) {
            $this->assertLessThanOrEqual($today, $date, '不应包含未来日期');
        }
    }

    public function testTimezoneControlsDayBoundary()
    {
        config(['v2board.checkin_timezone' => 'Pacific/Kiritimati']);
        $this->assertSame('Pacific/Kiritimati', $this->checkin()->timezone());
        $east = $this->checkin()->today();

        config(['v2board.checkin_timezone' => 'Pacific/Midway']);
        $this->assertSame('Pacific/Midway', $this->checkin()->timezone());
        $west = $this->checkin()->today();

        // UTC+14 与 UTC-11 相差 25 小时，日期必然不同（或至少时间戳不同）
        $this->assertNotSame(
            \Carbon\Carbon::now('Pacific/Kiritimati')->toDateString(),
            \Carbon\Carbon::now('Pacific/Midway')->toDateString()
        );
        $this->assertNotSame($east, $west);

        // 非法时区回退到默认
        config(['v2board.checkin_timezone' => 'Not/AZone']);
        $this->assertSame(ThemeCheckinService::DEFAULT_TIMEZONE, $this->checkin()->timezone());
    }

    // ============================================================ 子账号

    public function testSubAccountCannotClaimCheckin()
    {
        $parent = $this->makeUser(['email' => 'checkin-parent@example.com']);
        $child = $this->makeUser(['email' => 'checkin-child@example.com', 'transfer_enable' => 0]);

        $relation = new SubAccountRelation();
        $relation->parent_user_id = $parent->id;
        $relation->child_user_id = $child->id;
        $relation->traffic_limit = 0;
        $relation->remark = null;
        $relation->status = SubAccountRelation::STATUS_ENABLED;
        $relation->created_by_parent = 0;
        $relation->created_at = time();
        $relation->updated_at = time();
        $relation->save();

        // 子账号状态接口可用（但不会因此获得奖励）
        $status = $this->checkin()->status(User::find($child->id));
        $this->assertFalse($status['checked_in']);

        $this->assertHttpFailure(function () use ($child) {
            $this->checkin()->claim(User::find($child->id), '127.0.0.1');
        }, 403, 'Sub-accounts cannot claim check-in rewards independently');

        $this->assertSame(0, UserCheckinLog::where('user_id', $child->id)->count());
        $this->assertSame(0, (int)User::find($child->id)->transfer_enable);
    }

    // ============================================================ HTTP 层

    public function testStatusEndpointReturnsSingleDataWrapper()
    {
        $user = $this->makeUser();
        $http = $this->withHeaders($this->headersFor($user))->getJson('/api/v1/user/checkin/status');
        $http->assertStatus(200);
        $body = $http->json();
        $this->assertArrayHasKey('data', $body, '必须存在一层 data 包装');
        $this->assertArrayNotHasKey('data', $body['data'], 'data 内不能再嵌套 data（主题会丢字段）');
        $this->assertTrue($body['data']['enabled']);
        $this->assertFalse($body['data']['checked_in']);
    }

    public function testClaimEndpointAcceptsEmptyBodyAndReturnsClaimPayload()
    {
        $user = $this->makeUser(['transfer_enable' => 0]);
        $this->seedCheckinLog($user, $this->yesterday(), 6);

        $http = $this->withHeaders($this->headersFor($user))->postJson('/api/v1/user/checkin/claim');
        $http->assertStatus(200);
        $data = $http->json('data');
        $this->assertTrue($data['checked_in']);
        $this->assertSame(7, $data['continuous_days']);
        $this->assertSame(524288000, $data['reward_bytes']);
        $this->assertFalse($data['already_checked']);
        $this->assertSame(524288000, (int)User::find($user->id)->transfer_enable);

        // 重复领取 -> HTTP 200 + already_checked = true（主题不展示非 2xx 的 message）
        $again = $this->withHeaders($this->headersFor($user))->postJson('/api/v1/user/checkin/claim');
        $again->assertStatus(200);
        $this->assertTrue($again->json('data.already_checked'));
        $this->assertSame(524288000, (int)User::find($user->id)->transfer_enable);
    }

    public function testClaimEndpointRejectsAnonymous()
    {
        $http = $this->postJson('/api/v1/user/checkin/claim');
        $this->assertGreaterThanOrEqual(400, $http->getStatusCode());
    }
}
