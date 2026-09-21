<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * 用户签到日志。
 *
 * (user_id, checkin_date) 唯一：同一天只可能产生一条记录，
 * 并发/重复领取由数据库唯一索引兜底。
 */
class UserCheckinLog extends Model
{
    protected $table = 'v2_user_checkin_logs';
    protected $dateFormat = 'U';
    protected $guarded = ['id'];
    public $timestamps = false;

    const SOURCE_LOCAL = 'local';
    const SOURCE_MIGRATION = 'migration';

    protected $casts = [
        'created_at' => 'timestamp',
    ];

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id', 'id');
    }
}
