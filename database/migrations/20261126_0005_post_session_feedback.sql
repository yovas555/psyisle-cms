SET NAMES utf8mb4;

DROP TEMPORARY TABLE IF EXISTS `psyisle_post_session_booking_guard`;
CREATE TEMPORARY TABLE `psyisle_post_session_booking_guard` (
  `booking_id` int unsigned NOT NULL,
  UNIQUE KEY `psyisle_duplicate_case_sessions_booking_id_stop` (`booking_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
INSERT INTO `psyisle_post_session_booking_guard` (`booking_id`)
  SELECT s.`booking_id` FROM `case_sessions` s
  JOIN (
    SELECT `booking_id` FROM `case_sessions` WHERE `booking_id` IS NOT NULL
    GROUP BY `booking_id` HAVING COUNT(*) > 1
  ) d ON d.`booking_id`=s.`booking_id`;
DROP TEMPORARY TABLE `psyisle_post_session_booking_guard`;

CREATE TABLE IF NOT EXISTS `booking_feedbacks` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `booking_id` int unsigned NOT NULL,
  `member_id` int unsigned NULL,
  `counselor_id` int unsigned NOT NULL,
  `case_id` int unsigned NULL,
  `status` varchar(16) NOT NULL DEFAULT 'pending' COMMENT 'pending/submitted/closed/unavailable',
  `rating` tinyint unsigned NULL,
  `comment` text NULL,
  `wants_followup` tinyint(1) NOT NULL DEFAULT 0,
  `bridge_status` varchar(20) NOT NULL DEFAULT 'pending' COMMENT 'pending/waiting_case/created/not_applicable/unavailable',
  `skip_reason` varchar(190) NULL,
  `submitted_at` datetime NULL,
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_booking_feedback_booking` (`booking_id`),
  KEY `k_feedback_member` (`member_id`,`status`),
  KEY `k_feedback_counselor` (`counselor_id`,`status`),
  KEY `k_feedback_bridge` (`bridge_status`,`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='咨询完成后的会员反馈';

SET @has_col := (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE table_schema=DATABASE() AND table_name='case_followups' AND column_name='booking_id');
SET @ddl := IF(@has_col=0,
  'ALTER TABLE `case_followups` ADD COLUMN `booking_id` int unsigned NULL COMMENT ''来源预约'' AFTER `case_id`',
  'SELECT 1');
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_col := (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE table_schema=DATABASE() AND table_name='case_followups' AND column_name='session_id');
SET @ddl := IF(@has_col=0,
  'ALTER TABLE `case_followups` ADD COLUMN `session_id` int unsigned NULL COMMENT ''来源会次'' AFTER `booking_id`',
  'SELECT 1');
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_col := (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE table_schema=DATABASE() AND table_name='case_followups' AND column_name='source');
SET @ddl := IF(@has_col=0,
  'ALTER TABLE `case_followups` ADD COLUMN `source` varchar(32) NOT NULL DEFAULT ''manual'' COMMENT ''manual/booking_feedback'' AFTER `session_id`',
  'SELECT 1');
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_col := (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE table_schema=DATABASE() AND table_name='case_followups' AND column_name='source_key');
SET @ddl := IF(@has_col=0,
  'ALTER TABLE `case_followups` ADD COLUMN `source_key` varchar(190) NULL COMMENT ''来源幂等键'' AFTER `source`',
  'SELECT 1');
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

DROP TEMPORARY TABLE IF EXISTS `psyisle_post_session_source_key_guard`;
CREATE TEMPORARY TABLE `psyisle_post_session_source_key_guard` (
  `source_key` varchar(190) NOT NULL,
  UNIQUE KEY `psyisle_duplicate_case_followups_source_key_stop` (`source_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
INSERT INTO `psyisle_post_session_source_key_guard` (`source_key`)
  SELECT f.`source_key` FROM `case_followups` f
  JOIN (
    SELECT `source_key` FROM `case_followups` WHERE `source_key` IS NOT NULL
    GROUP BY `source_key` HAVING COUNT(*) > 1
  ) d ON d.`source_key`=f.`source_key`;
DROP TEMPORARY TABLE `psyisle_post_session_source_key_guard`;

SET @has_idx := (SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE table_schema=DATABASE() AND table_name='case_followups' AND index_name='uk_followup_source_key');
SET @ddl := IF(@has_idx=0,
  'ALTER TABLE `case_followups` ADD UNIQUE KEY `uk_followup_source_key` (`source_key`)',
  'SELECT 1');
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_idx := (SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE table_schema=DATABASE() AND table_name='case_followups' AND index_name='k_followup_booking');
SET @ddl := IF(@has_idx=0,
  'ALTER TABLE `case_followups` ADD KEY `k_followup_booking` (`booking_id`)',
  'SELECT 1');
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_idx := (SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE table_schema=DATABASE() AND table_name='case_followups' AND index_name='k_followup_session');
SET @ddl := IF(@has_idx=0,
  'ALTER TABLE `case_followups` ADD KEY `k_followup_session` (`session_id`)',
  'SELECT 1');
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_idx := (SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE table_schema=DATABASE() AND table_name='case_sessions' AND index_name='uk_case_session_booking');
SET @ddl := IF(@has_idx=0,
  'ALTER TABLE `case_sessions` ADD UNIQUE KEY `uk_case_session_booking` (`booking_id`)',
  'SELECT 1');
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
