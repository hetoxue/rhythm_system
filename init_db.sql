-- 数据库初始化脚本
-- 在 MySQL 中执行本脚本以创建必要的数据表

CREATE DATABASE IF NOT EXISTS `rhythm_system` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `rhythm_system`;

-- 用户表
CREATE TABLE IF NOT EXISTS `users` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `qq` VARCHAR(20) NOT NULL,
  `mobile` VARCHAR(20) NOT NULL,
  `password` VARCHAR(255) NOT NULL,
  `salt` VARCHAR(32) DEFAULT NULL,
  `balance` BIGINT NOT NULL DEFAULT 0,
  `status` TINYINT NOT NULL DEFAULT 1,
  `is_locked` TINYINT NOT NULL DEFAULT 0,
  `locked_until` DATETIME DEFAULT NULL,
  `failed_login_count` INT NOT NULL DEFAULT 0,
  `last_login_time` DATETIME DEFAULT NULL,
  `register_ip` VARCHAR(45) DEFAULT NULL,
  `register_device` TEXT DEFAULT NULL,
  `created_at` DATETIME NOT NULL,
  `updated_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_users_mobile` (`mobile`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- IP 锁定
CREATE TABLE IF NOT EXISTS `ip_locks` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `ip` VARCHAR(45) NOT NULL,
  `is_locked` TINYINT NOT NULL DEFAULT 0,
  `locked_until` DATETIME DEFAULT NULL,
  `reason` VARCHAR(255) DEFAULT NULL,
  `created_at` DATETIME NOT NULL,
  `updated_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_ip_locks_ip` (`ip`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 管理员
CREATE TABLE IF NOT EXISTS `admins` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `username` VARCHAR(50) NOT NULL,
  `password` VARCHAR(255) NOT NULL,
  `salt` VARCHAR(32) DEFAULT NULL,
  `qq` VARCHAR(20) DEFAULT NULL,
  `avatar` VARCHAR(255) DEFAULT NULL,
  `role` TINYINT NOT NULL DEFAULT 1,
  `status` TINYINT NOT NULL DEFAULT 1,
  `last_login_ip` VARCHAR(45) DEFAULT NULL,
  `last_login_time` DATETIME DEFAULT NULL,
  `created_at` DATETIME NOT NULL,
  `updated_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_admins_username` (`username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 入场记录
CREATE TABLE IF NOT EXISTS `entrance_records` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `enter_time` DATETIME NOT NULL,
  `exit_time` DATETIME DEFAULT NULL,
  `duration_min` INT DEFAULT NULL,
  `charge_amount` BIGINT DEFAULT NULL,
  `discount_amount` BIGINT DEFAULT NULL,
  `actual_amount` BIGINT DEFAULT NULL,
  `balance_after` BIGINT DEFAULT NULL,
  `status` TINYINT NOT NULL DEFAULT 0,
  `use_time_card` TINYINT NOT NULL DEFAULT 0,
  `time_card_id` BIGINT UNSIGNED DEFAULT NULL,
  `created_at` DATETIME NOT NULL,
  `updated_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_entrance_user` (`user_id`),
  CONSTRAINT `fk_entrance_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 充值订单
CREATE TABLE IF NOT EXISTS `recharge_orders` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `order_no` VARCHAR(64) NOT NULL,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `amount` BIGINT NOT NULL,
  `pay_method` TINYINT NOT NULL,
  `pay_channel_no` VARCHAR(128) DEFAULT NULL,
  `status` TINYINT NOT NULL DEFAULT 0,
  `paid_at` DATETIME DEFAULT NULL,
  `created_at` DATETIME NOT NULL,
  `updated_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_recharge_order_no` (`order_no`),
  KEY `idx_recharge_user` (`user_id`),
  CONSTRAINT `fk_recharge_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 消费记录
CREATE TABLE IF NOT EXISTS `consume_records` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `type` TINYINT NOT NULL,
  `related_id` BIGINT UNSIGNED DEFAULT NULL,
  `amount` BIGINT NOT NULL,
  `balance_after` BIGINT NOT NULL,
  `remark` VARCHAR(255) DEFAULT NULL,
  `created_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_consume_user` (`user_id`),
  CONSTRAINT `fk_consume_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 时长卡套餐
CREATE TABLE IF NOT EXISTS `time_card_plans` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(50) NOT NULL,
  `duration_min` INT NOT NULL,
  `price` BIGINT NOT NULL,
  `valid_days` INT NOT NULL,
  `max_per_user` INT NOT NULL DEFAULT 0,
  `status` TINYINT NOT NULL DEFAULT 1,
  `created_at` DATETIME NOT NULL,
  `updated_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 用户时长卡
CREATE TABLE IF NOT EXISTS `user_time_cards` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `plan_id` BIGINT UNSIGNED NOT NULL,
  `total_min` INT NOT NULL,
  `used_min` INT NOT NULL DEFAULT 0,
  `remaining_min` INT NOT NULL,
  `price` BIGINT NOT NULL,
  `status` TINYINT NOT NULL DEFAULT 1,
  `start_time` DATETIME NOT NULL,
  `expire_time` DATETIME NOT NULL,
  `created_at` DATETIME NOT NULL,
  `updated_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_user_time_cards_user` (`user_id`),
  CONSTRAINT `fk_user_time_cards_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 时长卡使用记录
CREATE TABLE IF NOT EXISTS `time_card_usage_logs` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_time_card_id` BIGINT UNSIGNED NOT NULL,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `entrance_id` BIGINT UNSIGNED NOT NULL,
  `used_min` INT NOT NULL,
  `created_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_time_card_usage_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 包场申请
CREATE TABLE IF NOT EXISTS `booking_orders` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `date` DATE NOT NULL,
  `start_time` TIME NOT NULL,
  `end_time` TIME NOT NULL,
  `purpose` VARCHAR(255) DEFAULT NULL,
  `status` TINYINT NOT NULL DEFAULT 0,
  `remark` VARCHAR(255) DEFAULT NULL,
  `created_at` DATETIME NOT NULL,
  `updated_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_booking_date` (`date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 包场场次配置
CREATE TABLE IF NOT EXISTS `booking_slots` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(50) NOT NULL,
  `start_time` TIME NOT NULL,
  `end_time` TIME NOT NULL,
  `price` BIGINT NOT NULL,
  `max_people` INT NOT NULL DEFAULT 0,
  `status` TINYINT NOT NULL DEFAULT 1,
  `created_at` DATETIME NOT NULL,
  `updated_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 初始插入日场和夜场配置
INSERT INTO `booking_slots` (`name`, `start_time`, `end_time`, `price`, `max_people`, `status`, `created_at`, `updated_at`)
VALUES 
('日场', '10:00:00', '22:00:00', 50000, 20, 1, NOW(), NOW()),
('夜场', '22:00:00', '10:00:00', 80000, 15, 1, NOW(), NOW());

-- 包场邀请名单
CREATE TABLE IF NOT EXISTS `booking_invited_users` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `booking_id` BIGINT UNSIGNED NOT NULL,
  `mobile` VARCHAR(20) DEFAULT NULL,
  `qq` VARCHAR(20) DEFAULT NULL,
  `user_id` BIGINT UNSIGNED DEFAULT NULL,
  `created_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_booking_invited_booking` (`booking_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 包场实际入场人员
CREATE TABLE IF NOT EXISTS `booking_actual_attendees` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `booking_id` BIGINT UNSIGNED NOT NULL,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `entrance_id` BIGINT UNSIGNED NOT NULL,
  `created_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_booking_actual_booking` (`booking_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 充值卡券
CREATE TABLE IF NOT EXISTS `recharge_cards` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `card_no` VARCHAR(64) NOT NULL,
  `amount` BIGINT NOT NULL,
  `status` TINYINT NOT NULL DEFAULT 0,
  `used_by` BIGINT UNSIGNED DEFAULT NULL,
  `used_at` DATETIME DEFAULT NULL,
  `expire_time` DATETIME DEFAULT NULL,
  `created_at` DATETIME NOT NULL,
  `updated_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_recharge_cards_card_no` (`card_no`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 公告
CREATE TABLE IF NOT EXISTS `announcements` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `title` VARCHAR(100) NOT NULL,
  `content` TEXT NOT NULL,
  `status` TINYINT NOT NULL DEFAULT 1,
  `sort_order` INT NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL,
  `updated_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 轮播图
CREATE TABLE IF NOT EXISTS `banners` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `title` VARCHAR(100) DEFAULT NULL,
  `image_url` VARCHAR(255) NOT NULL,
  `link_url` VARCHAR(255) DEFAULT NULL,
  `sort_order` INT NOT NULL DEFAULT 0,
  `status` TINYINT NOT NULL DEFAULT 1,
  `created_at` DATETIME NOT NULL,
  `updated_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 系统配置
CREATE TABLE IF NOT EXISTS `system_configs` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `config_key` VARCHAR(100) NOT NULL,
  `config_value` TEXT NOT NULL,
  `remark` VARCHAR(255) DEFAULT NULL,
  `updated_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_system_configs_key` (`config_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 操作日志
CREATE TABLE IF NOT EXISTS `operation_logs` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `actor_type` TINYINT NOT NULL,
  `actor_id` BIGINT UNSIGNED NOT NULL,
  `action` VARCHAR(100) NOT NULL,
  `ip` VARCHAR(45) DEFAULT NULL,
  `detail` TEXT DEFAULT NULL,
  `created_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_operation_actor` (`actor_type`, `actor_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 登录日志
CREATE TABLE IF NOT EXISTS `login_logs` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_type` TINYINT NOT NULL,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `ip` VARCHAR(45) NOT NULL,
  `status` TINYINT NOT NULL,
  `reason` VARCHAR(255) DEFAULT NULL,
  `created_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_login_user` (`user_type`, `user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 邮箱验证码
CREATE TABLE IF NOT EXISTS `verification_codes` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `email` VARCHAR(100) NOT NULL,
  `code` VARCHAR(10) NOT NULL,
  `type` VARCHAR(20) NOT NULL COMMENT 'register/reset',
  `expires_at` DATETIME NOT NULL,
  `used` TINYINT NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_email_type` (`email`, `type`),
  KEY `idx_expires` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 邮件发送日志
CREATE TABLE IF NOT EXISTS `email_logs` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `to_email` VARCHAR(100) NOT NULL,
  `subject` VARCHAR(255) NOT NULL,
  `status` TINYINT NOT NULL DEFAULT 0 COMMENT '0=失败,1=成功',
  `error_message` TEXT DEFAULT NULL,
  `created_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_status` (`status`),
  KEY `idx_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 初始化一个超级管理员（用户名：admin 密码：admin123，部署后请及时修改）
INSERT IGNORE INTO `admins` (`username`, `password`, `role`, `status`, `created_at`, `updated_at`)
VALUES (
  'admin',
  '$2y$10$SOME_DEFAULT_HASH_PLEASE_CHANGE',
  9,
  1,
  NOW(),
  NOW()
);

-- 初始化系统配置（favicon）
INSERT IGNORE INTO `system_configs` (`config_key`, `config_value`, `remark`, `updated_at`)
VALUES
('login_favicon', '', '登录页面favicon图标URL', NOW()),
('user_favicon', '', '用户端favicon图标URL', NOW()),
('admin_favicon', '', '管理后台favicon图标URL', NOW());

