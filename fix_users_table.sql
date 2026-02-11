-- 修复users表结构
-- 添加缺失的 last_login_ip 字段

USE `rhythm_system`;

-- 检查并添加 last_login_ip 字段（如果不存在）
ALTER TABLE `users` ADD COLUMN `last_login_ip` VARCHAR(45) DEFAULT NULL AFTER `last_login_time`;
