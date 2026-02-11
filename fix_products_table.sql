-- 修复商品表结构
-- 添加缺失的 sort_order 字段

USE `rhythm_system`;

-- 添加 sort_order 字段到 products 表
ALTER TABLE `products` ADD COLUMN `sort_order` INT NOT NULL DEFAULT 0 AFTER `stock`;
