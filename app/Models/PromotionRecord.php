<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * 活动弹窗行为记录。
 *
 * 只记录用户行为（展示/关闭/领取/点击/复制优惠码），
 * **不发放任何奖励或优惠券**。
 */
class PromotionRecord extends Model
{
    protected $table = 'v2_promotion_records';
    protected $dateFormat = 'U';
    protected $guarded = ['id'];
    public $timestamps = false;

    const ACTION_VIEW = 'view';
    const ACTION_CLOSE = 'close';
    const ACTION_CLAIM = 'claim';
    const ACTION_CLICK = 'click';
    const ACTION_COPY_COUPON = 'copy_coupon';

    public static function actions()
    {
        return [self::ACTION_VIEW, self::ACTION_CLOSE, self::ACTION_CLAIM, self::ACTION_CLICK, self::ACTION_COPY_COUPON];
    }

    public function popup()
    {
        return $this->belongsTo(PromotionPopup::class, 'promotion_id', 'id');
    }
}
