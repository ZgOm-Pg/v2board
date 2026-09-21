<?php

namespace Tests\Feature;

use App\Models\PromotionPopup;
use App\Models\User;
use App\Models\UserCheckinLog;
use App\Services\AuthService;
use App\Services\CheckinService;
use App\Services\PromotionService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * 签到 / 活动弹窗 测试公共基类。
 *
 * 与项目现有约定一致：
 *   - 无 migration，四张表由 `php artisan checkin:install --apply` 创建（幂等）；
 *   - MySQL 的 DDL 会隐式提交事务，因此在 createApplication()（事务开启之前）执行安装；
 *   - 使用 DatabaseTransactions，测试数据自动回滚。
 */
abstract class CheckinPromotionTestCase extends TestCase
{
    use DatabaseTransactions;

    protected static $skipAutoInstallInApplication = false;

    public function createApplication()
    {
        $app = parent::createApplication();

        if (!self::$skipAutoInstallInApplication) {
            try {
                Artisan::call('checkin:install --apply');
            } catch (\Throwable $e) {
                // 安装失败不掩盖断言失败：需要表的测试会以 "table doesn't exist" 暴露
            }
        }

        return $app;
    }

    protected function setUp(): void
    {
        parent::setUp();

        if (empty(config('app.key'))) {
            $this->markTestSkipped('APP_KEY 未配置: 无法生成/解析鉴权 JWT，跳过测试。');
        }

        config([
            'v2board.app_name' => 'V2BoardTest',
            'v2board.app_url' => 'http://localhost',
            'queue.default' => 'sync',
            'cache.default' => 'array',
        ]);

        $this->enableCheckin();
    }

    // ------------------------------------------------------------------ 配置

    protected function enableCheckin(string $timezone = 'Asia/Shanghai'): void
    {
        config([
            'v2board.checkin_enable' => 1,
            'v2board.checkin_timezone' => $timezone,
        ]);
    }

    protected function disableCheckin(): void
    {
        config(['v2board.checkin_enable' => 0]);
    }

    // ------------------------------------------------------------------ 服务

    protected function checkin(): CheckinService
    {
        return new CheckinService();
    }

    protected function promotion(): PromotionService
    {
        return new PromotionService();
    }

    // ------------------------------------------------------------------ 数据

    protected function makeUser(array $attributes = []): User
    {
        $email = isset($attributes['email'])
            ? $attributes['email']
            : 'u' . uniqid('', true) . '@example.com';

        $defaults = [
            'email' => $email,
            'password' => password_hash('password123', PASSWORD_DEFAULT),
            'uuid' => $this->fakeGuid(true),
            'token' => $this->fakeGuid(),
            'balance' => 0,
            'commission_balance' => 0,
            't' => 0,
            'u' => 0,
            'd' => 0,
            'transfer_enable' => 107374182400,
            'device_limit' => null,
            'banned' => 0,
            'is_admin' => 0,
            'is_staff' => 0,
            'group_id' => 1,
            'plan_id' => null,
            'speed_limit' => null,
            'auto_renewal' => 0,
            'remind_expire' => 0,
            'remind_traffic' => 0,
            'expired_at' => null,
            'created_at' => time(),
            'updated_at' => time(),
        ];

        $user = new User();
        foreach (array_merge($defaults, $attributes) as $key => $value) {
            $user->{$key} = $value;
        }
        $user->save();

        return $user->refresh();
    }

    /**
     * 直写一条签到日志（构造连续/断签场景，绕过业务逻辑）。
     */
    protected function seedCheckinLog(User $user, string $date, int $continuousDays, int $dayIndex = null, int $rewardBytes = 0, string $source = UserCheckinLog::SOURCE_LOCAL): UserCheckinLog
    {
        $log = new UserCheckinLog();
        $log->user_id = $user->id;
        $log->checkin_date = $date;
        $log->continuous_days = $continuousDays;
        $log->day_index = $dayIndex === null ? ((($continuousDays - 1) % 30) + 1) : $dayIndex;
        $log->reward_bytes = $rewardBytes;
        $log->reward_text = '';
        $log->credited = $rewardBytes > 0 ? 1 : 0;
        $log->source = $source;
        $log->created_at = time();
        $log->save();
        return $log;
    }

    protected function makePopup(array $attributes = []): PromotionPopup
    {
        $defaults = [
            'title' => '限时活动',
            'subtitle' => '限时优惠',
            'coupon_code' => 'SAVE10',
            'coupon_name' => '新人券',
            'discount_text' => '9 折',
            'button_text' => '立即领取',
            'button_url' => '/shop',
            'button_action' => PromotionPopup::ACTION_CLAIM_AND_REDIRECT,
            'pages' => 'all',
            'user_scope' => PromotionPopup::SCOPE_ALL,
            'sort' => 0,
            'cooldown_hours' => 0,
            'show' => 1,
            'starts_at' => null,
            'ends_at' => null,
            'created_at' => time(),
            'updated_at' => time(),
        ];

        $popup = new PromotionPopup();
        foreach (array_merge($defaults, $attributes) as $key => $value) {
            $popup->{$key} = $value;
        }
        $popup->save();

        return $popup->refresh();
    }

    // ------------------------------------------------------------------ 鉴权

    protected function authDataFor(User $user): string
    {
        $request = Request::create('/', 'GET', [], [], [], [
            'REMOTE_ADDR' => '127.0.0.1',
            'HTTP_USER_AGENT' => 'phpunit',
        ]);
        $auth = (new AuthService($user))->generateAuthData($request);
        return $auth['auth_data'];
    }

    protected function headersFor(User $user): array
    {
        return ['authorization' => $this->authDataFor($user)];
    }

    // ------------------------------------------------------------------ 其它

    protected function fakeGuid(bool $formatted = false): string
    {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(0x80 | (ord($data[8]) & 0x3f));
        if ($formatted) {
            return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
        }
        return bin2hex($data);
    }
}
