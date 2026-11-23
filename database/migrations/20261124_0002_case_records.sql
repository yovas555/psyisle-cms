-- =============================================================
-- 20261124_0002 · 结构化咨询记录（P1-B）
-- 新增：case_records（记录主表）、case_record_versions（版本快照）
--
-- 口径：
--  * 记录写进个案，不写进会员；一条记录可挂某次会次（session_id）。
--  * 草稿可改；锁定（locked）后不可改，改必须另开「修订记录」（amends_id）。
--  * 每次保存都落一个版本快照，versions 表只增不改，用于追溯"当时写了什么"。
--  * legacy_note_id 唯一：历史 counselor_notes 迁移过来时幂等，重复跑不会翻倍。
-- 幂等：CREATE TABLE IF NOT EXISTS。
-- =============================================================
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `case_records` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `case_id` int unsigned NOT NULL,
  `session_id` int unsigned NULL COMMENT '关联会次（选填）',
  `counselor_id` int unsigned NOT NULL,
  `member_id` int unsigned NOT NULL,
  `record_type` varchar(12) NOT NULL DEFAULT 'summary' COMMENT 'summary 小结 / soap 结构化 / assessment 评估 / plan 计划',
  `title` varchar(120) NOT NULL,
  `content` mediumtext NOT NULL COMMENT '富文本正文（已净化）',
  `version` int unsigned NOT NULL DEFAULT 1 COMMENT '当前版本号（每次保存 +1）',
  `status` varchar(12) NOT NULL DEFAULT 'draft' COMMENT 'draft 草稿可改 / locked 已锁定不可改',
  `visible` tinyint NOT NULL DEFAULT 0 COMMENT '对咨客可见（默认不可见，需显式勾选）',
  `locked_at` datetime NULL,
  `locked_by` int unsigned NULL,
  `amends_id` int unsigned NULL COMMENT '修订自哪条已锁定记录',
  `legacy_note_id` int unsigned NULL COMMENT '由 counselor_notes 迁移而来（幂等键）',
  `created_by` int unsigned NULL,
  `created_at` datetime NULL,
  `updated_at` datetime NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_legacy` (`legacy_note_id`),
  KEY `k_case` (`case_id`,`created_at`),
  KEY `k_session` (`session_id`),
  KEY `k_counselor` (`counselor_id`,`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='结构化咨询记录（P1-B）';

CREATE TABLE IF NOT EXISTS `case_record_versions` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `record_id` int unsigned NOT NULL,
  `version` int unsigned NOT NULL,
  `title` varchar(120) NOT NULL,
  `content` mediumtext NOT NULL,
  `status` varchar(12) NOT NULL DEFAULT 'draft',
  `editor_id` int unsigned NULL,
  `created_at` datetime NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_record_version` (`record_id`,`version`),
  KEY `k_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='咨询记录版本快照（只增不改）';
