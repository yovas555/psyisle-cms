-- =============================================================
-- 20261124_0004 · 危机 / 作业 / 随访（P1-D）
--
-- 口径：
--  * 危机事件「不闭环就一直开着」：status=open 的事件在工作台首页常驻提醒，
--    关闭必须写处置结果（closed_by + closure_note），不接受静默消失。
--  * 作业挂在个案（可挂会次），状态 assigned/done/cancelled，逾期由日期推断，不额外落库。
--  * 随访是"到期该联系谁"，status=pending 且 due_at<=今天 即到期，首页提示。
-- 全部幂等建表。
-- =============================================================
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `crisis_events` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `case_id` int unsigned NOT NULL,
  `counselor_id` int unsigned NOT NULL,
  `member_id` int unsigned NOT NULL,
  `level` varchar(12) NOT NULL DEFAULT 'medium' COMMENT 'low/medium/high/urgent',
  `occurred_at` datetime NULL COMMENT '发现时间',
  `summary` varchar(500) NOT NULL COMMENT '事件与表现（去标识）',
  `action_taken` varchar(1000) NULL COMMENT '已采取的处置（告知监护人/转介/热线/紧急联系人等）',
  `status` varchar(10) NOT NULL DEFAULT 'open' COMMENT 'open 未闭环 / closed 已闭环',
  `closed_at` datetime NULL,
  `closed_by` int unsigned NULL,
  `closure_note` varchar(500) NULL COMMENT '闭环结论（必填才能关闭）',
  `created_by` int unsigned NULL,
  `created_at` datetime NULL,
  `updated_at` datetime NULL,
  PRIMARY KEY (`id`),
  KEY `k_case` (`case_id`,`status`),
  KEY `k_counselor` (`counselor_id`,`status`,`occurred_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='危机事件（P1-D）';

CREATE TABLE IF NOT EXISTS `case_homeworks` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `case_id` int unsigned NOT NULL,
  `session_id` int unsigned NULL,
  `counselor_id` int unsigned NOT NULL,
  `title` varchar(120) NOT NULL,
  `content` varchar(1000) NULL COMMENT '练习说明',
  `assigned_at` date NULL,
  `due_at` date NULL,
  `status` varchar(12) NOT NULL DEFAULT 'assigned' COMMENT 'assigned/done/cancelled',
  `completed_at` datetime NULL,
  `feedback` varchar(500) NULL COMMENT '完成情况与反馈',
  `created_at` datetime NULL,
  `updated_at` datetime NULL,
  PRIMARY KEY (`id`),
  KEY `k_case` (`case_id`,`status`),
  KEY `k_counselor` (`counselor_id`,`status`,`due_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='个案作业（P1-D）';

CREATE TABLE IF NOT EXISTS `case_followups` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `case_id` int unsigned NOT NULL,
  `counselor_id` int unsigned NOT NULL,
  `due_at` date NOT NULL COMMENT '计划联系日期',
  `channel` varchar(12) NOT NULL DEFAULT 'phone' COMMENT 'phone/wechat/sms/visit',
  `purpose` varchar(200) NULL COMMENT '随访目的（如：结案后 2 周回访）',
  `status` varchar(10) NOT NULL DEFAULT 'pending' COMMENT 'pending/done/skipped',
  `done_at` datetime NULL,
  `result` varchar(500) NULL COMMENT '随访结果',
  `created_at` datetime NULL,
  `updated_at` datetime NULL,
  PRIMARY KEY (`id`),
  KEY `k_case` (`case_id`,`status`),
  KEY `k_counselor_due` (`counselor_id`,`status`,`due_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='个案随访计划（P1-D）';
