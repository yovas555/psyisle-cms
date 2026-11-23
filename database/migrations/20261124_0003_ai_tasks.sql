-- =============================================================
-- 20261124_0003 · AI 草稿任务（P1-C）
--
-- 口径（D3）：
--  * 不走本地 P100（那台在局域网 192.168.0.100，与本站不互通），走 OpenAI 兼容外部 API。
--  * 默认关闭，必须显式开启 ai.draft_enabled；ai.outbound_scope=none 时一律不外发。
--  * 出站前按 ai.redact 脱敏（手机/身份证/邮箱/卡号），库里只存脱敏后的输入。
--  * **AI 只产出草稿**：结果落在 ai_tasks，咨询师点「采用」才生成 case_records 草稿，
--    仍需人工核对、锁定。任何情况下 AI 都不直接写入咨询记录。
-- =============================================================
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `ai_tasks` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `case_id` int unsigned NOT NULL,
  `session_id` int unsigned NULL,
  `counselor_id` int unsigned NOT NULL,
  `kind` varchar(16) NOT NULL DEFAULT 'draft' COMMENT 'draft 记录草稿 / summary 阶段小结',
  `input_chars` int unsigned NOT NULL DEFAULT 0,
  `redacted_count` smallint unsigned NOT NULL DEFAULT 0 COMMENT '出站前脱敏命中数',
  `outbound` tinyint NOT NULL DEFAULT 0 COMMENT '1=确实发生了外发',
  `input_text` mediumtext NULL COMMENT '已脱敏的输入（不外发原文）',
  `output_text` mediumtext NULL COMMENT '模型返回的草稿',
  `model` varchar(80) NULL,
  `status` varchar(12) NOT NULL DEFAULT 'pending' COMMENT 'pending/done/failed/adopted',
  `error` varchar(500) NULL,
  `adopted_record_id` int unsigned NULL COMMENT '采用后生成的咨询记录',
  `created_by` int unsigned NULL,
  `created_at` datetime NULL,
  `updated_at` datetime NULL,
  PRIMARY KEY (`id`),
  KEY `k_case` (`case_id`,`created_at`),
  KEY `k_counselor` (`counselor_id`,`status`),
  KEY `k_adopted` (`adopted_record_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='AI 草稿任务（P1-C）';

-- 咨询记录增加 AI 来源标注（幂等：先查 information_schema 再决定是否 ALTER）
SET @has_ai_col := (SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema = DATABASE() AND table_name = 'case_records' AND column_name = 'ai_task_id');
SET @ddl := IF(@has_ai_col = 0,
  'ALTER TABLE `case_records` ADD COLUMN `ai_task_id` int unsigned NULL COMMENT ''AI 任务来源（AI 只是草稿，人工确认后才有此值）''',
  'SELECT 1');
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
