<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * 子账号关系表（V2Board 原生实现）。
 *
 * 语义:
 *   - parent_user_id 主账号，child_user_id 子账号，均为真实 v2_user 记录。
 *   - status = 1 启用, 0 停用（解绑只停用，不删除记录）。
 *   - child_user_id 唯一 => 一个账号最多只能作为一个主账号的子账号。
 *   - 重新绑定复用原记录（沿用 id，status 置 1）。
 *
 * 说明: 类名沿用需求书中的 UserSubAccount；App\Models\SubAccountRelation
 * 作为兼容别名保留（历史脚本/测试引用），二者指向同一张表。
 */
class UserSubAccount extends Model
{
    const STATUS_DISABLED = 0;
    const STATUS_ENABLED = 1;

    protected $table = 'v2_user_sub_accounts';
    protected $dateFormat = 'U';
    protected $guarded = ['id'];
    protected $casts = [
        'created_at' => 'timestamp',
        'updated_at' => 'timestamp'
    ];

    public function parent()
    {
        return $this->belongsTo(User::class, 'parent_user_id', 'id');
    }

    public function child()
    {
        return $this->belongsTo(User::class, 'child_user_id', 'id');
    }

    public function scopeEnabled($query)
    {
        return $query->where('status', self::STATUS_ENABLED);
    }

    /**
     * 健康关系: 关系启用 + 主账号与子账号记录都真实存在。
     *
     * 用户被删除后关系行可能变成孤儿（status 仍为 1），这类关系不参与
     * 权益继承、流量记账、周期重置与提醒跳过判断。
     */
    public function scopeHealthy($query)
    {
        return $query->whereHas('parent')->whereHas('child');
    }
}
