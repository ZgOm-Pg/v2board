<?php

namespace Tests\Feature;

use App\Models\SubAccountAuditLog;
use App\Models\SubAccountRelation;
use App\Models\User;
use App\Services\AuthService;
use App\Services\SubAccountService;
use App\Utils\CacheKey;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/**
 * 子账号测试公共基类。
 *
 * 环境约定（见任务书）:
 *   - 基础表结构由 database/install.sql 导入，不存在 migration，因此使用
 *     DatabaseTransactions 而不是 RefreshDatabase；
 *   - 两张子账号表由 `php artisan sub-account:install --apply` 创建（幂等）。
 *
 * 由于 MySQL 的 DDL 会隐式提交事务，`sub-account:install --apply` 被放在
 * 应用创建阶段（事务开启之前）执行一次，避免污染 DatabaseTransactions。
 */
abstract class SubAccountTestCase extends TestCase
{
    // 必须在类体内 use 该 trait，否则事务不会开启、测试数据不会回滚
    // （仅写 use 导入语句是不生效的）。
    use DatabaseTransactions;

    /** @var bool 供安装类测试临时关闭"应用创建期自动安装" */
    protected static $skipAutoInstallInApplication = false;

    public function createApplication()
    {
        $app = parent::createApplication();

        if (!self::$skipAutoInstallInApplication) {
            try {
                // 放在应用创建阶段（事务开启之前）执行: MySQL 的 DDL 会隐式提交事务，
                // 若在 DatabaseTransactions 之后执行会破坏回滚语义。
                Artisan::call('sub-account:install --apply');
            } catch (\Throwable $e) {
                // 安装失败不应掩盖真正的断言失败: 需要表的测试会以明确的
                // "table doesn't exist" 失败信息暴露问题。
            }
        }

        return $app;
    }

    protected function setUp(): void
    {
        parent::setUp();

        $key = config('app.key');
        if (empty($key)) {
            $this->markTestSkipped('APP_KEY 未配置: 无法生成/解析鉴权 JWT，跳过子账号测试。');
        }

        $this->disableSubAccount();
        config([
            'v2board.app_name' => 'V2BoardTest',
            'v2board.app_url' => 'http://localhost',
            'v2board.subscribe_path' => '/api/v1/client/subscribe',
            'v2board.subscribe_url' => 'http://localhost',
            'v2board.show_subscribe_method' => 0,
            'queue.default' => 'sync',
            'cache.default' => 'array',
        ]);

        // RateLimiter 使用 cache 驱动，array 驱动在同一测试内共享，需显式清理
        foreach ($this->codeRateLimitKeysFor(0) as $limitKey) {
            RateLimiter::clear($limitKey);
        }
    }

    protected function tearDown(): void
    {
        parent::tearDown();
    }

    // ----------------------------------------------------------------- 配置

    protected function disableSubAccount(): void
    {
        config(['v2board.sub_account_enable' => 0]);
    }

    protected function enableSubAccount(int $maxCount = 5, int $ttl = 300, int $interval = 60): void
    {
        config([
            'v2board.sub_account_enable' => 1,
            'v2board.sub_account_max_count' => $maxCount,
            'v2board.sub_account_email_code_ttl' => $ttl,
            'v2board.sub_account_email_code_interval' => $interval,
        ]);
    }

    protected function service(): SubAccountService
    {
        return new SubAccountService();
    }

    // ----------------------------------------------------------------- 数据

    /**
     * 创建 v2_user 记录。只覆盖测试关心的列，其余交给数据库默认值。
     */
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
     * 创建主账号: 有套餐、未过期、有流量额度。
     */
    protected function makeParent(array $attributes = []): User
    {
        // 主账号必须真的有套餐行，否则 plan_id 悬空 —— 此时
        // SubAccountService::calcNextResetAt() 会（正确地）返回 null。
        $this->ensurePlan(1);

        return $this->makeUser(array_merge([
            'group_id' => 1,
            'plan_id' => 1,
            'expired_at' => time() + 86400 * 30,
            'transfer_enable' => 107374182400,
            'speed_limit' => 100,
            'device_limit' => 3,
        ], $attributes));
    }

