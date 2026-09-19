<?php

namespace App\Services;

use App\Models\Order;
use App\Models\Plan;
use App\Models\SubAccountAuditLog;
use App\Models\SubAccountRelation;
use App\Models\User;
use App\Jobs\SendEmailJob;
use App\Utils\CacheKey;
use App\Utils\Helper;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;

/**
 * 子账号核心服务。
 *
 * 集中处理: 关系增删改查、绑定校验、有效授权计算、验证码、审计。
 * 不修改任何 Eloquent 用户属性来实现"继承"，继承只通过 SubAccountEntitlement 暴露。
 */
class SubAccountService
{
    const ACTION_BIND = 'bind';
    const ACTION_UNBIND = 'unbind';
    const ACTION_UPDATE_TRAFFIC = 'update_traffic';
    const ACTION_UPDATE_REMARK = 'update_remark';
    const ACTION_CHANGE_PASSWORD = 'change_password';
    const ACTION_RESET_TRAFFIC = 'reset_traffic';
    const ACTION_RESET_SUBSCRIBE = 'reset_subscribe';
    const ACTION_CREATE = 'create';
    const ACTION_CONFIG_SAVE = 'config_save';
    const ACTION_ADMIN_UNBIND = 'admin_unbind';
    const ACTION_ORPHAN_DEACTIVATE = 'orphan_deactivate';

    /** 每个子账号默认最小密码长度 */
    const MIN_PASSWORD_LENGTH = 8;

    // ---------------------------------------------------------------- config

    public function isEnabled()
    {
        return (int)config('v2board.sub_account_enable', 1) === 1;
    }

    public function getMaxCount()
    {
        $max = (int)config('v2board.sub_account_max_count', 1);
        return $max > 0 ? $max : 1;
    }

    public function getEmailCodeTtl()
    {
        $ttl = (int)config('v2board.sub_account_email_code_ttl', 300);
        return $ttl > 0 ? $ttl : 300;
    }

    public function getEmailCodeInterval()
    {
        $interval = (int)config('v2board.sub_account_email_code_interval', 60);
        return $interval > 0 ? $interval : 60;
    }

    // ------------------------------------------------------------ 有效授权

    /**
     * 计算任意用户的"有效订阅授权"。这是全站唯一的继承计算入口。
     */
    public function resolveEntitlement(User $user)
    {
        if (!$this->isEnabled()) {
            return SubAccountEntitlement::forNormalUser($user);
        }
        $relation = SubAccountRelation::where('child_user_id', $user->id)
            ->where('status', SubAccountRelation::STATUS_ENABLED)
            ->healthy()
            ->first();
        if (!$relation) {
            return SubAccountEntitlement::forNormalUser($user);
        }
        $parent = User::find($relation->parent_user_id);
        if (!$parent) {
            return SubAccountEntitlement::forSubAccount($user, null, null);
        }
        return SubAccountEntitlement::forSubAccount($user, $parent, $relation);
    }

    public function entitlementForUserId($userId)
    {
        $user = User::find($userId);
        if (!$user) return null;
        return $this->resolveEntitlement($user);
    }

    /**
     * 订阅渲染用的「有效订阅视图」。
     *
     * 普通用户原样返回（零改动、零额外查询语义）。
     * 子账号返回一个**只读副本**：副本上的 plan_id/group_id/expired_at/
     * speed_limit/device_limit/transfer_enable 来自权益解析器，u/d/id/uuid/token/email
     * 仍为子账号自身；原模型与数据库均不被修改（副本永不 save）。
     *
     * 这样协议渲染（订阅头部、剩余流量、到期时间、限速、设备数）走的是
     * 主账号的有效权益，而不会污染 User 模型或任何查询。
     */
    public function effectiveSubscriptionUser(User $user)
    {
        if (!$this->isEnabled()) return $user;
        $entitlement = $this->resolveEntitlement($user);
        if (!$entitlement->isSubAccount()) return $user;

        $clone = clone $user;
        $clone->setRawAttributes($user->getAttributes(), true);
        $clone->plan_id = $entitlement->getEffectivePlanId();
        $clone->group_id = $entitlement->getEffectiveGroupId();
        $clone->expired_at = $entitlement->getEffectiveExpiredAt();
        $clone->speed_limit = $entitlement->getEffectiveSpeedLimit();
        $clone->device_limit = $entitlement->getEffectiveDeviceLimit();
        // 客户端展示的总额度 = 个人额度(未设置时取主账号池) 与主账号池的较小值 + 个人已用
        $clone->transfer_enable = $entitlement->getRemainingTraffic() + $entitlement->getChildUsed();
        return $clone;
    }

    /**
     * 批量取"用户 => 启用中的子账号关系"，用于流量上报等热路径，避免 N+1。
     */
    public function activeRelationsByChildIds(array $childUserIds)
    {
        if (empty($childUserIds)) return [];
        if (!$this->isEnabled()) return [];
        $rows = SubAccountRelation::whereIn('child_user_id', $childUserIds)
            ->where('status', SubAccountRelation::STATUS_ENABLED)
            ->healthy()
            ->get();
        $map = [];
        foreach ($rows as $row) {
            $map[(int)$row->child_user_id] = $row;
        }
        return $map;
    }

    public function activeRelationForChild($childUserId)
    {
        if (!$this->isEnabled()) return null;
        return SubAccountRelation::where('child_user_id', $childUserId)
            ->where('status', SubAccountRelation::STATUS_ENABLED)
            ->healthy()
            ->first();
    }

    public function parentIdForChild($childUserId)
    {
        $relation = $this->activeRelationForChild($childUserId);
        return $relation ? (int)$relation->parent_user_id : null;
    }

    public function activeChildIdsForParent($parentUserId)
    {
        if (!$this->isEnabled()) return [];
        return SubAccountRelation::where('parent_user_id', $parentUserId)
            ->where('status', SubAccountRelation::STATUS_ENABLED)
            ->healthy()
            ->pluck('child_user_id')
            ->map(function ($v) { return (int)$v; })
            ->toArray();
    }

    /**
     * 当前用户是否为启用中的子账号。
     */
    public function isSubAccount(User $user)
    {
        if (!$this->isEnabled()) return false;
        return SubAccountRelation::where('child_user_id', $user->id)
            ->where('status', SubAccountRelation::STATUS_ENABLED)
            ->healthy()
            ->exists();
    }

    /**
     * 受限操作守卫: 子账号不允许下单/充值/提现/邀请/佣金/创建子账号。
     */
    public function assertNotSubAccount(User $user, $action = '')
    {
        if (!$this->isSubAccount($user)) return true;
        abort(403, $action
            ? __('Sub-account is not allowed to') . $action
            : __('Sub-account is not allowed to perform this action'));
    }

    // ---------------------------------------------------------------- 审计

