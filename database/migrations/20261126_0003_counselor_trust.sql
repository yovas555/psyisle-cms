SET NAMES utf8mb4;

SET @has_col := (SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema=DATABASE() AND table_name='counselors' AND column_name='audiences');
SET @ddl := IF(@has_col=0,
  'ALTER TABLE `counselors` ADD COLUMN `audiences` varchar(500) NULL COMMENT ''服务对象（机构维护）'' AFTER `service_modes`',
  'SELECT 1');
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_col := (SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema=DATABASE() AND table_name='counselors' AND column_name='unsuitable_topics');
SET @ddl := IF(@has_col=0,
  'ALTER TABLE `counselors` ADD COLUMN `unsuitable_topics` varchar(1000) NULL COMMENT ''不适合议题与情形（机构维护）'' AFTER `audiences`',
  'SELECT 1');
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_col := (SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema=DATABASE() AND table_name='counselors' AND column_name='personal_therapy');
SET @ddl := IF(@has_col=0,
  'ALTER TABLE `counselors` ADD COLUMN `personal_therapy` text NULL COMMENT ''个人体验与自我觉察（咨询师自述）'' AFTER `unsuitable_topics`',
  'SELECT 1');
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

CREATE TABLE IF NOT EXISTS `counselor_verifications` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `counselor_id` int unsigned NOT NULL,
  `item_key` varchar(32) NOT NULL,
  `status` varchar(16) NOT NULL,
  `subject_hash` char(64) NOT NULL,
  `evidence_reference` text NULL COMMENT '内部核验依据，禁止公开',
  `verified_summary` varchar(1000) NULL COMMENT '可公开核验摘要',
  `reviewed_at` datetime NOT NULL,
  `expires_at` date NULL,
  `actor_id` int unsigned NOT NULL,
  `verifier_name` varchar(80) NOT NULL,
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_counselor_latest` (`counselor_id`,`item_key`,`id`),
  KEY `idx_actor` (`actor_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
