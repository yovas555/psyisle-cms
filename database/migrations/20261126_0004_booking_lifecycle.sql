SET NAMES utf8mb4;

SET @has_col := (SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema=DATABASE() AND table_name='bookings' AND column_name='rule_snapshot');
SET @ddl := IF(@has_col=0,
  'ALTER TABLE `bookings` ADD COLUMN `rule_snapshot` JSON NULL COMMENT ''下单时规则快照；历史记录不伪造回填'' AFTER `source`',
  'SELECT 1');
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
CREATE TABLE IF NOT EXISTS `booking_status_events` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `booking_id` int unsigned NOT NULL,
  `from_status` varchar(20) NOT NULL,
  `to_status` varchar(20) NOT NULL,
  `event_type` varchar(32) NOT NULL DEFAULT 'status_change',
  `actor_id` int unsigned NULL,
  `actor_name` varchar(80) NOT NULL,
  `note` varchar(500) NULL,
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_booking_events` (`booking_id`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
