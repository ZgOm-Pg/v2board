<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * 活动弹窗配置。
 */
class PromotionPopup extends Model
{
    protected $table = 'v2_promotion_popups';
    protected $dateFormat = 'U';
    protected $guarded = ['id'];
    protected $casts = [
        'created_at' => 'timestamp',
        'updated_at' => 'timestamp',
    ];

    /** 按钮动作（与 EZ-Theme PromotionPopup.vue 的动作分支一一对应） */
    const ACTION_CLAIM_AND_REDIRECT = 'claim_and_redirect';
    const ACTION_REDIRECT = 'redirect';
    const ACTION_CLAIM = 'claim';
    const ACTION_COPY_COUPON = 'copy_coupon';

    const SCOPE_ALL = 'all';
    const SCOPE_NEW = 'new';
    const SCOPE_PAID = 'paid';
    const SCOPE_UNPAID = 'unpaid';

    public static function actions()
    {
        return [self::ACTION_CLAIM_AND_REDIRECT, self::ACTION_REDIRECT, self::ACTION_CLAIM, self::ACTION_COPY_COUPON];
    }

    public static function scopes()
    {
        return [self::SCOPE_ALL, self::SCOPE_NEW, self::SCOPE_PAID, self::SCOPE_UNPAID];
    }

    public function scopeVisible($query)
    {
        $now = time();
        return $query->where('show', 1)
            ->where(function ($q) use ($now) {
                $q->whereNull('starts_at')->orWhere('starts_at', '<=', $now);
            })
            ->where(function ($q) use ($now) {
                $q->whereNull('ends_at')->orWhere('ends_at', '>=', $now);
            });
    }
}