    public function audit($action, array $context = [])
    {
        try {
            $log = new SubAccountAuditLog();
            $log->relation_id = isset($context['relation_id']) ? $context['relation_id'] : null;
            $log->parent_user_id = isset($context['parent_user_id']) ? $context['parent_user_id'] : null;
            $log->child_user_id = isset($context['child_user_id']) ? $context['child_user_id'] : null;
            $log->actor_type = isset($context['actor_type']) ? $context['actor_type'] : SubAccountAuditLog::ACTOR_SYSTEM;
            // 兼容旧调用方传入的 actor_id 键名
            if (isset($context['actor_user_id'])) {
                $log->actor_user_id = $context['actor_user_id'];
            } elseif (isset($context['actor_id'])) {
                $log->actor_user_id = $context['actor_id'];
            } else {
                $log->actor_user_id = null;
            }
            $log->action = $action;
            $log->metadata = isset($context['metadata']) ? $context['metadata'] : null;
            $log->ip = isset($context['ip']) ? $context['ip'] : null;
            $log->created_at = time();
            $log->save();
            return $log;
        } catch (\Exception $e) {
            // 审计失败不应影响主流程，但必须留痕，不静默。
            \Log::error('sub_account_audit_failed: ' . $e->getMessage(), [
                'action' => $action,
                'context' => array_intersect_key($context, array_flip([
                    'relation_id', 'parent_user_id', 'child_user_id', 'actor_type', 'actor_user_id', 'actor_id'
                ]))
            ]);
            return null;
        }
    }

    // ------------------------------------------------------------ 列出关系

    /**
     * 主账号视角的关系列表（API 契约，对应需求书第十节）。
     */
    public function summarizeForParent(User $parent)
    {
        $enabled = $this->isEnabled();
        $maxCount = $this->getMaxCount();
        $relations = SubAccountRelation::where('parent_user_id', $parent->id)
            ->orderBy('id', 'asc')
            ->get();

        $items = [];
        foreach ($relations as $relation) {
            $child = User::find($relation->child_user_id);
            if (!$child) continue;
            $items[] = $this->buildItem($relation, $child, $parent);
        }

        return [
            // EZ-Theme 契约: 只有严格 === false 才视为「功能未启用」，
            // 因此这里必须返回 JSON 布尔值而不是 0/1。
            'enabled' => (bool)$enabled,
            'is_sub_account' => $this->isSubAccount($parent) ? 1 : 0,
            'max_count' => $maxCount,
            'created_count' => count($items),
            'items' => $items
        ];
    }

    /**
     * 单个关系项的展示数据。
     */
    public function buildItem(SubAccountRelation $relation, User $child, User $parent)
    {
        $childUsed = (int)$child->u + (int)$child->d;
        $trafficLimit = (int)$relation->traffic_limit;
        $parentUsed = (int)$parent->u + (int)$parent->d;
        $parentTransferEnable = (int)$parent->transfer_enable;

        $quotaRemaining = $trafficLimit > 0 ? max(0, $trafficLimit - $childUsed) : null;
        $parentRemaining = max(0, $parentTransferEnable - $parentUsed);
        $remaining = $quotaRemaining === null ? $parentRemaining : min($quotaRemaining, $parentRemaining);

        $expiredAt = $parent->expired_at;
        $nextResetAt = $this->calcNextResetAt($parent);

        return [
            'id' => (int)$relation->id,
            'child_user_id' => (int)$child->id,
            'email' => $child->email,
            'remark' => $relation->remark,
            'traffic_limit' => $trafficLimit,
            'traffic_limit_mb' => round($trafficLimit / 1048576, 2),
            'traffic_limit_gb' => round($trafficLimit / 1073741824, 2),
            'used_traffic' => $childUsed,
            'used_traffic_text' => Helper::trafficConvert($childUsed),
            'remaining_traffic' => $remaining,
            'remaining_traffic_text' => Helper::trafficConvert($remaining),
            'quota_remaining_traffic' => $quotaRemaining === null ? 0 : $quotaRemaining,
            'quota_remaining_traffic_text' => Helper::trafficConvert($quotaRemaining === null ? 0 : $quotaRemaining),
            'parent_remaining_traffic' => $parentRemaining,
            'parent_remaining_traffic_text' => Helper::trafficConvert($parentRemaining),
            'expired_at' => $expiredAt,
            'expired_at_text' => $this->formatTime($expiredAt),
            'next_reset_at' => $nextResetAt,
            'next_reset_at_text' => $this->formatTime($nextResetAt),
            'status' => (int)$relation->status,
            'created_by_parent' => (int)$relation->created_by_parent,
            'created_at' => $relation->created_at ? (int)$relation->created_at : null,
            'subscribe_url' => Helper::getSubscribeUrl($child->token)
        ];
    }

    /**
     * V2Board 没有 next_reset_at 字段，按主账号套餐的 reset_traffic_method 动态计算。
     */
    public function calcNextResetAt(User $parent)
    {
        if ((int)$parent->plan_id === 0 && $parent->plan_id !== null) return null;
        if ($parent->plan_id === null) return null;
        if ($parent->expired_at === null || (int)$parent->expired_at <= time()) return null;
        // 套餐行可能已被删除（plan_id 悬空）。UserService::getResetDay() 内部假定
        // $user->plan 存在，会直接抛 ErrorException，因此这里先自行校验。
        if (!Plan::find($parent->plan_id)) return null;
        $userService = new UserService();
        // 用克隆体探测重置日: UserService::getResetDay() 会往模型上写 $user->plan，
        // 直接传原模型会污染调用方（并可能在后续 save() 时触发意外写入）。
        $probe = clone $parent;
        $resetDay = $userService->getResetDay($probe);
        if ($resetDay === null) return null;
        $resetDay = (int)$resetDay;
        if ($resetDay < 0) $resetDay = 0;
        return time() + $resetDay * 86400;
    }

    private function formatTime($timestamp)
    {
        if (!$timestamp) return null;
        return date('Y-m-d H:i:s', (int)$timestamp);
    }

    // ------------------------------------------------------------ 邮箱验证码

    /**
     * 缓存键不含明文邮箱: sha256(email + app.key)。
     */
    private function emailCacheKey($email)
    {
        return hash('sha256', strtolower(trim($email)) . '|' . config('app.key'));
    }

    /**
     * 发送绑定用验证码。6 位、TTL 可配、发送间隔可配、请求限流。
     */
    public function sendBindCode(User $parent, $email, $ip)
    {
        if (!$this->isEnabled()) abort(500, __('Sub-account is not enabled'));
        if ($this->isSubAccount($parent)) abort(403, __('Sub-account is not allowed to create sub-accounts'));

        $email = strtolower(trim((string)$email));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) abort(500, __('Email format is incorrect'));

        // 请求限流: 每个主账号 10 次/小时，每个 IP 20 次/小时
        $userKey = 'sub_account_code_user_' . $parent->id;
        $ipKey = 'sub_account_code_ip_' . md5((string)$ip);
        if (RateLimiter::tooManyAttempts($userKey, 10)) {
            abort(429, __('Too many requests, please try again later.'));
        }
        if (RateLimiter::tooManyAttempts($ipKey, 20)) {
            abort(429, __('Too many requests, please try again later.'));
        }
        RateLimiter::hit($userKey, 3600);
        RateLimiter::hit($ipKey, 3600);

