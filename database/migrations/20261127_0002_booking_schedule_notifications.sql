SET NAMES utf8mb4;

SET @has_mode := (SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema=DATABASE() AND table_name='counselor_slots' AND column_name='service_mode');
SET @ddl := IF(@has_mode=0,
  'ALTER TABLE `counselor_slots` ADD COLUMN `service_mode` varchar(16) NOT NULL DEFAULT ''all'' COMMENT ''all/offline/online'' AFTER `slot_minutes`',
  'SELECT 1');
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_store := (SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema=DATABASE() AND table_name='counselor_slots' AND column_name='store_id');
SET @ddl := IF(@has_store=0,
  'ALTER TABLE `counselor_slots` ADD COLUMN `store_id` int unsigned NOT NULL DEFAULT 0 COMMENT ''0=沿用咨询师归属门店'' AFTER `service_mode`',
  'SELECT 1');
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

CREATE TABLE IF NOT EXISTS `counselor_leaves` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `counselor_id` int unsigned NOT NULL,
  `start_date` date NOT NULL,
  `end_date` date NOT NULL,
  `note` varchar(190) NULL,
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_counselor_dates` (`counselor_id`,`start_date`,`end_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `booking_paid_notifications` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `booking_id` int unsigned NOT NULL,
  `channel` varchar(32) NOT NULL,
  `status` varchar(16) NOT NULL DEFAULT 'pending',
  `attempts` smallint unsigned NOT NULL DEFAULT 0,
  `last_error` varchar(500) NULL,
  `next_attempt_at` datetime NULL,
  `payload` longtext NULL,
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL,
  `sent_at` datetime NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_booking_channel` (`booking_id`,`channel`),
  KEY `idx_status_updated` (`status`,`updated_at`),
  KEY `idx_status_next` (`status`,`next_attempt_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET @has_next := (SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema=DATABASE() AND table_name='booking_paid_notifications' AND column_name='next_attempt_at');
SET @ddl := IF(@has_next=0,
  'ALTER TABLE `booking_paid_notifications` ADD COLUMN `next_attempt_at` datetime NULL AFTER `last_error`',
  'SELECT 1');
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_next_idx := (SELECT COUNT(*) FROM information_schema.statistics
  WHERE table_schema=DATABASE() AND table_name='booking_paid_notifications' AND index_name='idx_status_next');
SET @ddl := IF(@has_next_idx=0,
  'ALTER TABLE `booking_paid_notifications` ADD KEY `idx_status_next` (`status`,`next_attempt_at`)',
  'SELECT 1');
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
