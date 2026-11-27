SET NAMES utf8mb4;

SET @has_service_price := (SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema=DATABASE() AND table_name='counselor_services' AND column_name='price_cents');
SET @ddl := IF(@has_service_price=0,
  'ALTER TABLE `counselor_services` ADD COLUMN `price_cents` int unsigned NULL DEFAULT NULL COMMENT ''咨询师项目默认价；空=使用全站服务价'' AFTER `service_id`',
  'SELECT 1');
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_slot_service := (SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema=DATABASE() AND table_name='counselor_slots' AND column_name='service_id');
SET @ddl := IF(@has_slot_service=0,
  'ALTER TABLE `counselor_slots` ADD COLUMN `service_id` int unsigned NOT NULL DEFAULT 0 COMMENT ''0=全部已关联项目'' AFTER `store_id`',
  'SELECT 1');
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