        $hash = $this->emailCacheKey($email);
        if (Cache::get(CacheKey::get('SUB_ACCOUNT_EMAIL_CODE_LAST_SEND', $hash))) {
            abort(500, __('Email verification code has been sent, please request again later'));
        }

        // 不能给自己发
        if ($email === strtolower($parent->email)) {
            abort(500, __('You cannot bind your own account as a sub-account'));
        }

        $code = (string)random_int(100000, 999999);
        SendEmailJob::dispatch([
            'email' => $email,
            'subject' => config('v2board.app_name', 'V2Board') . ' ' . __('Sub-account binding verification code'),
            'template_name' => 'verify',
            'template_value' => [
                'name' => config('v2board.app_name', 'V2Board'),
                'code' => $code,
                'url' => config('v2board.app_url')
            ]
        ]);

        Cache::put(CacheKey::get('SUB_ACCOUNT_EMAIL_CODE', $hash), $code, $this->getEmailCodeTtl());
        Cache::put(CacheKey::get('SUB_ACCOUNT_EMAIL_CODE_LAST_SEND', $hash), time(), $this->getEmailCodeInterval());

        $this->audit('send_bind_code', [
            'parent_user_id' => $parent->id,
            'actor_type' => SubAccountAuditLog::ACTOR_USER,
            'actor_user_id' => $parent->id,
            'ip' => $ip,
            'metadata' => ['email' => $email]
        ]);

