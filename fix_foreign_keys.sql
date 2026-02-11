-- 修复外键约束脚本
-- 执行此脚本以修复错误的外键约束

USE `rhythm_system`;

-- 删除错误的外键约束
ALTER TABLE `consume_records` DROP FOREIGN KEY IF EXISTS `fk_consume_user`;
ALTER TABLE `entrance_records` DROP FOREIGN KEY IF EXISTS `fk_entrance_user`;
ALTER TABLE `recharge_orders` DROP FOREIGN KEY IF EXISTS `fk_recharge_user`;
ALTER TABLE `user_time_cards` DROP FOREIGN KEY IF EXISTS `fk_user_time_cards_user`;
ALTER TABLE `booking_orders` DROP FOREIGN KEY IF EXISTS `fk_booking_user`;
ALTER TABLE `product_orders` DROP FOREIGN KEY IF EXISTS `fk_product_order_user`;

-- 重新创建正确的外键约束
ALTER TABLE `consume_records`
ADD CONSTRAINT `fk_consume_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

ALTER TABLE `entrance_records`
ADD CONSTRAINT `fk_entrance_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

ALTER TABLE `recharge_orders`
ADD CONSTRAINT `fk_recharge_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

ALTER TABLE `user_time_cards`
ADD CONSTRAINT `fk_user_time_cards_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

ALTER TABLE `booking_orders`
ADD CONSTRAINT `fk_booking_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

ALTER TABLE `product_orders`
ADD CONSTRAINT `fk_product_order_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;