    /**
     * 幂等创建 v2_plan 记录（默认 id=1，reset_traffic_method=0 即"每月 1 日重置"）。
     */
    protected function ensurePlan(int $id = 1): void
    {
        $exists = \Illuminate\Support\Facades\DB::table('v2_plan')->where('id', $id)->exists();
        if ($exists) {
            return;
        }
        \Illuminate\Support\Facades\DB::table('v2_plan')->insert([
            'id' => $id,
            'group_id' => 1,
            'transfer_enable' => 100,
            'device_limit' => 3,
            'name' => 'Test Plan',
            'speed_limit' => 100,
            'show' => 1,
            'sort' => 1,
            'renew' => 1,
            'content' => 'test',
            'reset_traffic_method' => 0,
            'created_at' => time(),
            'updated_at' => time(),
        ]);
    }

    /**
     * 直接落一条子账号关系（绕过验证码/校验，用于构造场景）。
     */
    protected function makeRelation(User $parent, User $child, array $attributes = []): SubAccountRelation
    {
        $relation = new SubAccountRelation();
        $relation->parent_user_id = $parent->id;
        $relation->child_user_id = $child->id;
        $relation->traffic_limit = isset($attributes['traffic_limit']) ? $attributes['traffic_limit'] : 0;
        $relation->remark = isset($attributes['remark']) ? $attributes['remark'] : null;
        $relation->status = isset($attributes['status'])
            ? $attributes['status']
            : SubAccountRelation::STATUS_ENABLED;
        $relation->created_by_parent = isset($attributes['created_by_parent'])
            ? $attributes['created_by_parent']
            : 0;
        $relation->created_at = time();
        $relation->updated_at = time();
        $relation->save();

        return $relation->refresh();
    }

    /**
     * 绑定成功后的关系（走 service::bind 全流程，含验证码消费）。
     */
    protected function bindSubAccount(User $parent, $email, array $input = []): SubAccountRelation
    {
        $this->seedBindCode($parent, $email);
        $result = $this->service()->bind($parent, array_merge($input, [
            'email' => $email,
            'code' => '123456',
        ]), '127.0.0.1');

        $this->assertArrayHasKey('data', $result);
        $this->assertArrayHasKey('id', $result['data']);

        return SubAccountRelation::find($result['data']['id']);
    }

    /**
     * 直接落库写入已有子账号的流量历史。
     *
     * 绑定校验（SubAccountService::assertBindableExistingUser）明确拒绝
     * "已有流量"的账号，因此"绑定后再产生流量"只能绕过服务层写入，这正是
     * 真实场景: 子账号先绑定、随后使用节点产生 u/d。
     */
    protected function seedChildTraffic(User $child, int $u, int $d, int $t = 0): User
    {
        \Illuminate\Support\Facades\DB::table('v2_user')
            ->where('id', $child->id)
            ->update(['u' => $u, 'd' => $d, 't' => $t]);

        return User::find($child->id);
    }

    // ------------------------------------------------------------- 验证码

    /** 允许传 User 或 user id */
    private function parentIdOf($parent): int
    {
        if ($parent instanceof User) return (int)$parent->id;
        return (int)$parent;
    }

    /**
     * 直接调用 SubAccountService::emailCacheKey，避免测试与实现漂移。
     * 键的作用域是 parent_user_id + 规范化邮箱 + APP_KEY。
     */
    protected function emailCodeCacheKey($parent, $email): string
    {
        $hash = $this->service()->emailCacheKey($this->parentIdOf($parent), $email);
        return CacheKey::get('SUB_ACCOUNT_EMAIL_CODE', $hash);
    }

    protected function emailCodeLastSendCacheKey($parent, $email): string
    {
        $hash = $this->service()->emailCacheKey($this->parentIdOf($parent), $email);
        return CacheKey::get('SUB_ACCOUNT_EMAIL_CODE_LAST_SEND', $hash);
    }

