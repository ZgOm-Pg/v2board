-- ============================================================================
-- V2Board 原生子账号功能 - MySQL 5.7 建表脚本
-- ----------------------------------------------------------------------------
-- 说明:
--   1. 本文件是 sub-account:install --apply 的等价可审查 SQL。
--   2. parent_user_id / child_user_id 的类型必须与目标库 v2_user.id 完全一致。
--      默认按上游 v2board 的 `int(11)` 编写。若目标库 v2_user.id 为其它类型，
--      请以 `php artisan sub-account:install --check` 的检测结果为准，
--      命令会按实际类型生成等价 DDL。
--   3. 目标库 v2_user 引擎为 InnoDB，因此关系表可使用 ON DELETE CASCADE，
--      避免用户被删除后留下孤立关系。审计表刻意不建外键，
--      以保证用户被删除后历史审计仍然保留。
--   4. MySQL 5.7 的 JSON 类型可用。utf8mb4 用于 remark / metadata。
--   5. 重复执行安全（CREATE TABLE IF NOT EXISTS）。
-- ============================================================================

CREATE TABLE IF NOT EXISTS `v2_user_sub_accounts` (
  `id`               BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `parent_user_id`   INT(11)             NOT NULL COMMENT '主账号 v2_user.id',
  `child_user_id`    INT(11)             NOT NULL COMMENT '子账号 v2_user.id',
  `traffic_limit`    BIGINT(20)          NOT NULL DEFAULT 0 COMMENT '子账号个人额度(字节)',
  `remark`           VARCHAR(255)        DEFAULT NULL,
  `status`           TINYINT(4)          NOT NULL DEFAULT 1 COMMENT '1=启用 0=停用',
  `created_by_parent` TINYINT(4)         NOT NULL DEFAULT 0 COMMENT '1=父账号新建 0=绑定已有账号',
  `created_at`       INT(11)             DEFAULT NULL,
  `updated_at`       INT(11)             DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `v2_user_sub_accounts_child_user_id_unique` (`child_user_id`),
  KEY `v2_user_sub_accounts_parent_user_id_status_index` (`parent_user_id`, `status`),
  CONSTRAINT `v2_user_sub_accounts_parent_user_id_foreign`
    FOREIGN KEY (`parent_user_id`) REFERENCES `v2_user` (`id`) ON DELETE CASCADE,
  CONSTRAINT `v2_user_sub_accounts_child_user_id_foreign`
    FOREIGN KEY (`child_user_id`) REFERENCES `v2_user` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='V2Board 子账号关系表';

CREATE TABLE IF NOT EXISTS `v2_sub_account_audit_logs` (
  `id`             BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `relation_id`    BIGINT(20) UNSIGNED DEFAULT NULL,
  `parent_user_id` INT(11)             DEFAULT NULL,
  `child_user_id`  INT(11)             DEFAULT NULL,
  `actor_type`     VARCHAR(20)         NOT NULL DEFAULT 'user' COMMENT 'user|admin|system',
  `actor_user_id`  INT(11)             DEFAULT NULL,
  `action`         VARCHAR(50)         NOT NULL,
  `metadata`       JSON                DEFAULT NULL,
  `ip`             VARCHAR(64)         DEFAULT NULL,
  `created_at`     INT(11)             DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `v2_sub_account_audit_logs_relation_id_index` (`relation_id`),
  KEY `v2_sub_account_audit_logs_parent_user_id_index` (`parent_user_id`),
  KEY `v2_sub_account_audit_logs_child_user_id_index` (`child_user_id`),
  KEY `v2_sub_account_audit_logs_actor_user_id_index` (`actor_user_id`),
  KEY `v2_sub_account_audit_logs_action_index` (`action`),
  KEY `v2_sub_account_audit_logs_created_at_index` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='V2Board 子账号审计日志表（刻意不建外键，删除用户不丢历史）';
