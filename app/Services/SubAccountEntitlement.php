<?php

namespace App\Services;

use App\Models\Plan;
use App\Models\SubAccountRelation;
use App\Models\User;

/**
 * 有效订阅授权快照（不可变值对象）。
 *
 * 设计约束（对应需求书第九节）:
 *   - 只在此处集中计算继承关系，绝不临时覆盖 $user->group_id 等 Eloquent 属性；
 *   - 不使用 Accessor 伪装继承属性；
 *   - 不使用全局 Scope 改写所有用户查询；
 *   - 普通用户（非子账号）的 effective_* 恒等于自身字段，行为与改动前完全一致。
 *
 * 所有属性只读：只有 private 属性 + getter，没有任何 setter。
 */
class SubAccountEntitlement
{
    /** @var bool */
    private $isSubAccount;
    /** @var User|null 子账号自身 */
    private $user;
    /** @var User|null 主账号 */
    private $parentUser;
    /** @var SubAccountRelation|null */
    private $relation;

    private $effectivePlanId;
    private $effectiveGroupId;
    private $effectiveExpiredAt;
    private $effectiveSpeedLimit;
    private $effectiveDeviceLimit;

    private $childTrafficLimit;
    private $childUsed;
    private $parentTransferEnable;
    private $parentUsed;

    private $relationEnabled;
    private $parentBanned;
    private $childBanned;
    private $parentHasPlan;
    private $parentExpired;
    private $childQuotaExceeded;
    private $parentQuotaExceeded;
    /** 仅供普通用户使用: 严格镜像改造前的 UserService::isAvailable() 语义 */
    private $normalAvailable;
    /** 主账号套餐的流量重置方式（0-4，null 表示未设置） */
    private $effectiveResetMethod;
    /** 订阅是否可用（有效套餐 + 未过期 + 未封禁 + 未耗尽 + 关系启用） */
    private $subscriptionAvailable;

    private function __construct()
    {
    }

    /**
     * 普通用户（含管理员/员工）：完全沿用自身字段。
     */
    public static function forNormalUser(User $user)
    {
        $e = new self();
        $e->isSubAccount = false;
        $e->user = $user;
        $e->parentUser = null;
        $e->relation = null;

        $e->effectivePlanId = $user->plan_id;
        $e->effectiveGroupId = $user->group_id;
        $e->effectiveExpiredAt = $user->expired_at;
        $e->effectiveSpeedLimit = $user->speed_limit;
        $e->effectiveDeviceLimit = $user->device_limit;

        $e->childTrafficLimit = (int)$user->transfer_enable;
        $e->childUsed = (int)$user->u + (int)$user->d;
        $e->parentTransferEnable = (int)$user->transfer_enable;
        $e->parentUsed = (int)$user->u + (int)$user->d;

        $e->relationEnabled = true;
        $e->parentBanned = (bool)$user->banned;
        $e->childBanned = (bool)$user->banned;
        $e->parentHasPlan = $user->plan_id !== null;
        $e->parentExpired = !($user->expired_at === null || (int)$user->expired_at > time());
        $e->childQuotaExceeded = $e->childUsed >= $e->parentTransferEnable;
        $e->parentQuotaExceeded = $e->childQuotaExceeded;
        // 普通用户保持改造前的行为: 不看额度是否用完，只看封禁/有额度/未过期。
        $e->normalAvailable = !$user->banned
            && (int)$user->transfer_enable > 0
            && ($user->expired_at === null || (int)$user->expired_at > time());
        $e->effectiveResetMethod = self::resolveResetMethod($user);
        $e->subscriptionAvailable = $e->canConnect();
        return $e;
    }

    /**
     * 主账号套餐的流量重置方式（v2_plan.reset_traffic_method）。
     * 普通用户取自身套餐，子账号取主账号套餐；无套餐返回 null。
     */
    private static function resolveResetMethod($user)
    {
        if (!$user || $user->plan_id === null) return null;
        $plan = Plan::find($user->plan_id);
        if (!$plan) return null;
        return $plan->reset_traffic_method === null ? null : (int)$plan->reset_traffic_method;
    }

    /**
     * 子账号：运行时继承主账号的套餐/权限组/到期/限速/设备限制。
     *
     * @param User $child 子账号（其自身 u/d 为个人用量）
     * @param User|null $parent 主账号
     * @param SubAccountRelation|null $relation
     */
    public static function forSubAccount(User $child, $parent, $relation)
    {
        $e = new self();
        $e->isSubAccount = true;
        $e->user = $child;
        $e->parentUser = $parent;
        $e->relation = $relation;

        $e->childUsed = (int)$child->u + (int)$child->d;
        $e->childTrafficLimit = $relation ? (int)$relation->traffic_limit : 0;

        if (!$parent || !$relation) {
            // 关系或主账号缺失：不可用，且不继承任何权限。
            $e->effectivePlanId = null;
            $e->effectiveGroupId = null;
            $e->effectiveExpiredAt = 0;
            $e->effectiveSpeedLimit = null;
            $e->effectiveDeviceLimit = null;
            $e->parentTransferEnable = 0;
            $e->parentUsed = 0;
            $e->relationEnabled = false;
            $e->parentBanned = true;
            $e->childBanned = (bool)$child->banned;
            $e->parentHasPlan = false;
            $e->parentExpired = true;
            $e->childQuotaExceeded = true;
            $e->parentQuotaExceeded = true;
            $e->normalAvailable = false;
            $e->effectiveResetMethod = null;
            $e->subscriptionAvailable = false;
            return $e;
        }

        $e->effectivePlanId = $parent->plan_id;
        $e->effectiveGroupId = $parent->group_id;
        $e->effectiveExpiredAt = $parent->expired_at;
        $e->effectiveSpeedLimit = $parent->speed_limit;
        $e->effectiveDeviceLimit = $parent->device_limit;

        $e->parentTransferEnable = (int)$parent->transfer_enable;
        $e->parentUsed = (int)$parent->u + (int)$parent->d;

        $e->relationEnabled = (int)$relation->status === SubAccountRelation::STATUS_ENABLED;
        $e->parentBanned = (bool)$parent->banned;
        $e->childBanned = (bool)$child->banned;
        $e->parentHasPlan = $parent->plan_id !== null;
        $e->parentExpired = !($parent->expired_at === null || (int)$parent->expired_at > time());
        // traffic_limit <= 0 表示不设个人额度（仍受主账号共享额度约束）。
        $e->childQuotaExceeded = $e->childTrafficLimit > 0 && $e->childUsed >= $e->childTrafficLimit;
        $e->parentQuotaExceeded = $e->parentUsed >= $e->parentTransferEnable;
        $e->normalAvailable = false;
        $e->effectiveResetMethod = self::resolveResetMethod($parent);
        $e->subscriptionAvailable = $e->canConnect();

        return $e;
    }

