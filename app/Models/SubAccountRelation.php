<?php

namespace App\Models;

/**
 * 兼容别名：子账号关系表的早期类名。
 *
 * 实际实现与表定义见 App\Models\UserSubAccount（同一张表 v2_user_sub_accounts）。
 * 保留本类是为了不破坏既有调用方与测试；新代码请直接使用 UserSubAccount。
 */
class SubAccountRelation extends UserSubAccount
{
}
