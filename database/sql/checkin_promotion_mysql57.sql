-- ============================================================================
-- V2Board 每日签到 + 活动弹窗 —— MySQL 5.7 建表脚本（可审查等价版本）
-- ----------------------------------------------------------------------------
-- 说明：
--   1. 与 `database/install.sql` / `database/update.sql` 末尾的独立增量区块内容一致；
--   2. user_id 类型必须与目标库 v2_user.id 完全一致（本项目为 int(11)）；
--   3. 幂等：建表用 CREATE TABLE IF NOT EXISTS，默认规则用 INSERT IGNORE；
--   4. 活动弹窗字段严格对齐 EZ-Theme PromotionPopup.vue 实际消费的 14 个字段，
--      不为 theme 不使用的字段（content/image/starts_at 等）设计列；
--   5. 卸载说明（默认不执行，且不会删除任何数据）：
--        DROP TABLE IF EXISTS `v2_checkin_rewards`;
--        DROP TABLE IF EXISTS `v2_user_checkin_logs`;
--        DROP TABLE IF EXISTS `v2_promotion_popups`;
--        DROP TABLE IF EXISTS `v2_promotion_records`;
-- ============================================================================

-- ---------------------------------------------------------------- 签到奖励规则
CREATE TABLE IF NOT EXISTS `v2_checkin_rewards` (
  `id`           INT(11)      NOT NULL AUTO_INCREMENT,
  `day_index`    INT(11)      NOT NULL COMMENT '连续天数档位（1-30 循环内的第 N 天）',
  `reward_bytes` BIGINT(20)   NOT NULL DEFAULT 0 COMMENT '该档位奖励流量（字节）',
  `reward_text`  VARCHAR(32)  NOT NULL DEFAULT '' COMMENT '展示文案，如 500MB / 2GB',
  `enabled`      TINYINT(4)   NOT NULL DEFAULT 1 COMMENT '1=启用 0=停用（被日志引用时不删除，只停用）',
  `sort`         INT(11)      NOT NULL DEFAULT 0,
  `created_at`   INT(11)      DEFAULT NULL,
  `updated_at`   INT(11)      DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `v2_checkin_rewards_day_index_unique` (`day_index`),
  KEY `v2_checkin_rewards_enabled_sort_index` (`enabled`, `sort`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='签到奖励规则（30 天循环档位）';

-- 默认规则（与原站一致：第 7/14/21/30 天 → 500MB / 2GB / 4GB / 7GB）；幂等，可由后台修改
INSERT IGNORE INTO `v2_checkin_rewards` (`day_index`, `reward_bytes`, `reward_text`, `enabled`, `sort`, `created_at`, `updated_at`) VALUES
  (7,   524288000,  '500MB', 1, 7,  UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),
  (14,  2147483648, '2GB',   1, 14, UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),
  (21,  4294967296, '4GB',   1, 21, UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),
  (30,  7516192768, '7GB',   1, 30, UNIX_TIMESTAMP(), UNIX_TIMESTAMP());

-- ---------------------------------------------------------------- 签到日志
CREATE TABLE IF NOT EXISTS `v2_user_checkin_logs` (
  `id`              BIGINT(20)  NOT NULL AUTO_INCREMENT,
  `user_id`         INT(11)     NOT NULL COMMENT 'v2_user.id',
  `checkin_date`    DATE        NOT NULL COMMENT '签到日期（按签到时区计算的自然日）',
  `continuous_days` INT(11)     NOT NULL DEFAULT 1 COMMENT '本次签到后的连续天数（断签从 1 重算）',
  `day_index`       INT(11)     NOT NULL DEFAULT 1 COMMENT '30 天循环内的档位（1-30）',
  `reward_rule_id`  INT(11)     DEFAULT NULL COMMENT '命中的规则 id（历史导入可能为空）',
  `reward_bytes`    BIGINT(20)  NOT NULL DEFAULT 0 COMMENT '本次实际奖励字节（快照，迁移时保留原值）',
  `reward_text`     VARCHAR(32) NOT NULL DEFAULT '' COMMENT '当时展示文案快照',
  `credited`        TINYINT(4)  NOT NULL DEFAULT 1 COMMENT '1=奖励已计入 transfer_enable 0=未计入（历史导入用）',
  `source`          VARCHAR(16) NOT NULL DEFAULT 'local' COMMENT 'local=本功能写入 migration=停站后历史导入',
  `created_at`      INT(11)     DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `v2_user_checkin_logs_user_date_unique` (`user_id`, `checkin_date`),
  KEY `v2_user_checkin_logs_user_id_index` (`user_id`),
  KEY `v2_user_checkin_logs_checkin_date_index` (`checkin_date`),
  KEY `v2_user_checkin_logs_source_index` (`source`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='用户签到日志（(user_id, checkin_date) 唯一，保证同日只奖励一次）';

-- ---------------------------------------------------------------- 活动弹窗配置
-- 字段严格对齐 EZ-Theme PromotionPopup.vue：title/subtitle/coupon_code/coupon_name/
-- discount_text/button_text/button_url/button_action/show_countdown/ends_at/
-- server_time/cooldown_hours/enabled/id
CREATE TABLE IF NOT EXISTS `v2_promotion_popups` (
  `id`              INT(11)      NOT NULL AUTO_INCREMENT,
  `title`           VARCHAR(128) NOT NULL DEFAULT '',
  `subtitle`        VARCHAR(128) DEFAULT NULL,
  `coupon_code`     VARCHAR(64)  DEFAULT NULL COMMENT '展示用优惠码（只展示/复制，不自动发券）',
  `coupon_name`     VARCHAR(64)  DEFAULT NULL,
  `discount_text`   VARCHAR(64)  DEFAULT NULL,
  `button_text`     VARCHAR(64)  DEFAULT NULL,
  `button_url`      VARCHAR(255) DEFAULT NULL COMMENT '默认 /shop；redirect 时可为外部 http(s) 链接',
  `button_action`   VARCHAR(32)  NOT NULL DEFAULT 'claim_and_redirect' COMMENT 'claim_and_redirect|redirect|claim|copy_coupon',
  `pages`           VARCHAR(64)  NOT NULL DEFAULT 'all' COMMENT '生效页面：all 或 dashboard,shop,checkin,home',
  `user_scope`      VARCHAR(16)  NOT NULL DEFAULT 'all' COMMENT 'all|new|paid|unpaid（服务端筛选，不下发）',
  `sort`            INT(11)      NOT NULL DEFAULT 0,
  `cooldown_hours`  INT(11)      NOT NULL DEFAULT 0 COMMENT '用户关闭后冷却小时数（0=不冷却）',
  `show`            TINYINT(4)   NOT NULL DEFAULT 0 COMMENT '1=启用 0=关闭',
  `starts_at`       INT(11)      DEFAULT NULL COMMENT '生效开始时间（Unix 秒，NULL=不限；仅服务端筛选用）',
  `ends_at`         INT(11)      DEFAULT NULL COMMENT '生效结束时间（Unix 秒，NULL=不显示倒计时）',
  `created_at`      INT(11)      DEFAULT NULL,
  `updated_at`      INT(11)      DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `v2_promotion_popups_show_sort_index` (`show`, `sort`),
  KEY `v2_promotion_popups_time_index` (`starts_at`, `ends_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='活动弹窗配置';

-- ---------------------------------------------------------------- 活动行为记录
CREATE TABLE IF NOT EXISTS `v2_promotion_records` (
  `id`           BIGINT(20)   NOT NULL AUTO_INCREMENT,
  `promotion_id` INT(11)      NOT NULL,
  `user_id`      INT(11)      NOT NULL,
  `action`       VARCHAR(32)  NOT NULL COMMENT 'view|close|click|claim（claim 只记录行为，不真正发券）',
  `channel`      VARCHAR(32)  DEFAULT NULL COMMENT '触发页面，如 dashboard/shop/checkin/home',
  `metadata`     TEXT         DEFAULT NULL COMMENT '附加信息（JSON 字符串）',
  `ip`           VARCHAR(64)  DEFAULT NULL,
  `created_at`   INT(11)      DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `v2_promotion_records_user_index` (`user_id`, `promotion_id`),
  KEY `v2_promotion_records_action_index` (`action`),
  KEY `v2_promotion_records_created_at_index` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='活动弹窗行为记录（只记录行为，不发放任何奖励/优惠券）';
