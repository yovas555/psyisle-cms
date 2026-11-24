-- =============================================================
-- 20261124_0005 · 随访计划（P2-A）
--
-- 口径：把 P1-D 的「单次随访 case_followups」升级为「计划」。
--  * followup_plans 是一张模板：周期 interval_days / 次数 times / 渠道 channel。
--  * 生成器（cron 每日）把到期计划实例化为 case_followups 的 pending 待办，
--    复用 CaseCareService::overview() 的「到期随访」常驻工作台提醒。
--  * 完成率在个案详情按 case_followups 的 done/(done+pending) 计算，不额外落库。
--  * 不动任何现有表结构；本表幂等 CREATE TABLE IF NOT EXISTS。
-- =============================================================
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `followup_plans` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `case_id` int unsigned NOT NULL,
  `counselor_id` int unsigned NOT NULL,
  `title` varchar(120) NOT NULL DEFAULT '结案后随访',
  `interval_days` int unsigned NOT NULL DEFAULT 7 COMMENT '随访间隔天数（首访偏移同此）',
  `times` int unsigned NOT NULL DEFAULT 3 COMMENT '计划随访次数',
  `channel` varchar(12) NOT NULL DEFAULT 'phone' COMMENT 'phone/wechat/sms/visit',
  `purpose` varchar(200) NULL COMMENT '随访目的',
  `status` varchar(12) NOT NULL DEFAULT 'active' COMMENT 'active 进行中 / done 已完成 / stopped 已停止',
  `next_due_at` date NULL COMMENT '下次生成日期（生成器据此推进）',
  `generated_count` int unsigned NOT NULL DEFAULT 0 COMMENT '已生成随访数（上限 times）',
  `created_at` datetime NULL,
  `updated_at` datetime NULL,
  PRIMARY KEY (`id`),
  KEY `k_case` (`case_id`,`status`),
  KEY `k_counselor` (`counselor_id`,`status`,`next_due_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='随访计划（P2-A）';
