SET NAMES utf8mb4;

SET @has_col := (SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema=DATABASE() AND table_name='counselors' AND column_name='profile_draft');
SET @ddl := IF(@has_col=0,
  'ALTER TABLE `counselors` ADD COLUMN `profile_draft` longtext NULL COMMENT ''资质资料草稿 JSON，审核通过前不公开'' AFTER `personal_therapy`',
  'SELECT 1');
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

CREATE TABLE IF NOT EXISTS `counselor_profile_submissions` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `counselor_id` int unsigned NOT NULL,
  `payload` longtext NOT NULL COMMENT '提交时的资质资料快照 JSON',
  `base_hash` char(64) NOT NULL COMMENT '提交时公开版本哈希，用于冲突检测',
  `status` varchar(16) NOT NULL DEFAULT 'pending' COMMENT 'pending/approved/rejected',
  `submitted_by` int unsigned NOT NULL,
  `submitted_at` datetime NOT NULL,
  `reviewed_by` int unsigned NULL,
  `reviewed_at` datetime NULL,
  `review_note` varchar(500) NULL,
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_counselor_status` (`counselor_id`,`status`,`id`),
  KEY `idx_status` (`status`,`id`),
  KEY `idx_submitter` (`submitted_by`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `counselor_profile_attachments` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `counselor_id` int unsigned NOT NULL,
  `submission_id` int unsigned NULL,
  `original_name` varchar(190) NOT NULL,
  `stored_path` varchar(255) NOT NULL COMMENT '相对 storage 的私有路径',
  `mime` varchar(100) NOT NULL,
  `size` int unsigned NOT NULL,
  `sha256` char(64) NOT NULL,
  `created_by` int unsigned NOT NULL,
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_counselor` (`counselor_id`,`id`),
  KEY `idx_submission` (`submission_id`,`id`),
  KEY `idx_creator` (`created_by`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