    /**
     * 直接写入验证码（等价于 sendBindCode 的缓存副作用，但不发邮件、不占用限流）。
     */
    protected function seedBindCode($parent, $email, string $code = '123456', int $ttl = 300): string
    {
        Cache::put($this->emailCodeCacheKey($parent, $email), $code, $ttl);
        Cache::put($this->emailCodeLastSendCacheKey($parent, $email), time(), max(1, $ttl));
        return $code;
    }

    /**
     * 让 sendBindCode 认为"刚刚发送过"，用于验证发送间隔限制。
     */
    protected function seedLastSend($parent, $email): void
    {
        Cache::put($this->emailCodeLastSendCacheKey($parent, $email), time(), 600);
    }

    protected function forgetBindCode($parent, $email): void
    {
        Cache::forget($this->emailCodeCacheKey($parent, $email));
        Cache::forget($this->emailCodeLastSendCacheKey($parent, $email));
    }

    protected function codeRateLimitKeysFor($userId, $ip = '127.0.0.1'): array
    {
        return ['sub_account_code_user_' . $userId, 'sub_account_code_ip_' . md5((string)$ip)];
    }

    /**
     * 打满主账号维度的发送限流（10 次/小时）。
     */
    protected function exhaustUserCodeRateLimit($userId, int $attempts = 10): void
    {
        $key = 'sub_account_code_user_' . $userId;
        RateLimiter::clear($key);
        for ($i = 0; $i < $attempts; $i++) {
            RateLimiter::hit($key, 3600);
        }
    }

    /**
     * 打满 IP 维度的发送限流（20 次/小时）。
     */
    protected function exhaustIpCodeRateLimit(string $ip = '127.0.0.1', int $attempts = 20): void
    {
        $key = 'sub_account_code_ip_' . md5($ip);
        RateLimiter::clear($key);
        for ($i = 0; $i < $attempts; $i++) {
            RateLimiter::hit($key, 3600);
        }
    }

    // --------------------------------------------------------------- 鉴权

    protected function authDataFor(User $user): string
    {
        $request = Request::create('/', 'GET', [], [], [], [
            'REMOTE_ADDR' => '127.0.0.1',
            'HTTP_USER_AGENT' => 'phpunit',
        ]);
        $auth = (new AuthService($user))->generateAuthData($request);

        return $auth['auth_data'];
    }

    /**
     * 伪造管理端 Request（后台路由前缀在路由注册期就已固化，不可靠地覆盖，
     * 因此管理端逻辑直接调用控制器方法，见任务书说明）。
     */
    protected function adminRequest(array $input = [], string $method = 'POST', string $uri = '/testsecure/sub-account/x'): Request
    {
        $request = Request::create($uri, $method, $input, [], [], [
            'REMOTE_ADDR' => '127.0.0.1',
            'HTTP_USER_AGENT' => 'phpunit',
        ]);
        $request->merge(['user' => $input['__actor'] ?? []]);
        return $request;
    }

    // ---------------------------------------------------------------- 其它

    protected function fakeGuid(bool $formatted = false): string
    {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(0x80 | (ord($data[8]) & 0x3f));
        if ($formatted) {
            return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
        }
        return md5(vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4)) . '-' . time() . '-' . mt_rand());
    }

    protected function columnIsNullable(string $table, string $column): bool
    {
        $row = \Illuminate\Support\Facades\DB::selectOne(
            'SELECT IS_NULLABLE AS is_nullable FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [$table, $column]
        );
        return $row ? strtoupper($row->is_nullable) === 'YES' : false;
    }

    protected function tableExists(string $table): bool
    {
        $row = \Illuminate\Support\Facades\DB::selectOne(
            'SELECT COUNT(*) AS c FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
            [$table]
        );
        return $row && (int)$row->c > 0;
    }

    protected function auditLogs(string $action = null): array
    {
        $query = SubAccountAuditLog::query();
        if ($action !== null) {
            $query->where('action', $action);
        }
        return $query->orderBy('id', 'asc')->get()->all();
    }

    protected function reloadUser($userId): User
    {
        return User::find($userId);
    }

    /**
     * Redis 是否可用（部分命令如 reset:traffic 会强依赖 Redis 锁）。
     */
    protected function redisIsAvailable(): bool
    {
        try {
            \Illuminate\Support\Facades\Redis::connection()->ping();
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }
}
