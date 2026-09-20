<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * 子账号审计日志。
 *
 * 刻意不建外键: 用户或关系被删除后，历史审计仍然保留
 * （只保存用户 ID 与必要快照，不保存密码等敏感信息）。
 */
class SubAccountAuditLog extends Model
{
    const ACTOR_USER = 'user';
    const ACTOR_ADMIN = 'admin';
    const ACTOR_SYSTEM = 'system';

    protected $table = 'v2_sub_account_audit_logs';
    protected $dateFormat = 'U';
    protected $guarded = ['id'];
    public $timestamps = false;
    protected $casts = [
        'created_at' => 'timestamp',
        'metadata' => 'array'
    ];
}
