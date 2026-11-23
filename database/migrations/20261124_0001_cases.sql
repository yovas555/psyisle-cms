-- =============================================================
-- 20261124_0001 · 个案地基（P1-A）
-- 新增：cases（个案主档）、case_sessions（会次）
-- 口径（D1）：一个会员可多案并存，但同时只有一个 active 案。
--   MySQL 5.7 不支持部分唯一索引，故 active 唯一性在 CaseService 事务内
--   以 SELECT ... FOR UPDATE 保证（member 行加锁），不靠数据库约束。
-- 幂等：全部 CREATE TABLE IF NOT EXISTS，安装向导导入 schema.sql 与本迁移可共存。
-- =============================================================
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `cases` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `case_no` varchar(24) NOT NULL COMMENT '个案编号（C+日期-序号，对人可读）',
  `member_id` int unsigned NOT NULL COMMENT '来访者（会员）',
  `counselor_id` int unsigned NOT NULL COMMENT '主责咨询师',
  `booking_id` int unsigned NULL COMMENT '开案关联预约（选填）',
  `title` varchar(120) NOT NULL DEFAULT '' COMMENT '个案名（去标识，如「大一适应-初诊」）',
  `presenting` varchar(500) NULL COMMENT '主诉概述（勿写可识别人信息）',
  `goals` varchar(1000) NULL COMMENT '咨询目标与计划',
  `risk_level` varchar(10) NOT NULL DEFAULT 'low' COMMENT 'low/medium/high 危机风险分级',
  `status` varchar(12) NOT NULL DEFAULT 'active' COMMENT 'active 进行中 / closed 已结案 / archived 已归档',
  `opened_at` date NULL COMMENT '开案日期',
  `closed_at` datetime NULL,
  `close_reason` varchar(200) NULL,
  `session_count` int unsigned NOT NULL DEFAULT 0 COMMENT '会次计数（case_sessions 汇总，冗余加速列表）',
  `last_session_at` date NULL,
  `created_by` int unsigned NULL,
  `created_at` datetime NULL,
  `updated_at` datetime NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_case_no` (`case_no`),
  KEY `k_member` (`member_id`,`status`),
  KEY `k_counselor` (`counselor_id`,`status`,`last_session_at`),
  KEY `k_status_opened` (`status`,`opened_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='个案主档（P1-A）';

CREATE TABLE IF NOT EXISTS `case_sessions` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `case_id` int unsigned NOT NULL,
  `seq` int unsigned NOT NULL COMMENT '个案内第几次（1 起递增）',
  `counselor_id` int unsigned NOT NULL COMMENT '冗余，便于鉴权与按咨询师统计',
  `booking_id` int unsigned NULL COMMENT '对应预约（选填，便于对账）',
  `held_at` date NULL COMMENT '咨询日期',
  `duration_min` smallint unsigned NULL COMMENT '时长（分钟）',
  `method` varchar(20) NULL COMMENT '面对面/视频/电话',
  `attendance` varchar(12) NOT NULL DEFAULT 'held' COMMENT 'held 已进行 / no_show 未到 / cancelled 已取消',
  `topic` varchar(200) NULL COMMENT '本次主题（去标识）',
  `created_at` datetime NULL,
  `updated_at` datetime NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_case_seq` (`case_id`,`seq`),
  KEY `k_counselor_date` (`counselor_id`,`held_at`),
  KEY `k_booking` (`booking_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='个案会次（P1-A）';