    public function isSubAccount()
    {
        return $this->isSubAccount;
    }

    public function getUser()
    {
        return $this->user;
    }

    public function getParentUser()
    {
        return $this->parentUser;
    }

    public function getRelation()
    {
        return $this->relation;
    }

    public function getEffectivePlanId()
    {
        return $this->effectivePlanId;
    }

    public function getEffectiveGroupId()
    {
        return $this->effectiveGroupId;
    }

    public function getEffectiveExpiredAt()
    {
        return $this->effectiveExpiredAt;
    }

    public function getEffectiveSpeedLimit()
    {
        return $this->effectiveSpeedLimit;
    }

    public function getEffectiveDeviceLimit()
    {
        return $this->effectiveDeviceLimit;
    }

    public function getChildTrafficLimit()
    {
        return $this->childTrafficLimit;
    }

    public function getChildUsed()
    {
        return $this->childUsed;
    }

    public function getParentTransferEnable()
    {
        return $this->parentTransferEnable;
    }

    public function getParentUsed()
    {
        return $this->parentUsed;
    }

    /**
     * 子账号是否具备连接（取节点/订阅）资格。
     *
     * 必须同时满足（需求书第十二节）:
     *   关系启用 / 父子均未封禁 / 主账号有套餐 / 主账号未过期 /
     *   子账号个人额度未超 / 主账号共享额度未超。
     */
    public function canConnect()
    {
        if (!$this->isSubAccount) {
            // 普通用户必须与改造前的 UserService::isAvailable() 完全一致（需求书第九节）。
            return $this->normalAvailable;
        }
        return $this->relationEnabled
            && !$this->parentBanned
            && !$this->childBanned
            && $this->parentHasPlan
            && !$this->parentExpired
            && !$this->childQuotaExceeded
            && !$this->parentQuotaExceeded;
    }

    /** 用于订阅/节点判定: 是否具备有效套餐（等价于原 isAvailable 的语义） */
    public function isAvailable()
    {
        return $this->canConnect();
    }

    /**
     * 关系是否启用（普通用户恒为 true）。
     */
    public function isRelationEnabled()
    {
        return $this->relationEnabled;
    }

    /** 主账号套餐的流量重置方式（0-4；null 表示未设置/无套餐） */
    public function getEffectiveResetMethod()
    {
        return $this->effectiveResetMethod;
    }

    /** 主账号共享流量池剩余字节（不小于 0） */
    public function getParentRemainingTraffic()
    {
        return max(0, $this->parentTransferEnable - $this->parentUsed);
    }

    /**
     * 子账号个人额度剩余字节。
     *
     * traffic_limit=0 表示不设个人额度，此时返回主账号池剩余（即不受个人额度限制）。
     */
    public function getChildRemainingTraffic()
    {
        if ($this->childTrafficLimit <= 0) {
            return $this->getParentRemainingTraffic();
        }
        return max(0, $this->childTrafficLimit - $this->childUsed);
    }

    /** 有效可用剩余流量 = min(个人额度剩余, 主账号池剩余) */
    public function getRemainingTraffic()
    {
        return min($this->getChildRemainingTraffic(), $this->getParentRemainingTraffic());
    }

    /** 订阅是否可用（与 canConnect 同义，供 API/管理端展示） */
    public function isSubscriptionAvailable()
    {
        return $this->subscriptionAvailable;
    }

    /**
     * 供 API / 日志使用的只读快照。
     */
    public function toArray()
    {
        return [
            'is_sub_account' => $this->isSubAccount,
            'parent_user_id' => $this->parentUser ? (int)$this->parentUser->id : null,
            'effective_plan_id' => $this->effectivePlanId,
            'effective_group_id' => $this->effectiveGroupId,
            'effective_expired_at' => $this->effectiveExpiredAt,
            'effective_speed_limit' => $this->effectiveSpeedLimit,
            'effective_device_limit' => $this->effectiveDeviceLimit,
            'child_traffic_limit' => $this->childTrafficLimit,
            'child_used' => $this->childUsed,
            'parent_transfer_enable' => $this->parentTransferEnable,
            'parent_used' => $this->parentUsed,
            'effective_reset_method' => $this->effectiveResetMethod,
            'parent_remaining_traffic' => $this->getParentRemainingTraffic(),
            'child_remaining_traffic' => $this->getChildRemainingTraffic(),
            'remaining_traffic' => $this->getRemainingTraffic(),
            'subscription_available' => $this->subscriptionAvailable,
            'can_connect' => $this->canConnect()
        ];
    }
}
