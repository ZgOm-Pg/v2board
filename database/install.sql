-- Adminer 4.8.1 MySQL 5.7.29 dump

SET NAMES utf8;
SET time_zone = '+00:00';
SET foreign_key_checks = 0;
SET sql_mode = 'NO_AUTO_VALUE_ON_ZERO';

SET NAMES utf8mb4;

DROP TABLE IF EXISTS `failed_jobs`;
CREATE TABLE `failed_jobs` (
                               `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                               `connection` text COLLATE utf8mb4_unicode_ci NOT NULL,
                               `queue` text COLLATE utf8mb4_unicode_ci NOT NULL,
                               `payload` longtext COLLATE utf8mb4_unicode_ci NOT NULL,
                               `exception` longtext COLLATE utf8mb4_unicode_ci NOT NULL,
                               `failed_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
                               PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


DROP TABLE IF EXISTS `v2_commission_log`;
CREATE TABLE `v2_commission_log` (
                                     `id` int(11) NOT NULL AUTO_INCREMENT,
                                     `invite_user_id` int(11) NOT NULL,
                                     `user_id` int(11) NOT NULL,
                                     `trade_no` char(36) NOT NULL,
                                     `order_amount` int(11) NOT NULL,
                                     `get_amount` int(11) NOT NULL,
                                     `created_at` int(11) NOT NULL,
                                     `updated_at` int(11) NOT NULL,
                                     PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


DROP TABLE IF EXISTS `v2_coupon`;
CREATE TABLE `v2_coupon` (
                             `id` int(11) NOT NULL AUTO_INCREMENT,
                             `code` varchar(255) NOT NULL,
                             `name` varchar(255) CHARACTER SET utf8mb4 NOT NULL,
                             `type` tinyint(1) NOT NULL,
                             `value` int(11) NOT NULL,
                             `show` tinyint(1) NOT NULL DEFAULT '0',
                             `limit_use` int(11) DEFAULT NULL,
                             `limit_use_with_user` int(11) DEFAULT NULL,
                             `limit_plan_ids` varchar(255) DEFAULT NULL,
                             `limit_period` varchar(255) DEFAULT NULL,
                             `started_at` int(11) NOT NULL,
                             `ended_at` int(11) NOT NULL,
                             `created_at` int(11) NOT NULL,
                             `updated_at` int(11) NOT NULL,
                             PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;


DROP TABLE IF EXISTS `v2_giftcard`;
CREATE TABLE `v2_giftcard` (
                             `id` int(11) NOT NULL AUTO_INCREMENT,
                             `code` varchar(255) NOT NULL,
                             `name` varchar(255) CHARACTER SET utf8mb4 NOT NULL,
                             `type` tinyint(1) NOT NULL,
                             `value` int(11) DEFAULT NULL,
                             `plan_id` int(11) DEFAULT NULL,
                             `limit_use` int(11) DEFAULT NULL,
                             `used_user_ids` varchar(16384) DEFAULT NULL,
                             `started_at` int(11) NOT NULL,
                             `ended_at` int(11) NOT NULL,
                             `created_at` int(11) NOT NULL,
                             `updated_at` int(11) NOT NULL,
                             PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;


DROP TABLE IF EXISTS `v2_invite_code`;
CREATE TABLE `v2_invite_code` (
                                  `id` int(11) NOT NULL AUTO_INCREMENT,
                                  `user_id` int(11) NOT NULL,
                                  `code` char(32) NOT NULL,
                                  `status` tinyint(1) NOT NULL DEFAULT '0',
                                  `pv` int(11) NOT NULL DEFAULT '0',
                                  `created_at` int(11) NOT NULL,
                                  `updated_at` int(11) NOT NULL,
                                  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;


DROP TABLE IF EXISTS `v2_knowledge`;
CREATE TABLE `v2_knowledge` (
                                `id` int(11) NOT NULL AUTO_INCREMENT,
                                `language` char(5) NOT NULL COMMENT '語言',
                                `category` varchar(255) NOT NULL COMMENT '分類名',
                                `title` varchar(255) NOT NULL COMMENT '標題',
                                `body` text NOT NULL COMMENT '內容',
                                `sort` int(11) DEFAULT NULL COMMENT '排序',
                                `show` tinyint(1) NOT NULL DEFAULT '0' COMMENT '顯示',
                                `created_at` int(11) NOT NULL COMMENT '創建時間',
                                `updated_at` int(11) NOT NULL COMMENT '更新時間',
                                PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='知識庫';


DROP TABLE IF EXISTS `v2_log`;
CREATE TABLE `v2_log` (
                          `id` int(11) NOT NULL AUTO_INCREMENT,
                          `title` text NOT NULL,
                          `level` varchar(11) DEFAULT NULL,
                          `host` varchar(255) DEFAULT NULL,
                          `uri` varchar(255) NOT NULL,
                          `method` varchar(11) NOT NULL,
                          `data` text,
                          `ip` varchar(128) DEFAULT NULL,
                          `context` text,
                          `created_at` int(11) NOT NULL,
                          `updated_at` int(11) NOT NULL,
                          PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


DROP TABLE IF EXISTS `v2_mail_log`;
CREATE TABLE `v2_mail_log` (
                               `id` int(11) NOT NULL AUTO_INCREMENT,
                               `email` varchar(64) NOT NULL,
                               `subject` varchar(255) NOT NULL,
                               `template_name` varchar(255) NOT NULL,
                               `error` text,
                               `created_at` int(11) NOT NULL,
                               `updated_at` int(11) NOT NULL,
                               PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


DROP TABLE IF EXISTS `v2_notice`;
CREATE TABLE `v2_notice` (
                             `id` int(11) NOT NULL AUTO_INCREMENT,
                             `title` varchar(255) NOT NULL,
                             `content` text NOT NULL,
                             `show` tinyint(1) NOT NULL DEFAULT '0',
                             `img_url` varchar(255) DEFAULT NULL,
                             `tags` varchar(255) DEFAULT NULL,
                             `created_at` int(11) NOT NULL,
                             `updated_at` int(11) NOT NULL,
                             PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;


DROP TABLE IF EXISTS `v2_order`;
CREATE TABLE `v2_order` (
                            `id` int(11) NOT NULL AUTO_INCREMENT,
                            `invite_user_id` int(11) DEFAULT NULL,
                            `user_id` int(11) NOT NULL,
                            `plan_id` int(11) NOT NULL,
                            `coupon_id` int(11) DEFAULT NULL,
                            `payment_id` int(11) DEFAULT NULL,
                            `type` int(11) NOT NULL COMMENT '1新购2续费3升级',
                            `period` varchar(255) NOT NULL,
                            `trade_no` varchar(36) NOT NULL,
                            `callback_no` varchar(255) DEFAULT NULL,
                            `total_amount` int(11) NOT NULL,
                            `handling_amount` int(11) DEFAULT NULL,
                            `discount_amount` int(11) DEFAULT NULL,
                            `surplus_amount` int(11) DEFAULT NULL COMMENT '剩余价值',
                            `refund_amount` int(11) DEFAULT NULL COMMENT '退款金额',
                            `balance_amount` int(11) DEFAULT NULL COMMENT '使用余额',
                            `surplus_order_ids` text COMMENT '折抵订单',
                            `status` tinyint(1) NOT NULL DEFAULT '0' COMMENT '0待支付1开通中2已取消3已完成4已折抵',
                            `commission_status` tinyint(1) NOT NULL DEFAULT '0' COMMENT '0待确认1发放中2有效3无效',
                            `commission_balance` int(11) NOT NULL DEFAULT '0',
                            `actual_commission_balance` int(11) DEFAULT NULL COMMENT '实际支付佣金',
                            `paid_at` int(11) DEFAULT NULL,
                            `created_at` int(11) NOT NULL,
                            `updated_at` int(11) NOT NULL,
                            PRIMARY KEY (`id`),
                            UNIQUE KEY `trade_no` (`trade_no`),
                            INDEX idx_user (`user_id`),
                            INDEX idx_user_status (`user_id`, `status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;


DROP TABLE IF EXISTS `v2_payment`;
CREATE TABLE `v2_payment` (
                              `id` int(11) NOT NULL AUTO_INCREMENT,
                              `uuid` char(32) NOT NULL,
                              `payment` varchar(16) NOT NULL,
                              `name` varchar(255) NOT NULL,
                              `icon` varchar(255) DEFAULT NULL,
                              `config` text NOT NULL,
                              `notify_domain` varchar(128) DEFAULT NULL,
                              `handling_fee_fixed` int(11) DEFAULT NULL,
                              `handling_fee_percent` decimal(5,2) DEFAULT NULL,
                              `enable` tinyint(1) NOT NULL DEFAULT '0',
                              `sort` int(11) DEFAULT NULL,
                              `created_at` int(11) NOT NULL,
                              `updated_at` int(11) NOT NULL,
                              PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


DROP TABLE IF EXISTS `v2_plan`;
CREATE TABLE `v2_plan` (
                           `id` int(11) NOT NULL AUTO_INCREMENT,
                           `group_id` int(11) NOT NULL,
                           `transfer_enable` int(11) NOT NULL,
                           `device_limit` int(11) DEFAULT NULL,
                           `name` varchar(255) NOT NULL,
                           `speed_limit` int(11) DEFAULT NULL,
                           `show` tinyint(1) NOT NULL DEFAULT '0',
                           `sort` int(11) DEFAULT NULL,
                           `renew` tinyint(1) NOT NULL DEFAULT '1',
                           `content` text,
                           `month_price` int(11) DEFAULT NULL,
                           `quarter_price` int(11) DEFAULT NULL,
                           `half_year_price` int(11) DEFAULT NULL,
                           `year_price` int(11) DEFAULT NULL,
                           `two_year_price` int(11) DEFAULT NULL,
                           `three_year_price` int(11) DEFAULT NULL,
                           `onetime_price` int(11) DEFAULT NULL,
                           `reset_price` int(11) DEFAULT NULL,
                           `reset_traffic_method` tinyint(1) DEFAULT NULL,
                           `capacity_limit` int(11) DEFAULT NULL,
                           `created_at` int(11) NOT NULL,
                           `updated_at` int(11) NOT NULL,
                           PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


DROP TABLE IF EXISTS `v2_server_group`;
CREATE TABLE `v2_server_group` (
                                   `id` int(11) NOT NULL AUTO_INCREMENT,
                                   `name` varchar(255) NOT NULL,
                                   `created_at` int(11) NOT NULL,
                                   `updated_at` int(11) NOT NULL,
                                   PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;


DROP TABLE IF EXISTS `v2_server_tuic`;
CREATE TABLE `v2_server_tuic` (
                                      `id` int(11) NOT NULL AUTO_INCREMENT,
                                      `group_id` varchar(255) NOT NULL,
                                      `route_id` varchar(255) DEFAULT NULL,
                                      `name` varchar(255) NOT NULL,
                                      `parent_id` int(11) DEFAULT NULL,
                                      `host` varchar(255) NOT NULL,
                                      `port` varchar(11) NOT NULL,
                                      `server_port` int(11) NOT NULL,
                                      `tags` varchar(255) DEFAULT NULL,
                                      `rate` varchar(11) NOT NULL,
                                      `show` tinyint(1) NOT NULL DEFAULT '0',
                                      `sort` int(11) DEFAULT NULL,
                                      `server_name` varchar(64) DEFAULT NULL,
                                      `insecure` tinyint(1) NOT NULL DEFAULT '0',
                                      `disable_sni` tinyint(1) NOT NULL DEFAULT '0',
                                      `udp_relay_mode` varchar(64) DEFAULT NULL,
                                      `zero_rtt_handshake` tinyint(1) NOT NULL DEFAULT '0',
                                      `congestion_control` varchar(64) DEFAULT NULL,
                                      `created_at` int(11) NOT NULL,
                                      `updated_at` int(11) NOT NULL,
                                      PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


DROP TABLE IF EXISTS `v2_server_hysteria`;
CREATE TABLE `v2_server_hysteria` (
                                      `id` int(11) NOT NULL AUTO_INCREMENT,
                                      `version` int(11) NOT NULL,
                                      `group_id` varchar(255) NOT NULL,
                                      `route_id` varchar(255) DEFAULT NULL,
                                      `name` varchar(255) NOT NULL,
                                      `parent_id` int(11) DEFAULT NULL,
                                      `host` varchar(255) NOT NULL,
                                      `port` varchar(11) NOT NULL,
                                      `server_port` int(11) NOT NULL,
                                      `tags` varchar(255) DEFAULT NULL,
                                      `rate` varchar(11) NOT NULL,
                                      `show` tinyint(1) NOT NULL DEFAULT '0',
                                      `sort` int(11) DEFAULT NULL,
                                      `up_mbps` int(11) NOT NULL,
                                      `down_mbps` int(11) NOT NULL,
                                      `obfs` varchar(64) DEFAULT NULL,
                                      `obfs_password` varchar(255) DEFAULT NULL,
                                      `server_name` varchar(64) DEFAULT NULL,
                                      `insecure` tinyint(1) NOT NULL DEFAULT '0',
                                      `created_at` int(11) NOT NULL,
                                      `updated_at` int(11) NOT NULL,
                                      PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


DROP TABLE IF EXISTS `v2_server_route`;
CREATE TABLE `v2_server_route` (
                                   `id` int(11) NOT NULL AUTO_INCREMENT,
                                   `remarks` varchar(255) NOT NULL,
                                   `match` text NOT NULL,
                                   `action` varchar(11) NOT NULL,
                                   `action_value` text DEFAULT NULL,
                                   `created_at` int(11) NOT NULL,
                                   `updated_at` int(11) NOT NULL,
                                   PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


DROP TABLE IF EXISTS `v2_server_shadowsocks`;
CREATE TABLE `v2_server_shadowsocks` (
                                         `id` int(11) NOT NULL AUTO_INCREMENT,
                                         `group_id` varchar(255) NOT NULL,
                                         `route_id` varchar(255) DEFAULT NULL,
                                         `parent_id` int(11) DEFAULT NULL,
                                         `tags` varchar(255) DEFAULT NULL,
                                         `name` varchar(255) NOT NULL,
                                         `rate` varchar(11) NOT NULL,
                                         `host` varchar(255) NOT NULL,
                                         `port` varchar(11) NOT NULL,
                                         `server_port` int(11) NOT NULL,
                                         `cipher` varchar(255) NOT NULL,
                                         `obfs` char(11) DEFAULT NULL,
                                         `obfs_settings` varchar(255) DEFAULT NULL,
                                         `show` tinyint(4) NOT NULL DEFAULT '0',
                                         `sort` int(11) DEFAULT NULL,
                                         `created_at` int(11) NOT NULL,
                                         `updated_at` int(11) NOT NULL,
                                         PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


DROP TABLE IF EXISTS `v2_server_trojan`;
CREATE TABLE `v2_server_trojan` (
                                    `id` int(11) NOT NULL AUTO_INCREMENT COMMENT '节点ID',
                                    `group_id` varchar(255) NOT NULL COMMENT '节点组',
                                    `route_id` varchar(255) DEFAULT NULL,
                                    `parent_id` int(11) DEFAULT NULL COMMENT '父节点',
                                    `tags` varchar(255) DEFAULT NULL COMMENT '节点标签',
                                    `name` varchar(255) NOT NULL COMMENT '节点名称',
                                    `rate` varchar(11) NOT NULL COMMENT '倍率',
                                    `host` varchar(255) NOT NULL COMMENT '主机名',
                                    `port` varchar(11) NOT NULL COMMENT '连接端口',
                                    `server_port` int(11) NOT NULL COMMENT '服务端口',
                                    `network` varchar(11) DEFAULT NULL COMMENT '传输方式',
                                    `network_settings` text COMMENT '传输配置',
                                    `allow_insecure` tinyint(1) NOT NULL DEFAULT '0' COMMENT '是否允许不安全',
                                    `server_name` varchar(255) DEFAULT NULL,
                                    `show` tinyint(1) NOT NULL DEFAULT '0' COMMENT '是否显示',
                                    `sort` int(11) DEFAULT NULL,
                                    `created_at` int(11) NOT NULL,
                                    `updated_at` int(11) NOT NULL,
                                    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='trojan伺服器表';


DROP TABLE IF EXISTS `v2_server_vless`;
CREATE TABLE `v2_server_vless` (
                                   `id` int(11) NOT NULL AUTO_INCREMENT,
                                   `group_id` text NOT NULL,
                                   `route_id` text,
                                   `name` varchar(255) NOT NULL,
                                   `parent_id` int(11) DEFAULT NULL,
                                   `host` varchar(255) NOT NULL,
                                   `port` int(11) NOT NULL,
                                   `server_port` int(11) NOT NULL,
                                   `tls` tinyint(1) NOT NULL,
                                   `tls_settings` text,
                                   `flow` varchar(64) DEFAULT NULL,
                                   `network` varchar(11) NOT NULL,
                                   `network_settings` text,
                                   `encryption` varchar(64) DEFAULT NULL,
                                   `encryption_settings` text,
                                   `tags` text,
                                   `rate` varchar(11) NOT NULL,
                                   `show` tinyint(1) NOT NULL DEFAULT '0',
                                   `sort` int(11) DEFAULT NULL,
                                   `created_at` int(11) NOT NULL,
                                   `updated_at` int(11) NOT NULL,
                                   PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


DROP TABLE IF EXISTS `v2_server_vmess`;
CREATE TABLE `v2_server_vmess` (
                                   `id` int(11) NOT NULL AUTO_INCREMENT,
                                   `group_id` varchar(255) NOT NULL,
                                   `route_id` varchar(255) DEFAULT NULL,
                                   `name` varchar(255) NOT NULL,
                                   `parent_id` int(11) DEFAULT NULL,
                                   `host` varchar(255) NOT NULL,
                                   `port` varchar(11) NOT NULL,
                                   `server_port` int(11) NOT NULL,
                                   `tls` tinyint(4) NOT NULL DEFAULT '0',
                                   `tags` varchar(255) DEFAULT NULL,
                                   `rate` varchar(11) NOT NULL,
                                   `network` varchar(11) NOT NULL,
                                   `rules` text,
                                   `networkSettings` text,
                                   `tlsSettings` text,
                                   `ruleSettings` text,
                                   `dnsSettings` text,
                                   `show` tinyint(1) NOT NULL DEFAULT '0',
                                   `sort` int(11) DEFAULT NULL,
                                   `created_at` int(11) NOT NULL,
                                   `updated_at` int(11) NOT NULL,
                                   PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

DROP TABLE IF EXISTS `v2_server_anytls`;
CREATE TABLE `v2_server_anytls` (
                                      `id` int(11) NOT NULL AUTO_INCREMENT,
                                      `group_id` varchar(255) NOT NULL,
                                      `route_id` varchar(255) DEFAULT NULL,
                                      `name` varchar(255) NOT NULL,
                                      `parent_id` int(11) DEFAULT NULL,
                                      `host` varchar(255) NOT NULL,
                                      `port` varchar(11) NOT NULL,
                                      `server_port` int(11) NOT NULL,
                                      `tags` varchar(255) DEFAULT NULL,
                                      `rate` varchar(11) NOT NULL,
                                      `show` tinyint(1) NOT NULL DEFAULT '0',
                                      `sort` int(11) DEFAULT NULL,
                                      `server_name` varchar(64) DEFAULT NULL,
                                      `insecure` tinyint(1) NOT NULL DEFAULT '0',
                                      `padding_scheme` text,
                                      `created_at` int(11) NOT NULL,
                                      `updated_at` int(11) NOT NULL,
                                      PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

DROP TABLE IF EXISTS `v2_server_v2node`;
CREATE TABLE `v2_server_v2node` (
                                    `id` int(11) NOT NULL AUTO_INCREMENT,
                                    `group_id` varchar(255) NOT NULL,
                                    `route_id` varchar(255) DEFAULT NULL,
                                    `name` varchar(255) NOT NULL,
                                    `parent_id` int(11) DEFAULT NULL,
                                    `host` varchar(255) NOT NULL,
                                    `listen_ip` varchar(255) NOT NULL DEFAULT '0.0.0.0',
                                    `port` varchar(11) NOT NULL,
                                    `server_port` int(11) NOT NULL,
                                    `tags` varchar(255) DEFAULT NULL,
                                    `rate` varchar(11) NOT NULL,
                                    `show` tinyint(1) NOT NULL DEFAULT '0',
                                    `sort` int(11) DEFAULT NULL,
                                    `protocol` varchar(24) NOT NULL COMMENT '协议类型',
                                    `tls` tinyint(1) NOT NULL COMMENT 'tls类型',
                                    `tls_settings` text COMMENT 'tls配置',
                                    `flow` varchar(64) DEFAULT NULL COMMENT 'vless流控',
                                    `network` varchar(11) NOT NULL COMMENT '传输类型',
                                    `network_settings` text COMMENT '传输配置',
                                    `trusted_x_forwarded_for` varchar(255) DEFAULT NULL COMMENT '信任的x-forwarded-for头部',
                                    `encryption` varchar(64) DEFAULT NULL COMMENT 'vless加密',
                                    `encryption_settings` text COMMENT 'vless加密配置',
                                    `disable_sni` tinyint(1) NOT NULL DEFAULT '0' COMMENT 'tuic禁用sni',
                                    `udp_relay_mode` varchar(64) DEFAULT NULL COMMENT 'tuic udp中继模式',
                                    `zero_rtt_handshake` tinyint(1) NOT NULL DEFAULT '0' COMMENT 'tuic 0rtt握手',
                                    `congestion_control` varchar(64) DEFAULT NULL COMMENT 'tuic拥塞控制',
                                    `cipher` varchar(64) DEFAULT NULL COMMENT 'shadowsocks加密方式',
                                    `up_mbps` int(11) NOT NULL COMMENT 'hysteria上行带宽',
                                    `down_mbps` int(11) NOT NULL COMMENT 'hysteria下行带宽',
                                    `obfs` varchar(64) DEFAULT NULL COMMENT 'hysteria1混淆密码/hysteria2混淆类型',
                                    `obfs_password` varchar(255) DEFAULT NULL COMMENT 'hysteria2混淆密码',
                                    `padding_scheme` text COMMENT 'anytls填充配置',
                                    `created_at` int(11) NOT NULL,
                                    `updated_at` int(11) NOT NULL,
                                    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

DROP TABLE IF EXISTS `v2_stat`;
CREATE TABLE `v2_stat` (
                           `id` int(11) NOT NULL AUTO_INCREMENT,
                           `record_at` int(11) NOT NULL,
                           `record_type` char(1) NOT NULL,
                           `order_count` int(11) NOT NULL COMMENT '订单数量',
                           `order_total` int(11) NOT NULL COMMENT '订单合计',
                           `commission_count` int(11) NOT NULL,
                           `commission_total` int(11) NOT NULL COMMENT '佣金合计',
                           `paid_count` int(11) NOT NULL,
                           `paid_total` int(11) NOT NULL,
                           `register_count` int(11) NOT NULL,
                           `invite_count` int(11) NOT NULL,
                           `transfer_used_total` varchar(32) NOT NULL,
                           `created_at` int(11) NOT NULL,
                           `updated_at` int(11) NOT NULL,
                           PRIMARY KEY (`id`),
                           UNIQUE KEY `record_at` (`record_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COMMENT='订单统计';


DROP TABLE IF EXISTS `v2_stat_server`;
CREATE TABLE `v2_stat_server` (
                                  `id` int(11) NOT NULL AUTO_INCREMENT,
                                  `server_id` int(11) NOT NULL COMMENT '节点id',
                                  `server_type` char(11) NOT NULL COMMENT '节点类型',
                                  `u` bigint(20) NOT NULL,
                                  `d` bigint(20) NOT NULL,
                                  `record_type` char(1) NOT NULL COMMENT 'd day m month',
                                  `record_at` int(11) NOT NULL COMMENT '记录时间',
                                  `created_at` int(11) NOT NULL,
                                  `updated_at` int(11) NOT NULL,
                                  PRIMARY KEY (`id`),
                                  UNIQUE KEY `server_id_server_type_record_at` (`server_id`,`server_type`,`record_at`),
                                  KEY `record_at` (`record_at`),
                                  KEY `server_id` (`server_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COMMENT='节点数据统计';


DROP TABLE IF EXISTS `v2_stat_user`;
CREATE TABLE `v2_stat_user` (
                                `id` int(11) NOT NULL AUTO_INCREMENT,
                                `user_id` int(11) NOT NULL,
                                `server_rate` decimal(10,2) NOT NULL,
                                `u` bigint(20) NOT NULL,
                                `d` bigint(20) NOT NULL,
                                `record_type` char(2) NOT NULL,
                                `record_at` int(11) NOT NULL,
                                `created_at` int(11) NOT NULL,
                                `updated_at` int(11) NOT NULL,
                                PRIMARY KEY (`id`),
                                UNIQUE KEY `server_rate_user_id_record_at` (`server_rate`,`user_id`,`record_at`),
                                KEY `user_id` (`user_id`),
                                KEY `record_at` (`record_at`),
                                KEY `server_rate` (`server_rate`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


DROP TABLE IF EXISTS `v2_ticket`;
CREATE TABLE `v2_ticket` (
                             `id` int(11) NOT NULL AUTO_INCREMENT,
                             `user_id` int(11) NOT NULL,
                             `subject` varchar(255) NOT NULL,
                             `level` tinyint(1) NOT NULL,
                             `status` tinyint(1) NOT NULL DEFAULT '0' COMMENT '0:已开启 1:已关闭',
                             `reply_status` tinyint(1) NOT NULL DEFAULT '0' COMMENT '0:待回复 1:已回复',
                             `created_at` int(11) NOT NULL,
                             `updated_at` int(11) NOT NULL,
                             PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;


DROP TABLE IF EXISTS `v2_ticket_message`;
CREATE TABLE `v2_ticket_message` (
                                     `id` int(11) NOT NULL AUTO_INCREMENT,
                                     `user_id` int(11) NOT NULL,
                                     `ticket_id` int(11) NOT NULL,
                                     `message` text CHARACTER SET utf8mb4 NOT NULL,
                                     `created_at` int(11) NOT NULL,
                                     `updated_at` int(11) NOT NULL,
                                     PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;


DROP TABLE IF EXISTS `v2_user`;
CREATE TABLE `v2_user` (
                           `id` int(11) NOT NULL AUTO_INCREMENT,
                           `invite_user_id` int(11) DEFAULT NULL,
                           `telegram_id` bigint(20) DEFAULT NULL,
                           `email` varchar(64) NOT NULL,
                           `password` varchar(64) NOT NULL,
                           `password_algo` char(10) DEFAULT NULL,
                           `password_salt` char(10) DEFAULT NULL,
                           `balance` int(11) NOT NULL DEFAULT '0',
                           `discount` int(11) DEFAULT NULL,
                           `commission_type` tinyint(4) NOT NULL DEFAULT '0' COMMENT '0: system 1: period 2: onetime',
                           `commission_rate` int(11) DEFAULT NULL,
                           `commission_balance` int(11) NOT NULL DEFAULT '0',
                           `t` int(11) NOT NULL DEFAULT '0',
                           `u` bigint(20) NOT NULL DEFAULT '0',
                           `d` bigint(20) NOT NULL DEFAULT '0',
                           `transfer_enable` bigint(20) NOT NULL DEFAULT '0',
                           `device_limit` int(11) DEFAULT NULL,
                           `banned` tinyint(1) NOT NULL DEFAULT '0',
                           `is_admin` tinyint(1) NOT NULL DEFAULT '0',
                           `last_login_at` int(11) DEFAULT NULL,
                           `is_staff` tinyint(1) NOT NULL DEFAULT '0',
                           `last_login_ip` int(11) DEFAULT NULL,
                           `uuid` varchar(36) NOT NULL,
                           `group_id` int(11) DEFAULT NULL,
                           `plan_id` int(11) DEFAULT NULL,
                           `speed_limit` int(11) DEFAULT NULL,
                           `auto_renewal` tinyint(4) DEFAULT '0',
                           `remind_expire` tinyint(4) DEFAULT '1',
                           `remind_traffic` tinyint(4) DEFAULT '1',
                           `token` char(32) NOT NULL,
                           `expired_at` bigint(20) DEFAULT '0',
                           `remarks` text,
                           `created_at` int(11) NOT NULL,
                           `updated_at` int(11) NOT NULL,
                           PRIMARY KEY (`id`),
                           UNIQUE KEY `email` (`email`),
                           UNIQUE KEY `token` (`token`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;


CREATE TABLE IF NOT EXISTS `v2_new_period_log` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `type` tinyint(1) NOT NULL DEFAULT '1' COMMENT '1提前续期 2订阅覆盖',
  `order_id` int(11) DEFAULT NULL COMMENT '触发覆盖的订单',
  `plan_id` int(11) DEFAULT NULL COMMENT '覆盖前订阅',
  `new_plan_id` int(11) DEFAULT NULL COMMENT '覆盖后订阅',
  `deduct_days` int(11) NOT NULL DEFAULT '0' COMMENT '扣除天数',
  `old_expired_at` bigint(20) NOT NULL DEFAULT '0' COMMENT '原到期时间',
  `new_expired_at` bigint(20) NOT NULL DEFAULT '0' COMMENT '新到期时间',
  `u` bigint(20) NOT NULL DEFAULT '0' COMMENT '重置前已用上行',
  `d` bigint(20) NOT NULL DEFAULT '0' COMMENT '重置前已用下行',
  `transfer_enable` bigint(20) NOT NULL DEFAULT '0' COMMENT '当时流量配额',
  `created_at` int(11) NOT NULL,
  `updated_at` int(11) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `order_id_type` (`order_id`, `type`),
  KEY `user_id` (`user_id`),
  KEY `created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- 2025-09-12 10:05:00

-- ============================================================================
-- 子账号功能（Sub-Account）— 独立增量区块
-- ----------------------------------------------------------------------------
-- 幂等：使用 CREATE TABLE IF NOT EXISTS，可重复执行。
-- 卸载说明（默认不执行，且不会删除任何数据）：
--   DROP TABLE IF EXISTS `v2_user_sub_accounts`;
--   DROP TABLE IF EXISTS `v2_sub_account_audit_logs`;
-- 等价的、带类型自检的安装方式：php artisan sub-account:install --apply
-- ============================================================================
CREATE TABLE IF NOT EXISTS `v2_user_sub_accounts` (
  `id`                BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `parent_user_id`    INT(11)             NOT NULL COMMENT '主账号 v2_user.id',
  `child_user_id`     INT(11)             NOT NULL COMMENT '子账号 v2_user.id',
  `traffic_limit`     BIGINT(20)          NOT NULL DEFAULT 0 COMMENT '子账号个人额度(字节)，0=不设独立上限',
  `remark`            VARCHAR(255)        DEFAULT NULL,
  `status`            TINYINT(4)          NOT NULL DEFAULT 1 COMMENT '1=启用 0=停用/归档',
  `created_by_parent` TINYINT(4)          NOT NULL DEFAULT 0 COMMENT '1=父账号新建 0=绑定已有账号',
  `created_at`        INT(11)             DEFAULT NULL,
  `updated_at`        INT(11)             DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `v2_user_sub_accounts_child_user_id_unique` (`child_user_id`),
  KEY `v2_user_sub_accounts_parent_user_id_status_index` (`parent_user_id`, `status`),
  CONSTRAINT `v2_user_sub_accounts_parent_user_id_foreign`
    FOREIGN KEY (`parent_user_id`) REFERENCES `v2_user` (`id`) ON DELETE CASCADE,
  CONSTRAINT `v2_user_sub_accounts_child_user_id_foreign`
    FOREIGN KEY (`child_user_id`) REFERENCES `v2_user` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='V2Board 子账号关系表';

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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='V2Board 子账号审计日志表（无外键，删除用户不丢历史）';

-- ============================================================================
-- 每日签到 + 活动弹窗 —— 独立增量区块（幂等，可重复执行）
-- 明细与卸载说明见 database/sql/checkin_promotion_mysql57.sql
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