        return ['data' => true];
    }

    /**
     * 原子校验并消费验证码。
     */
    public function consumeEmailCode($email, $code)
    {
        $hash = $this->emailCacheKey($email);
        $cacheKey = CacheKey::get('SUB_ACCOUNT_EMAIL_CODE', $hash);
        $cached = Cache::get($cacheKey);
        if (!$cached) abort(500, __('The verification code has expired, please resend'));
        if (!hash_equals((string)$cached, (string)$code)) {
            abort(500, __('Invalid verification code'));
        }
        // 验证成功后原子失效，防止一次性验证码被重放
        Cache::forget($cacheKey);
        Cache::forget(CacheKey::get('SUB_ACCOUNT_EMAIL_CODE_LAST_SEND', $hash));
        return true;
    }

    // ---------------------------------------------------------------- 绑定

    /**
     * 绑定（或新建）子账号。
     *
     * 全流程在事务 + 行锁中完成；child_user_id 唯一索引兜底防并发重复。
     */
    public function bind(User $parent, array $input, $ip)
    {
        if (!$this->isEnabled()) abort(500, __('Sub-account is not enabled'));
        if ($this->isSubAccount($parent)) abort(403, __('Sub-account is not allowed to create sub-accounts'));

        $email = strtolower(trim((string)(isset($input['email']) ? $input['email'] : '')));
        // EZ-Theme 发送 email_code（字符串，可能带前导零）；兼容旧的 code 字段名。
        $code = '';
        if (isset($input['email_code']) && $input['email_code'] !== '') {
            $code = trim((string)$input['email_code']);
        } elseif (isset($input['code'])) {
            $code = trim((string)$input['code']);
        }
        $remark = isset($input['remark']) ? $this->normalizeRemark($input['remark']) : null;
        // EZ-Theme 只提交 traffic_limit_gb（GB）；兼容直接传字节的 traffic_limit。
        $trafficLimitInput = $this->resolveTrafficLimitInput($input, null);
        $trafficLimit = $trafficLimitInput === null ? 0 : $trafficLimitInput;
        $password = isset($input['password']) ? (string)$input['password'] : '';

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) abort(500, __('Email format is incorrect'));
        if ($email === strtolower($parent->email)) {
            abort(500, __('You cannot bind your own account as a sub-account'));
        }
        if ($password !== '' && strlen($password) < self::MIN_PASSWORD_LENGTH) {
            abort(500, __('Password must be at least 8 characters'));
        }

        // 一次性验证码: 在事务外先扣掉，避免事务回滚后验证码可被重复使用
        $this->consumeEmailCode($email, $code);

        DB::beginTransaction();
        try {
            // 行锁: 锁住主账号，串行化同一主账号的并发绑定
            $lockedParent = User::lockForUpdate()->find($parent->id);
            if (!$lockedParent) throw new \Exception(__('The user does not exist'));

            $existsCount = SubAccountRelation::where('parent_user_id', $lockedParent->id)
                ->where('status', SubAccountRelation::STATUS_ENABLED)
                ->count();
            if ($existsCount >= $this->getMaxCount()) {
                throw new \Exception(__('The number of sub-accounts has reached the upper limit'));
            }

            // 主账号自身不能是别人的子账号（禁止多层）
            if (SubAccountRelation::where('child_user_id', $lockedParent->id)
                ->where('status', SubAccountRelation::STATUS_ENABLED)
                ->lockForUpdate()
                ->exists()) {
                throw new \Exception(__('A sub-account cannot be a parent account'));
            }

            $candidate = User::where('email', $email)->lockForUpdate()->first();
            $createdByParent = 0;
            $generatedPassword = null;

            if ($candidate) {
                $this->assertBindableExistingUser($candidate, $lockedParent);
            } else {
                if ($password === '') {
                    $generatedPassword = Helper::randomChar(16, true);
                }
                $candidate = $this->createChildUser($email, $password !== '' ? $password : $generatedPassword);
                $createdByParent = 1;
            }

            // 复用已有（停用的）关系记录，保证 child_user_id 唯一且历史连续
            $relation = SubAccountRelation::where('child_user_id', $candidate->id)
                ->lockForUpdate()
                ->first();
            if (!$relation) {
                $relation = new SubAccountRelation();
                $relation->child_user_id = $candidate->id;
            }
            $relation->parent_user_id = $lockedParent->id;
            $relation->traffic_limit = $trafficLimit;
            $relation->remark = $remark;
            $relation->status = SubAccountRelation::STATUS_ENABLED;
            $relation->created_by_parent = $createdByParent;
            if (!$relation->save()) throw new \Exception(__('Save failed'));

            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            abort(500, $e->getMessage());
        }

        $this->audit($createdByParent ? self::ACTION_CREATE : self::ACTION_BIND, [
            'relation_id' => $relation->id,
            'parent_user_id' => $lockedParent->id,
            'child_user_id' => $relation->child_user_id,
            'actor_type' => SubAccountAuditLog::ACTOR_USER,
            'actor_user_id' => $lockedParent->id,
            'ip' => $ip,
            'metadata' => [
                'traffic_limit' => $trafficLimit,
                'created_by_parent' => (bool)$createdByParent
            ]
        ]);

        $result = [
            'data' => $this->buildItem($relation, User::find($relation->child_user_id), $lockedParent)
        ];
        if ($createdByParent && isset($generatedPassword) && $generatedPassword) {
            // 仅在自动生成初始密码时一次性返回，便于主账号交付给子账号使用者
            $result['data']['initial_password'] = $generatedPassword;
        }
        return $result;
    }

    /**
     * 绑定已存在账号时的拒绝规则（需求书第十一节）。
     */
    public function assertBindableExistingUser(User $candidate, User $parent)
    {
        if ((int)$candidate->id === (int)$parent->id) {
            abort(500, __('You cannot bind your own account as a sub-account'));
        }
        if ((int)$candidate->is_admin === 1) abort(500, __('Administrators cannot be bound as sub-accounts'));
        if ((int)$candidate->is_staff === 1) abort(500, __('Staff cannot be bound as sub-accounts'));
        if ((int)$candidate->banned === 1) abort(500, __('Banned users cannot be bound as sub-accounts'));

        if (Order::where('user_id', $candidate->id)->exists()) {
            abort(500, __('Users with orders cannot be bound as sub-accounts'));
        }
        if ((int)$candidate->balance !== 0 || (int)$candidate->commission_balance !== 0) {
            abort(500, __('Users with balance or commission cannot be bound as sub-accounts'));
        }
        if ($candidate->plan_id !== null) {
            abort(500, __('Users with a plan cannot be bound as sub-accounts'));
        }
        if (((int)$candidate->u + (int)$candidate->d) > 0) {
            abort(500, __('Users with traffic usage cannot be bound as sub-accounts'));
        }

        // 已是启用中的子账号
        if (SubAccountRelation::where('child_user_id', $candidate->id)
            ->where('status', SubAccountRelation::STATUS_ENABLED)
            ->exists()) {
            abort(500, __('This account is already a sub-account'));
        }
        // 自身有启用子账号 => 不能变成子账号（禁止多层）
        if (SubAccountRelation::where('parent_user_id', $candidate->id)
            ->where('status', SubAccountRelation::STATUS_ENABLED)
            ->exists()) {
            abort(500, __('This account already has sub-accounts'));
        }
        // 循环保护（结构上多层已被禁止，此处为纵深防御）
        if ($this->wouldCreateCycle($parent->id, $candidate->id)) {
            abort(500, __('Circular sub-account relationship is not allowed'));
        }
        return true;
    }

    /**
     * 沿 parent_user_id 向上回溯，判断 candidateId 是否已是 parent 的祖先。
     */
    private function wouldCreateCycle($parentId, $candidateId)
    {
        $cursor = (int)$parentId;
        $guard = 0;
        while ($cursor && $guard < 64) {
            if ($cursor === (int)$candidateId) return true;
            $relation = SubAccountRelation::where('child_user_id', $cursor)
                ->where('status', SubAccountRelation::STATUS_ENABLED)
                ->first();
            if (!$relation) return false;
            $cursor = (int)$relation->parent_user_id;
            $guard++;
        }
        return $guard >= 64;
    }

    /**
     * 新建子账号 v2_user 记录，全部使用中性值（需求书第八节）。
     * 绝不复制主账号套餐字段。
     */
    public function createChildUser($email, $plainPassword)
    {
        $user = new User();
        $user->email = $email;
        $user->password = password_hash($plainPassword, PASSWORD_DEFAULT);
        $user->password_algo = null;
        $user->password_salt = null;

        $user->plan_id = null;
        $user->group_id = null;
        $user->expired_at = null;
        $user->transfer_enable = 0;
        $user->balance = 0;
        $user->commission_balance = 0;
        $user->auto_renewal = 0;
        $user->remind_expire = 0;
        $user->remind_traffic = 0;
        // speed_limit / device_limit 在目标结构中可空，子账号保持 NULL（不复制主账号限制）
        $user->speed_limit = null;
        $user->device_limit = null;

        $user->u = 0;
        $user->d = 0;
        $user->t = 0;
        $user->banned = 0;
        $user->is_admin = 0;
        $user->is_staff = 0;
        $user->invite_user_id = null;
        $user->uuid = Helper::guid(true);
        $user->token = Helper::guid();
        $user->created_at = time();
        $user->updated_at = time();

        if (!$user->save()) throw new \Exception(__('Save failed'));
        return $user;
    }

    private function normalizeTrafficLimit($value)
    {
        if ($value === null || $value === '') return 0;
        if (!is_numeric($value)) abort(500, __('Traffic limit format is incorrect'));
        $value = (float)$value;
        if ($value < 0) abort(500, __('Traffic limit format is incorrect'));
        return (int)round($value);
    }

    /**
     * 解析流量额度入参。
     *
     * EZ-Theme 只提交 traffic_limit_gb（GB，浮点）；为兼容脚本与旧客户端，
     * 仍接受字节语义的 traffic_limit。两者同时出现时以 traffic_limit_gb 为准。
     *
     * @return int|null 未提供任何额度字段时返回 null
     */
    private function resolveTrafficLimitInput(array $input, $default = null)
    {
        if (array_key_exists('traffic_limit_gb', $input)
            && $input['traffic_limit_gb'] !== null
            && $input['traffic_limit_gb'] !== '') {
            if (!is_numeric($input['traffic_limit_gb'])) {
                abort(500, __('Traffic limit format is incorrect'));
            }
            $gb = (float)$input['traffic_limit_gb'];
            if ($gb < 0) abort(500, __('Traffic limit format is incorrect'));
            return (int)round($gb * 1073741824);
        }
        if (array_key_exists('traffic_limit', $input) && $input['traffic_limit'] !== null && $input['traffic_limit'] !== '') {
            return $this->normalizeTrafficLimit($input['traffic_limit']);
        }
        return $default;
    }

    /**
     * 管理端筛选: 支持用户 ID 或邮箱关键字（父/子账号）。
     *
     * @return array 匹配的 user id 列表；无匹配时返回 [0]，保证查询必然为空。
     */
    public function resolveUserIdsByKeyword($keyword)
    {
        $keyword = trim((string)$keyword);
        if ($keyword === '') return [];
        if (ctype_digit($keyword)) return [(int)$keyword];
        $ids = User::where('email', 'like', '%' . $keyword . '%')
            ->limit(200)
            ->pluck('id')
            ->map(function ($v) { return (int)$v; })
            ->toArray();
        return empty($ids) ? [0] : $ids;
    }

    private function normalizeRemark($value)
    {
        $value = trim((string)$value);
        if ($value === '') return null;
        if (function_exists('mb_substr')) {
            return mb_substr($value, 0, 255, 'UTF-8');
        }
        return substr($value, 0, 255);
    }

    // ------------------------------------------------------- 归属校验(防越权)

    /**
     * 取指定主账号名下的关系，找不到直接 403/500，防止水平越权。
     */
    public function requireOwnedRelation(User $parent, $relationId)
    {
        $relation = SubAccountRelation::find($relationId);
        if (!$relation) abort(500, __('The sub-account relation does not exist'));
        if ((int)$relation->parent_user_id !== (int)$parent->id) {
            abort(403, __('You do not have permission to operate this sub-account'));
        }
        return $relation;
    }

    public function requireOwnedRelationByChildId(User $parent, $childUserId)
    {
        $relation = SubAccountRelation::where('parent_user_id', $parent->id)
            ->where('child_user_id', $childUserId)
            ->first();
        if (!$relation) abort(403, __('You do not have permission to operate this sub-account'));
        return $relation;
    }

    /**
     * 关系定位：优先用关系 id，其次用 child_user_id（EZ-Theme 两者都会发送）。
     */
    public function requireOwnedRelationOrChild(User $parent, array $input)
    {
        $relationId = isset($input['id']) && $input['id'] !== '' ? $input['id'] : null;
        $childUserId = isset($input['child_user_id']) && $input['child_user_id'] !== '' ? $input['child_user_id'] : null;

        if ($relationId !== null) {
            $relation = SubAccountRelation::find($relationId);
            if ($relation && (int)$relation->parent_user_id === (int)$parent->id) {
                return $relation;
            }
        }
        if ($childUserId !== null) {
            return $this->requireOwnedRelationByChildId($parent, $childUserId);
        }
        if ($relationId === null) {
            abort(500, __('The sub-account relation does not exist'));
        }
        return $this->requireOwnedRelation($parent, $relationId);
    }

    // ------------------------------------------------------------ 修改/解绑

    public function updateRelation(User $parent, array $input, $ip)
    {
        if (!$this->isEnabled()) abort(500, __('Sub-account is not enabled'));
        if ($this->isSubAccount($parent)) abort(403, __('Sub-account is not allowed to manage sub-accounts'));
        $relation = $this->requireOwnedRelation($parent, isset($input['id']) ? $input['id'] : null);

        DB::beginTransaction();
        try {
            $locked = SubAccountRelation::lockForUpdate()->find($relation->id);
            if (!$locked) throw new \Exception(__('The sub-account relation does not exist'));

            $changed = [];
            // EZ-Theme 只提交 traffic_limit_gb（GB）；兼容字节语义的 traffic_limit。
            $limitInput = $this->resolveTrafficLimitInput($input, null);
            if ($limitInput !== null) {
                $limit = $limitInput;
                if ($limit !== (int)$locked->traffic_limit) {
                    $changed['traffic_limit'] = $limit;
                    $locked->traffic_limit = $limit;
                }
            }
            if (array_key_exists('remark', $input)) {
                $remark = $this->normalizeRemark($input['remark']);
                if ($remark !== $locked->remark) {
                    $changed['remark'] = $remark;
                    $locked->remark = $remark;
                }
            }
            if (!$locked->save()) throw new \Exception(__('Save failed'));
            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            abort(500, $e->getMessage());
        }

        if (isset($changed['traffic_limit'])) {
            $this->audit(self::ACTION_UPDATE_TRAFFIC, [
                'relation_id' => $locked->id,
                'parent_user_id' => $locked->parent_user_id,
                'child_user_id' => $locked->child_user_id,
                'actor_type' => SubAccountAuditLog::ACTOR_USER,
                'actor_user_id' => $parent->id,
                'ip' => $ip,
                'metadata' => ['traffic_limit' => $changed['traffic_limit']]
            ]);
        }
        if (isset($changed['remark'])) {
            $this->audit(self::ACTION_UPDATE_REMARK, [
                'relation_id' => $locked->id,
                'parent_user_id' => $locked->parent_user_id,
                'child_user_id' => $locked->child_user_id,
                'actor_type' => SubAccountAuditLog::ACTOR_USER,
                'actor_user_id' => $parent->id,
                'ip' => $ip,
                'metadata' => ['remark' => $changed['remark']]
            ]);
        }

        $child = User::find($locked->child_user_id);
        return ['data' => $this->buildItem($locked, $child, $parent)];
    }

    /**
     * 主账号修改子账号密码: 8 位起、清除子账号全部登录会话、审计不保存密码。
     */
    public function changeChildPassword(User $parent, array $input, $ip)
    {
        if (!$this->isEnabled()) abort(500, __('Sub-account is not enabled'));
        if ($this->isSubAccount($parent)) abort(403, __('Sub-account is not allowed to manage sub-accounts'));
        $relation = $this->requireOwnedRelationOrChild($parent, $input);

        // EZ-Theme 同时发送 new_password 与 password（值相同）；两者都接受。
        $password = '';
        if (isset($input['new_password']) && $input['new_password'] !== '') {
            $password = (string)$input['new_password'];
        } elseif (isset($input['password'])) {
            $password = (string)$input['password'];
        }
        if (strlen($password) < self::MIN_PASSWORD_LENGTH) {
            abort(500, __('Password must be at least 8 characters'));
        }

        DB::beginTransaction();
        try {
            $child = User::lockForUpdate()->find($relation->child_user_id);
            if (!$child) throw new \Exception(__('The user does not exist'));
            $child->password = password_hash($password, PASSWORD_DEFAULT);
            $child->password_algo = null;
            $child->password_salt = null;
            if (!$child->save()) throw new \Exception(__('Save failed'));
            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            abort(500, $e->getMessage());
        }

        // 清除已有登录会话
        $authService = new AuthService($child);
        $authService->removeAllSession();

        $this->audit(self::ACTION_CHANGE_PASSWORD, [
            'relation_id' => $relation->id,
            'parent_user_id' => $relation->parent_user_id,
            'child_user_id' => $relation->child_user_id,
            'actor_type' => SubAccountAuditLog::ACTOR_USER,
            'actor_user_id' => $parent->id,
            'ip' => $ip,
            'metadata' => ['session_revoked' => true]
        ]);

        return ['data' => true];
    }

    /**
     * 手动重置子账号流量: 只清零子账号 u/d，不回退主账号已累计的共享用量。
     */
    public function resetChildTraffic(User $parent, array $input, $ip)
    {
        if (!$this->isEnabled()) abort(500, __('Sub-account is not enabled'));
        if ($this->isSubAccount($parent)) abort(403, __('Sub-account is not allowed to manage sub-accounts'));
        $relation = $this->requireOwnedRelation($parent, isset($input['id']) ? $input['id'] : null);

        DB::beginTransaction();
        try {
            $child = User::lockForUpdate()->find($relation->child_user_id);
            if (!$child) throw new \Exception(__('The user does not exist'));
            $before = ['u' => (int)$child->u, 'd' => (int)$child->d];
            $child->u = 0;
            $child->d = 0;
            if (!$child->save()) throw new \Exception(__('Save failed'));
            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            abort(500, $e->getMessage());
        }

        $this->audit(self::ACTION_RESET_TRAFFIC, [
            'relation_id' => $relation->id,
            'parent_user_id' => $relation->parent_user_id,
            'child_user_id' => $relation->child_user_id,
            'actor_type' => SubAccountAuditLog::ACTOR_USER,
            'actor_user_id' => $parent->id,
            'ip' => $ip,
            'metadata' => ['before' => $before]
        ]);

        return ['data' => true];
    }

    /**
     * 重置订阅: 轮换 uuid + token，旧订阅立即失效。
     */
    public function resetChildSubscribe(User $parent, array $input, $ip)
    {
        if (!$this->isEnabled()) abort(500, __('Sub-account is not enabled'));
        if ($this->isSubAccount($parent)) abort(403, __('Sub-account is not allowed to manage sub-accounts'));
        $relation = $this->requireOwnedRelation($parent, isset($input['id']) ? $input['id'] : null);

        DB::beginTransaction();
        try {
            $child = User::lockForUpdate()->find($relation->child_user_id);
            if (!$child) throw new \Exception(__('The user does not exist'));
            $child->uuid = Helper::guid(true);
            $child->token = Helper::guid();
            if (!$child->save()) throw new \Exception(__('Save failed'));
            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            abort(500, $e->getMessage());
        }

        $this->audit(self::ACTION_RESET_SUBSCRIBE, [
            'relation_id' => $relation->id,
            'parent_user_id' => $relation->parent_user_id,
            'child_user_id' => $relation->child_user_id,
            'actor_type' => SubAccountAuditLog::ACTOR_USER,
            'actor_user_id' => $parent->id,
            'ip' => $ip,
            'metadata' => ['token_rotated' => true]
        ]);

        return ['data' => [
            'child_user_id' => (int)$child->id,
            'subscribe_url' => Helper::getSubscribeUrl($child->token),
            'url' => Helper::getSubscribeUrl($child->token)
        ]];
    }

    /**
     * 子账号订阅信息（主账号视角）。
     *
     * EZ-Theme 以关系 id（列表行 id）请求；同时兼容 child_user_id。
     */
    public function childSubscribe(User $parent, $idOrChildId)
    {
        if (!$this->isEnabled()) abort(500, __('Sub-account is not enabled'));
        if ($this->isSubAccount($parent)) abort(403, __('Sub-account is not allowed to manage sub-accounts'));
        if (!$idOrChildId) abort(500, __('The sub-account relation does not exist'));

        $relation = SubAccountRelation::where('id', $idOrChildId)
            ->where('parent_user_id', $parent->id)
            ->first();
        if (!$relation) {
            $relation = $this->requireOwnedRelationByChildId($parent, $idOrChildId);
        }
        $child = User::find($relation->child_user_id);
        if (!$child) abort(500, __('The user does not exist'));
        $item = $this->buildItem($relation, $child, $parent);
        // 兼容 data.url / data.subscribe_url 两种读取方式
        $item['url'] = $item['subscribe_url'];
        return ['data' => $item];
    }

    /**
     * 解绑: 只停用关系 + 轮换 uuid/token；不删除用户、流量历史与审计。
     */
    public function unbind(User $parent, array $input, $ip)
    {
        if (!$this->isEnabled()) abort(500, __('Sub-account is not enabled'));
        if ($this->isSubAccount($parent)) abort(403, __('Sub-account is not allowed to manage sub-accounts'));
        $relation = $this->requireOwnedRelation($parent, isset($input['id']) ? $input['id'] : null);

        DB::beginTransaction();
        try {
            $locked = SubAccountRelation::lockForUpdate()->find($relation->id);
            if (!$locked) throw new \Exception(__('The sub-account relation does not exist'));
            if ((int)$locked->status !== SubAccountRelation::STATUS_ENABLED) {
                throw new \Exception(__('The sub-account has been unbound'));
            }
            $locked->status = SubAccountRelation::STATUS_DISABLED;
            if (!$locked->save()) throw new \Exception(__('Save failed'));

            $child = User::lockForUpdate()->find($locked->child_user_id);
            if ($child) {
                // 轮换凭据，旧订阅立即失效
                $child->uuid = Helper::guid(true);
                $child->token = Helper::guid();
                if (!$child->save()) throw new \Exception(__('Save failed'));
            }
            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            abort(500, $e->getMessage());
        }

        $this->audit(self::ACTION_UNBIND, [
            'relation_id' => $locked->id,
            'parent_user_id' => $locked->parent_user_id,
            'child_user_id' => $locked->child_user_id,
            'actor_type' => SubAccountAuditLog::ACTOR_USER,
            'actor_user_id' => $parent->id,
            'ip' => $ip,
            'metadata' => ['status' => SubAccountRelation::STATUS_DISABLED]
        ]);

        return ['data' => true];
    }

    // ---------------------------------------------------------------- 管理端

    public function adminList(array $filters = [])
    {
        $query = SubAccountRelation::query();
        if (isset($filters['status']) && $filters['status'] !== '' && $filters['status'] !== null) {
            $query->where('status', (int)$filters['status']);
        }
        if (isset($filters['parent_user_id']) && $filters['parent_user_id'] !== '' && $filters['parent_user_id'] !== null) {
            $query->whereIn('parent_user_id', $this->resolveUserIdsByKeyword($filters['parent_user_id']));
        }
        if (isset($filters['child_user_id']) && $filters['child_user_id'] !== '' && $filters['child_user_id'] !== null) {
            $query->whereIn('child_user_id', $this->resolveUserIdsByKeyword($filters['child_user_id']));
        }
        $page = isset($filters['current']) ? max(1, (int)$filters['current']) : 1;
        $size = isset($filters['page_size']) ? min(100, max(1, (int)$filters['page_size'])) : 20;

        $total = (clone $query)->count();
        $relations = $query->orderBy('id', 'desc')
            ->skip(($page - 1) * $size)
            ->take($size)
            ->get();

        $items = [];
        foreach ($relations as $relation) {
            $child = User::find($relation->child_user_id);
            $parent = User::find($relation->parent_user_id);
            $row = [
                'id' => (int)$relation->id,
                'parent_user_id' => (int)$relation->parent_user_id,
                'parent_email' => $parent ? $parent->email : null,
                'child_user_id' => (int)$relation->child_user_id,
                'child_email' => $child ? $child->email : null,
                'traffic_limit' => (int)$relation->traffic_limit,
                'traffic_limit_gb' => round((int)$relation->traffic_limit / 1073741824, 2),
                'traffic_limit_mb' => round((int)$relation->traffic_limit / 1048576, 2),
                'remark' => $relation->remark,
                'status' => (int)$relation->status,
                'created_by_parent' => (int)$relation->created_by_parent,
                'created_at' => $relation->created_at ? (int)$relation->created_at : null
            ];
            if ($child) {
                $row['used_traffic'] = (int)$child->u + (int)$child->d;
                $row['used_traffic_text'] = Helper::trafficConvert($row['used_traffic']);
            } else {
                $row['used_traffic'] = null;
                $row['used_traffic_text'] = null;
            }
            if ($parent) {
                $row['parent_used'] = (int)$parent->u + (int)$parent->d;
                $row['parent_transfer_enable'] = (int)$parent->transfer_enable;
                $row['parent_remaining_traffic'] = max(0, (int)$parent->transfer_enable - $row['parent_used']);
                $row['parent_remaining_traffic_text'] = Helper::trafficConvert($row['parent_remaining_traffic']);
                $row['plan_id'] = $parent->plan_id;
                $row['group_id'] = $parent->group_id;
                $row['expired_at'] = $parent->expired_at;
                $row['expired_at_text'] = $this->formatTime($parent->expired_at);
            }
            $items[] = $row;
        }

        return ['data' => ['total' => $total, 'items' => $items]];
    }

    /**
     * 关系详情（管理端）: 关系字段 + 父子账号快照 + 权益可用性 + 最近审计。
     */
    public function adminDetail($relationId)
    {
        $relation = SubAccountRelation::find($relationId);
        if (!$relation) abort(500, __('The sub-account relation does not exist'));

        $parent = User::find($relation->parent_user_id);
        $child = User::find($relation->child_user_id);
        $entitlement = $child ? $this->resolveEntitlement($child) : null;

        $logs = SubAccountAuditLog::where('relation_id', $relation->id)
            ->orderBy('id', 'desc')
            ->limit(20)
            ->get();
        $audit = [];
        foreach ($logs as $log) {
            $audit[] = [
                'id' => (int)$log->id,
                'action' => $log->action,
                'actor_type' => $log->actor_type,
                'actor_user_id' => $log->actor_user_id,
                'metadata' => $log->metadata,
                'ip' => $log->ip,
                'created_at' => $log->created_at ? (int)$log->created_at : null,
                'created_at_text' => $this->formatTime($log->created_at)
            ];
        }

        $parentUsed = $parent ? (int)$parent->u + (int)$parent->d : 0;
        $childUsed = $child ? (int)$child->u + (int)$child->d : 0;
        $parentRemaining = $parent ? max(0, (int)$parent->transfer_enable - $parentUsed) : 0;

        return ['data' => [
            'id' => (int)$relation->id,
            'parent_user_id' => (int)$relation->parent_user_id,
            'child_user_id' => (int)$relation->child_user_id,
            'traffic_limit' => (int)$relation->traffic_limit,
            'traffic_limit_gb' => round((int)$relation->traffic_limit / 1073741824, 2),
            'traffic_limit_mb' => round((int)$relation->traffic_limit / 1048576, 2),
            'remark' => $relation->remark,
            'status' => (int)$relation->status,
            'created_by_parent' => (int)$relation->created_by_parent,
            'created_at' => $relation->created_at ? (int)$relation->created_at : null,
            'created_at_text' => $this->formatTime($relation->created_at),
            'updated_at' => $relation->updated_at ? (int)$relation->updated_at : null,
            'parent' => $parent ? [
                'id' => (int)$parent->id,
                'email' => $parent->email,
                'plan_id' => $parent->plan_id,
                'group_id' => $parent->group_id,
                'expired_at' => $parent->expired_at,
                'expired_at_text' => $this->formatTime($parent->expired_at),
                'banned' => (int)$parent->banned,
                'u' => (int)$parent->u,
                'd' => (int)$parent->d,
                'transfer_enable' => (int)$parent->transfer_enable,
                'used_traffic' => $parentUsed,
                'used_traffic_text' => Helper::trafficConvert($parentUsed),
                'remaining_traffic' => $parentRemaining,
                'remaining_traffic_text' => Helper::trafficConvert($parentRemaining)
            ] : null,
            'child' => $child ? [
                'id' => (int)$child->id,
                'email' => $child->email,
                'banned' => (int)$child->banned,
                'u' => (int)$child->u,
                'd' => (int)$child->d,
                'used_traffic' => $childUsed,
                'used_traffic_text' => Helper::trafficConvert($childUsed),
                'plan_id' => $child->plan_id,
                'group_id' => $child->group_id,
                'expired_at' => $child->expired_at
            ] : null,
            'health' => [
                'parent_exists' => (bool)$parent,
                'child_exists' => (bool)$child,
                'healthy' => (bool)($parent && $child),
                'can_connect' => $entitlement ? (bool)$entitlement->canConnect() : false,
                'effective_plan_id' => $entitlement ? $entitlement->getEffectivePlanId() : null,
                'effective_group_id' => $entitlement ? $entitlement->getEffectiveGroupId() : null,
                'effective_expired_at' => $entitlement ? $entitlement->getEffectiveExpiredAt() : null,
                'effective_reset_method' => $entitlement ? $entitlement->getEffectiveResetMethod() : null,
                'subscription_available' => $entitlement ? (bool)$entitlement->isSubscriptionAvailable() : false
            ],
            'audit' => $audit
        ]];
    }

    public function adminAudit(array $filters = [])
    {
        $query = SubAccountAuditLog::query();
        if (!empty($filters['relation_id'])) $query->where('relation_id', (int)$filters['relation_id']);
        if (isset($filters['parent_user_id']) && $filters['parent_user_id'] !== '' && $filters['parent_user_id'] !== null) {
            $query->whereIn('parent_user_id', $this->resolveUserIdsByKeyword($filters['parent_user_id']));
        }
        if (isset($filters['child_user_id']) && $filters['child_user_id'] !== '' && $filters['child_user_id'] !== null) {
            $query->whereIn('child_user_id', $this->resolveUserIdsByKeyword($filters['child_user_id']));
        }
        if (!empty($filters['action'])) $query->where('action', $filters['action']);

        $page = isset($filters['current']) ? max(1, (int)$filters['current']) : 1;
        $size = isset($filters['page_size']) ? min(100, max(1, (int)$filters['page_size'])) : 20;

        $total = (clone $query)->count();
        $logs = $query->orderBy('id', 'desc')
            ->skip(($page - 1) * $size)
            ->take($size)
            ->get();

        $items = [];
        foreach ($logs as $log) {
            $actorEmail = null;
            if ($log->actor_user_id) {
                $actor = User::find($log->actor_user_id);
                if ($actor) $actorEmail = $actor->email;
            }
            $items[] = [
                'id' => (int)$log->id,
                'relation_id' => $log->relation_id,
                'parent_user_id' => $log->parent_user_id,
                'child_user_id' => $log->child_user_id,
                'actor_type' => $log->actor_type,
                'actor_user_id' => $log->actor_user_id,
                'actor_email' => $actorEmail,
                'action' => $log->action,
                'metadata' => $log->metadata,
                'ip' => $log->ip,
                'created_at' => $log->created_at ? (int)$log->created_at : null,
                'created_at_text' => $this->formatTime($log->created_at ? (int)$log->created_at : null)
            ];
        }

        return ['data' => ['total' => $total, 'items' => $items]];
    }

    public function adminUpdate($relationId, array $input, $actorId, $ip)
    {
        $relation = SubAccountRelation::find($relationId);
        if (!$relation) abort(500, __('The sub-account relation does not exist'));

        DB::beginTransaction();
        try {
            $locked = SubAccountRelation::lockForUpdate()->find($relation->id);
            $changed = [];
            // 管理端允许传字节(traffic_limit)或 GB(traffic_limit_gb)
            $limitInput = $this->resolveTrafficLimitInput($input, null);
            if ($limitInput !== null) {
                $limit = $limitInput;
                if ($limit !== (int)$locked->traffic_limit) {
                    $changed['traffic_limit'] = $limit;
                    $locked->traffic_limit = $limit;
                }
            }
            if (array_key_exists('remark', $input)) {
                $remark = $this->normalizeRemark($input['remark']);
                if ($remark !== $locked->remark) {
                    $changed['remark'] = $remark;
                    $locked->remark = $remark;
                }
            }
            if (array_key_exists('status', $input) && $input['status'] !== '' && $input['status'] !== null) {
                $status = (int)$input['status'] === SubAccountRelation::STATUS_ENABLED
                    ? SubAccountRelation::STATUS_ENABLED
                    : SubAccountRelation::STATUS_DISABLED;
                if ($status !== (int)$locked->status) {
                    $changed['status'] = $status;
                    $locked->status = $status;
                }
            }
            if (!empty($changed)) {
                if (!$locked->save()) throw new \Exception(__('Save failed'));
            }
            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            abort(500, $e->getMessage());
        }

        if (!empty($changed)) {
            $this->audit(self::ACTION_UPDATE_TRAFFIC, [
                'relation_id' => $locked->id,
                'parent_user_id' => $locked->parent_user_id,
                'child_user_id' => $locked->child_user_id,
                'actor_type' => SubAccountAuditLog::ACTOR_ADMIN,
                'actor_user_id' => $actorId,
                'ip' => $ip,
                'metadata' => $changed
            ]);
        }
        return ['data' => true];
    }

    public function adminResetSubscribe($relationId, $actorId, $ip)
    {
        $relation = SubAccountRelation::find($relationId);
        if (!$relation) abort(500, __('The sub-account relation does not exist'));

        DB::beginTransaction();
        try {
            $child = User::lockForUpdate()->find($relation->child_user_id);
            if (!$child) throw new \Exception(__('The user does not exist'));
            $child->uuid = Helper::guid(true);
            $child->token = Helper::guid();
            if (!$child->save()) throw new \Exception(__('Save failed'));
            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            abort(500, $e->getMessage());
        }

        $this->audit(self::ACTION_RESET_SUBSCRIBE, [
            'relation_id' => $relation->id,
            'parent_user_id' => $relation->parent_user_id,
            'child_user_id' => $relation->child_user_id,
            'actor_type' => SubAccountAuditLog::ACTOR_ADMIN,
            'actor_user_id' => $actorId,
            'ip' => $ip,
            'metadata' => ['token_rotated' => true]
        ]);
        return ['data' => true];
    }

    public function adminResetTraffic($relationId, $actorId, $ip)
    {
        $relation = SubAccountRelation::find($relationId);
        if (!$relation) abort(500, __('The sub-account relation does not exist'));

        DB::beginTransaction();
        try {
            $child = User::lockForUpdate()->find($relation->child_user_id);
            if (!$child) throw new \Exception(__('The user does not exist'));
            $before = ['u' => (int)$child->u, 'd' => (int)$child->d];
            $child->u = 0;
            $child->d = 0;
            if (!$child->save()) throw new \Exception(__('Save failed'));
            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            abort(500, $e->getMessage());
        }

        $this->audit(self::ACTION_RESET_TRAFFIC, [
            'relation_id' => $relation->id,
            'parent_user_id' => $relation->parent_user_id,
            'child_user_id' => $relation->child_user_id,
            'actor_type' => SubAccountAuditLog::ACTOR_ADMIN,
            'actor_user_id' => $actorId,
            'ip' => $ip,
            'metadata' => ['before' => $before]
        ]);
        return ['data' => true];
    }
    public function adminUnbind($relationId, $actorId, $ip)
    {
        $relation = SubAccountRelation::find($relationId);
        if (!$relation) abort(500, __('The sub-account relation does not exist'));

        DB::beginTransaction();
        try {
            $locked = SubAccountRelation::lockForUpdate()->find($relation->id);
            $locked->status = SubAccountRelation::STATUS_DISABLED;
            if (!$locked->save()) throw new \Exception(__('Save failed'));
            $child = User::lockForUpdate()->find($locked->child_user_id);
            if ($child) {
                $child->uuid = Helper::guid(true);
                $child->token = Helper::guid();
                if (!$child->save()) throw new \Exception(__('Save failed'));
            }
            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            abort(500, $e->getMessage());
        }

        $this->audit(self::ACTION_ADMIN_UNBIND, [
            'relation_id' => $locked->id,
            'parent_user_id' => $locked->parent_user_id,
            'child_user_id' => $locked->child_user_id,
            'actor_type' => SubAccountAuditLog::ACTOR_ADMIN,
            'actor_user_id' => $actorId,
            'ip' => $ip,
            'metadata' => ['status' => SubAccountRelation::STATUS_DISABLED]
        ]);
        return ['data' => true];
    }

    /**
     * 启用中的子账号 ID 映射（[childId => true]），用于批量跳过判断，避免 N+1。
     */
    public function enabledChildIdMap()
    {
        if (!(int)config('v2board.sub_account_enable', 1)) return [];
        $ids = SubAccountRelation::enabled()->healthy()->pluck('child_user_id');
        $map = [];
        foreach ($ids as $id) {
            $map[(int)$id] = true;
        }
        return $map;
    }

    /**
     * 给定主账号集合，返回其全部启用中的子账号 ID。
     */
    public function enabledChildIdsForParents(array $parentIds)
    {
        if (empty($parentIds)) return [];
        if (!(int)config('v2board.sub_account_enable', 1)) return [];
        return SubAccountRelation::whereIn('parent_user_id', $parentIds)
            ->where('status', SubAccountRelation::STATUS_ENABLED)
            ->pluck('child_user_id')
            ->map(function ($v) { return (int)$v; })
            ->toArray();
    }

    /**
     * 健康检查: 用于 --check 与迁移后校验。
     */
    public function healthStats()
    {
        $active = SubAccountRelation::where('status', SubAccountRelation::STATUS_ENABLED)->count();
        $orphan = SubAccountRelation::where(function ($query) {
            $query->whereNotIn('parent_user_id', function ($q) {
                $q->select('id')->from('v2_user');
            })->orWhereNotIn('child_user_id', function ($q) {
                $q->select('id')->from('v2_user');
            });
        })->count();
        $duplicateChild = (int)DB::selectOne(
            'SELECT COUNT(*) AS c FROM (SELECT child_user_id FROM v2_user_sub_accounts GROUP BY child_user_id HAVING COUNT(*) > 1) t'
        )->c;
        $cycles = 0;
        foreach (SubAccountRelation::where('status', SubAccountRelation::STATUS_ENABLED)->get() as $relation) {
            if ($this->wouldCreateCycle($relation->parent_user_id, $relation->child_user_id)) {
                $cycles++;
            }
        }
        return [
            'active_relations' => $active,
            'orphan_relations' => $orphan,
            'duplicate_child' => $duplicateChild,
            'cycles' => $cycles
        ];
    }
}
