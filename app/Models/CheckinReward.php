<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * 签到奖励规则（30 天循环档位）。
 *
 * 被签到日志引用过的规则只允许停用（enabled=0），不允许物理删除。
 */
class CheckinReward extends Model
{
    protected $table = 'v2_checkin_rewards';
    protected $dateFormat = 'U';
    protected $guarded = ['id'];
    protected $casts = [
        'created_at' => 'timestamp',
        'updated_at' => 'timestamp',
    ];

    public function scopeEnabled($query)
    {
        return $query->where('enabled', 1);
    }
}
