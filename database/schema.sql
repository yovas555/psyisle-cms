-- =============================================================
-- 心屿 PsyIsle CMS 1.0.0 全量建表脚本 (MySQL 5.7+, utf8mb4)
-- =============================================================
SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;
-- 站点设置（key-value）
CREATE TABLE `settings` (
  `skey` varchar(80) NOT NULL,
  `svalue` text NULL,
  `sgroup` varchar(40) NOT NULL DEFAULT 'general',
  `updated_at` datetime NULL,
  PRIMARY KEY (`skey`), KEY `idx_group` (`sgroup`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- 后台角色
CREATE TABLE `roles` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(40) NOT NULL,
  `code` varchar(40) NOT NULL,
  `permissions` text NULL COMMENT 'JSON 权限数组',
  `is_system` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` datetime NULL,
  PRIMARY KEY (`id`), UNIQUE KEY `uk_code` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- 后台用户
CREATE TABLE `users` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `username` varchar(40) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `password_history` text NULL COMMENT '最近5次密码hash',
  `display_name` varchar(60) NULL,
  `email` varchar(120) NULL,
  `role_id` int unsigned NOT NULL DEFAULT 1,
  `status` tinyint(1) NOT NULL DEFAULT 1,
  `totp_secret` varchar(64) NULL,
  `failed_count` tinyint unsigned NOT NULL DEFAULT 0,
  `locked_until` datetime NULL,
  `last_login_at` datetime NULL,
  `last_login_ip` varchar(64) NULL,
  `created_at` datetime NULL,
  `updated_at` datetime NULL,
  PRIMARY KEY (`id`), UNIQUE KEY `uk_username` (`username`), KEY `idx_role` (`role_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- 操作审计
CREATE TABLE `audit_logs` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int unsigned NULL,
  `username` varchar(40) NULL,
  `action` varchar(80) NOT NULL COMMENT '如 article.create',
  `target_type` varchar(40) NULL,
  `target_id` varchar(64) NULL,
  `detail` text NULL COMMENT '变更摘要 JSON',
  `ip` varchar(64) NULL,
  `prev_hash` char(64) NULL COMMENT '链式哈希：上一条 row_hash',
  `row_hash` char(64) NULL COMMENT '本记录哈希（防篡改）',
  `created_at` datetime NULL,
  PRIMARY KEY (`id`), KEY `idx_user` (`user_id`,`created_at`), KEY `idx_action` (`action`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- 栏目/分类（树形，type 区分用途）
CREATE TABLE `categories` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `pid` int unsigned NOT NULL DEFAULT 0,
  `type` varchar(20) NOT NULL DEFAULT 'article' COMMENT 'article/news/service/faq',
  `name` varchar(60) NOT NULL,
  `slug` varchar(80) NOT NULL,
  `description` varchar(255) NULL,
  `seo_title` varchar(150) NULL,
  `seo_keywords` varchar(200) NULL,
  `seo_description` varchar(300) NULL,
  `sort` int NOT NULL DEFAULT 0,
  `status` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NULL,
  PRIMARY KEY (`id`), UNIQUE KEY `uk_slug` (`slug`), KEY `idx_type_pid` (`type`,`pid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- 文章（新闻动态/科普/案例共用）
CREATE TABLE `articles` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `category_id` int unsigned NULL,
  `title` varchar(150) NOT NULL,
  `slug` varchar(120) NOT NULL,
  `cover` varchar(255) NULL,
  `summary` varchar(500) NULL COMMENT '摘要：SEO description 与 AI 引用共源',
  `content` mediumtext NULL,
  `author` varchar(40) NULL,
  `source` varchar(80) NULL,
  `tags` varchar(255) NULL,
  `views` int unsigned NOT NULL DEFAULT 0,
  `is_top` tinyint(1) NOT NULL DEFAULT 0,
  `status` varchar(20) NOT NULL DEFAULT 'draft' COMMENT 'draft/published',
  `published_at` datetime NULL,
  `seo_title` varchar(150) NULL,
  `seo_keywords` varchar(200) NULL,
  `seo_description` varchar(300) NULL,
  `created_by` int unsigned NULL,
  `created_at` datetime NULL,
  `updated_at` datetime NULL,
  PRIMARY KEY (`id`), UNIQUE KEY `uk_slug` (`slug`),
  KEY `idx_pub` (`status`,`published_at`), KEY `idx_cat` (`category_id`), FULLTEXT KEY `ft_title` (`title`,`summary`) WITH PARSER ngram
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- 单页
CREATE TABLE `pages` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `title` varchar(100) NOT NULL,
  `slug` varchar(80) NOT NULL,
  `content` mediumtext NULL,
  `show_in_nav` tinyint(1) NOT NULL DEFAULT 0,
  `status` varchar(20) NOT NULL DEFAULT 'draft',
  `seo_title` varchar(150) NULL,
  `seo_keywords` varchar(200) NULL,
  `seo_description` varchar(300) NULL,
  `created_at` datetime NULL,
  `updated_at` datetime NULL,
  PRIMARY KEY (`id`), UNIQUE KEY `uk_slug` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- 咨询师
CREATE TABLE `counselors` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(40) NOT NULL,
  `slug` varchar(80) NOT NULL,
  `gender` tinyint(1) NULL COMMENT '1男 2女',
  `title` varchar(80) NULL COMMENT '头衔：国家二级心理咨询师 等',
  `avatar` varchar(255) NULL,
  `orientation` varchar(150) NULL COMMENT '咨询流派',
  `specialties` varchar(500) NULL COMMENT '擅长方向，顿号分隔',
  `introduction` text NULL COMMENT '个人简介',
  `education` text NULL COMMENT '教育背景',
  `experience` text NULL COMMENT '从业经历/受训经历',
  `style` text NULL COMMENT '咨询风格',
  `credentials` text NULL COMMENT '公开资质与注册信息',
  `supervision` text NULL COMMENT '公开督导与专业支持信息',
  `hours` int unsigned NULL COMMENT '累计咨询时长',
  `fee_from` decimal(10,2) NULL COMMENT '收费起',
  `fee_to` decimal(10,2) NULL COMMENT '收费止',
  `fee_unit` varchar(20) NULL DEFAULT '50分钟',
  `languages` varchar(100) NULL,
  `service_modes` varchar(120) NULL COMMENT '服务方式：面对面/视频/电话，空值沿用预约表单全部方式',
  `audiences` varchar(500) NULL COMMENT '服务对象（机构维护）',
  `unsuitable_topics` varchar(1000) NULL COMMENT '不适合议题与情形（机构维护）',
  `personal_therapy` text NULL COMMENT '个人体验与自我觉察（咨询师自述）',
  `profile_draft` longtext NULL COMMENT '资质资料草稿 JSON，审核通过前不公开',
  `phone` varchar(30) NULL COMMENT '手机号（短信提醒收件）',
  `fee_override` text NULL COMMENT '分时定价 JSON: [{weekday,slot,time_from,time_to,price_cents}]',
  `on_leave` tinyint(1) NOT NULL DEFAULT 0 COMMENT '请假状态（1=暂停接单）',
  `leave_note` varchar(200) NULL COMMENT '请假说明',
  `tags` varchar(255) NULL,
  `is_featured` tinyint(1) NOT NULL DEFAULT 0,
  `sort` int NOT NULL DEFAULT 0,
  `status` tinyint(1) NOT NULL DEFAULT 1,
  `seo_title` varchar(150) NULL,
  `seo_keywords` varchar(200) NULL,
  `seo_description` varchar(300) NULL,
  `created_at` datetime NULL,
  `updated_at` datetime NULL,
  PRIMARY KEY (`id`), UNIQUE KEY `uk_slug` (`slug`), KEY `idx_status` (`status`,`sort`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- 咨询师可约时段模板
CREATE TABLE `counselor_slots` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `counselor_id` int unsigned NOT NULL,
  `weekday` tinyint NOT NULL COMMENT '1-7 周一至周日',
  `start_time` time NOT NULL,
  `end_time` time NOT NULL,
  `slot_minutes` smallint unsigned NOT NULL DEFAULT 50,
  `service_mode` varchar(16) NOT NULL DEFAULT 'all' COMMENT 'all/offline/online',
  `store_id` int unsigned NOT NULL DEFAULT 0 COMMENT '0=沿用咨询师归属门店',
  `service_id` int unsigned NOT NULL DEFAULT 0 COMMENT '0=全部已关联项目',
  `status` tinyint(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`), KEY `idx_counselor` (`counselor_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- 咨询师按日期请假（快速请假仍使用 counselors.on_leave）
CREATE TABLE `counselor_leaves` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `counselor_id` int unsigned NOT NULL,
  `start_date` date NOT NULL,
  `end_date` date NOT NULL,
  `note` varchar(190) NULL,
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_counselor_dates` (`counselor_id`,`start_date`,`end_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- 支付成功通知Outbox：管理员邮件与咨询师站内/短信/服务号幂等重试
CREATE TABLE `booking_paid_notifications` (
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
-- 咨询师可提供的服务（显式关联；不根据擅长方向或价格自动推断）
CREATE TABLE `counselor_services` (
  `counselor_id` int unsigned NOT NULL,
  `service_id` int unsigned NOT NULL,
  `price_cents` int unsigned NULL DEFAULT NULL COMMENT '咨询师项目默认价；空=使用全站服务价',
  `created_at` datetime NULL,
  PRIMARY KEY (`counselor_id`,`service_id`),
  KEY `idx_service_counselor` (`service_id`,`counselor_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- 咨询师信任信息核验台账（追加记录；内部依据不进入公开投影）
CREATE TABLE `counselor_verifications` (
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
-- 咨询师资质资料发布审核（草稿→待审→通过/驳回；通过前不覆盖公开字段）
CREATE TABLE `counselor_profile_submissions` (
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
-- 咨询师资质审核私有附件（仅登录后的咨询师本人或机构管理员可下载）
CREATE TABLE `counselor_profile_attachments` (
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
-- 服务项目
CREATE TABLE `services` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(80) NOT NULL,
  `slug` varchar(80) NOT NULL,
  `cover` varchar(255) NULL,
  `summary` varchar(500) NULL,
  `content` mediumtext NULL,
  `format` varchar(60) NULL COMMENT '咨询形式：面对面/视频/电话',
  `duration` varchar(40) NULL COMMENT '单次时长',
  `price_from` decimal(10,2) NULL,
  `price_to` decimal(10,2) NULL,
  `price_note` varchar(100) NULL,
  `sort` int NOT NULL DEFAULT 0,
  `is_featured` tinyint(1) NOT NULL DEFAULT 0,
  `status` tinyint(1) NOT NULL DEFAULT 1,
  `seo_title` varchar(150) NULL,
  `seo_keywords` varchar(200) NULL,
  `seo_description` varchar(300) NULL,
  `created_at` datetime NULL,
  `updated_at` datetime NULL,
  PRIMARY KEY (`id`), UNIQUE KEY `uk_slug` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- 预约
CREATE TABLE `bookings` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `code` varchar(20) NOT NULL COMMENT '查询码',
  `service_id` int unsigned NULL,
  `counselor_id` int unsigned NULL,
  `name` varchar(40) NOT NULL,
  `gender` tinyint(1) NULL,
  `age_range` varchar(20) NULL,
  `phone` varchar(30) NOT NULL,
  `email` varchar(120) NULL,
  `wechat` varchar(60) NULL,
  `method` varchar(20) NULL COMMENT '面对面/视频/电话',
  `prefer_date` date NULL,
  `prefer_slot` varchar(60) NULL COMMENT '期望时段描述',
  `issue` varchar(1000) NULL COMMENT '主诉概述',
  `urgency` tinyint(1) NOT NULL DEFAULT 0 COMMENT '是否紧急求助',
  `status` varchar(20) NOT NULL DEFAULT 'pending' COMMENT 'pending/confirmed/rescheduled/completed/cancelled',
  `amount_cents` int unsigned NULL COMMENT '线上咨询应付金额（分；NULL=未定价）',
  `pay_status` varchar(12) NOT NULL DEFAULT 'unpaid' COMMENT 'unpaid/paid/free/refunded（面对面=free 线下付）',
  `remind_sms_3h_at` datetime NULL COMMENT '前3小时短信已发送时间（防重）',
  `remind_wx_seen_at` datetime NULL COMMENT '会员确认（进房/点开推送）时间',
  `remind_sms_1h_at` datetime NULL COMMENT '未确认时前1小时短信已发时间（防重）',
  `admin_note` varchar(500) NULL,
  `source` varchar(30) NULL DEFAULT 'web',
  `rule_snapshot` JSON NULL COMMENT '下单时规则快照；历史记录不伪造回填',
  `ip` varchar(64) NULL,
  `created_at` datetime NULL,
  `updated_at` datetime NULL,
  PRIMARY KEY (`id`), UNIQUE KEY `uk_code` (`code`),
  KEY `idx_status` (`status`), KEY `idx_phone` (`phone`), KEY `idx_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `booking_status_events` (
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
-- 咨询留言
CREATE TABLE `messages` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `code` varchar(20) NOT NULL COMMENT '查询码',
  `name` varchar(40) NULL,
  `contact` varchar(120) NOT NULL COMMENT '电话/微信/邮箱',
  `subject` varchar(120) NULL,
  `content` varchar(2000) NOT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'pending' COMMENT 'pending/replied/closed',
  `has_unread_reply` tinyint(1) NOT NULL DEFAULT 0 COMMENT '用户未查看的新回复',
  `ip` varchar(64) NULL,
  `created_at` datetime NULL,
  `updated_at` datetime NULL,
  PRIMARY KEY (`id`), UNIQUE KEY `uk_code` (`code`), KEY `idx_status` (`status`), KEY `idx_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- 留言回复
CREATE TABLE `message_replies` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `message_id` int unsigned NOT NULL,
  `admin_user_id` int unsigned NULL,
  `content` varchar(3000) NOT NULL,
  `visible_to_user` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NULL,
  PRIMARY KEY (`id`), KEY `idx_msg` (`message_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- AI 客服会话
CREATE TABLE `chat_sessions` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `token` varchar(64) NOT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'active' COMMENT 'active/escalated/closed',
  `escalated` tinyint(1) NOT NULL DEFAULT 0,
  `contact` varchar(120) NULL COMMENT '访客留下的联系方式',
  `visitor_ip` varchar(64) NULL,
  `msg_count` int unsigned NOT NULL DEFAULT 0,
  `crisis_flag` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` datetime NULL,
  `last_active_at` datetime NULL,
  PRIMARY KEY (`id`), UNIQUE KEY `uk_token` (`token`), KEY `idx_status` (`status`,`last_active_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- AI 对话消息
CREATE TABLE `chat_messages` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `session_id` bigint unsigned NOT NULL,
  `role` varchar(12) NOT NULL COMMENT 'user/assistant/system',
  `content` text NOT NULL,
  `model` varchar(60) NULL,
  `tokens` int unsigned NULL,
  `created_at` datetime NULL,
  PRIMARY KEY (`id`), KEY `idx_session` (`session_id`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- AI 知识库词条
CREATE TABLE `ai_kb` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `question` varchar(200) NOT NULL,
  `answer` text NOT NULL,
  `keywords` varchar(255) NULL,
  `source` varchar(20) NOT NULL DEFAULT 'manual' COMMENT 'manual/auto_faq/site',
  `enabled` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NULL,
  `updated_at` datetime NULL,
  PRIMARY KEY (`id`), KEY `idx_enabled` (`enabled`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- FAQ
CREATE TABLE `faqs` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `category_id` int unsigned NULL,
  `question` varchar(200) NOT NULL,
  `answer` text NOT NULL,
  `sort` int NOT NULL DEFAULT 0,
  `status` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NULL,
  `updated_at` datetime NULL,
  PRIMARY KEY (`id`), KEY `idx_cat` (`category_id`,`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- 轮播图
CREATE TABLE `banners` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `title` varchar(100) NULL,
  `image` varchar(255) NOT NULL,
  `link` varchar(255) NULL,
  `position` varchar(30) NOT NULL DEFAULT 'home',
  `sort` int NOT NULL DEFAULT 0,
  `status` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NULL,
  PRIMARY KEY (`id`), KEY `idx_pos` (`position`,`status`,`sort`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- 导航菜单
CREATE TABLE `navigations` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `title` varchar(40) NOT NULL,
  `url` varchar(255) NOT NULL,
  `location` varchar(20) NOT NULL DEFAULT 'header' COMMENT 'header/footer/mobile',
  `sort` int NOT NULL DEFAULT 0,
  `status` tinyint(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`), KEY `idx_loc` (`location`,`status`,`sort`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- 友情链接
CREATE TABLE `links` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(60) NOT NULL,
  `url` varchar(255) NOT NULL,
  `logo` varchar(255) NULL,
  `sort` int NOT NULL DEFAULT 0,
  `status` tinyint(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- 附件媒体库
CREATE TABLE `attachments` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `path` varchar(255) NOT NULL,
  `original_name` varchar(200) NULL,
  `mime` varchar(80) NULL,
  `size` int unsigned NOT NULL DEFAULT 0,
  `hash` varchar(64) NULL,
  `module` varchar(30) NULL COMMENT 'article/banner/avatar 等',
  `uploaded_by` int unsigned NULL,
  `created_at` datetime NULL,
  PRIMARY KEY (`id`), KEY `idx_hash` (`hash`), KEY `idx_module` (`module`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- 邮件队列
CREATE TABLE `email_queue` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `to_email` varchar(120) NOT NULL,
  `subject` varchar(200) NOT NULL,
  `body_html` text NOT NULL,
  `priority` tinyint NOT NULL DEFAULT 5 COMMENT '1 最高',
  `status` varchar(15) NOT NULL DEFAULT 'queued' COMMENT 'queued/sending/sent/failed',
  `attempts` tinyint unsigned NOT NULL DEFAULT 0,
  `error` varchar(500) NULL,
  `scheduled_at` datetime NULL,
  `sent_at` datetime NULL,
  `created_at` datetime NULL,
  PRIMARY KEY (`id`), KEY `idx_status` (`status`,`scheduled_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- 站内通知
CREATE TABLE `notifications` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `type` varchar(30) NOT NULL COMMENT 'booking/message/chat_escalate/login_alert/system',
  `title` varchar(150) NOT NULL,
  `body` varchar(500) NULL,
  `ref_type` varchar(30) NULL,
  `ref_id` varchar(64) NULL,
  `is_read` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` datetime NULL,
  PRIMARY KEY (`id`), KEY `idx_read` (`is_read`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- 访问日志（隐私：IP 哈希）
CREATE TABLE `visit_logs` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `ip_hash` char(32) NULL,
  `path` varchar(255) NULL,
  `referer_host` varchar(120) NULL,
  `ua` varchar(200) NULL,
  `created_at` datetime NULL,
  PRIMARY KEY (`id`), KEY `idx_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- 访问统计日聚合
CREATE TABLE `visit_stats` (
  `date` date NOT NULL,
  `pv` int unsigned NOT NULL DEFAULT 0,
  `uv` int unsigned NOT NULL DEFAULT 0,
  `top_pages` text NULL COMMENT 'JSON',
  `top_sources` text NULL COMMENT 'JSON',
  PRIMARY KEY (`date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- 排班例外日期（schedule-pro：临时开诊/停诊覆盖周模板）
CREATE TABLE `schedule_overrides` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `counselor_id` int unsigned NOT NULL,
  `date` date NOT NULL,
  `status` varchar(10) NOT NULL DEFAULT 'closed' COMMENT 'closed=停诊 / open=开诊',
  `start_time` time NULL COMMENT 'open 时可选：当日限定时段',
  `end_time` time NULL,
  `note` varchar(200) NULL,
  `created_at` datetime NULL,
  PRIMARY KEY (`id`), UNIQUE KEY `uk_cd` (`counselor_id`,`date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- 考勤（schedule-pro：预约完成后标记）
CREATE TABLE `attendance` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `booking_id` int unsigned NOT NULL,
  `counselor_id` int unsigned NOT NULL,
  `mark` varchar(12) NOT NULL DEFAULT 'completed' COMMENT 'completed/noshow/late',
  `note` varchar(200) NULL,
  `marked_by` varchar(40) NULL,
  `marked_at` datetime NULL,
  PRIMARY KEY (`id`), UNIQUE KEY `uk_booking` (`booking_id`), KEY `k_counselor` (`counselor_id`,`mark`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- 课时包（packages-pro）
CREATE TABLE `packages` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(60) NOT NULL,
  `sessions` int unsigned NOT NULL DEFAULT 10 COMMENT '次数',
  `price_cents` bigint unsigned NOT NULL DEFAULT 0 COMMENT '售价（分）',
  `valid_days` int unsigned NOT NULL DEFAULT 365 COMMENT '自购买起有效天数',
  `bonus_text` varchar(100) NULL COMMENT '促销说明（如 买10送1）',
  `status` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- 会员课时包订单（余额与有效期）
CREATE TABLE `package_orders` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `member_id` int unsigned NOT NULL,
  `package_id` int unsigned NOT NULL,
  `total` int unsigned NOT NULL DEFAULT 0,
  `remain` int unsigned NOT NULL DEFAULT 0,
  `expire_at` date NULL,
  `status` varchar(12) NOT NULL DEFAULT 'active' COMMENT 'active/exhausted/expired/refunded',
  `channel` varchar(30) NULL COMMENT 'sale=售课 / referral=转介绍赠送',
  `note` varchar(200) NULL,
  `created_at` datetime NULL,
  PRIMARY KEY (`id`), KEY `k_member` (`member_id`,`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- 优惠券/邀请码（packages-pro）
CREATE TABLE `coupons` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `code` varchar(24) NOT NULL COMMENT '兑换码',
  `kind` varchar(12) NOT NULL DEFAULT 'package' COMMENT 'package=兑换课时包 / referral=转介绍邀请码',
  `package_id` int unsigned NULL,
  `sessions` int unsigned NOT NULL DEFAULT 0 COMMENT 'package 券兑换次数',
  `owner_member_id` int unsigned NULL COMMENT 'referral 券的推荐人（核销后返课时）',
  `bonus_sessions` int unsigned NOT NULL DEFAULT 1 COMMENT '推荐人返次数',
  `usage_limit` int unsigned NOT NULL DEFAULT 1,
  `used` int unsigned NOT NULL DEFAULT 0,
  `valid_until` date NULL,
  `note` varchar(200) NULL,
  `status` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NULL,
  PRIMARY KEY (`id`), UNIQUE KEY `uk_code` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- 券使用记录
CREATE TABLE `coupon_usages` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `coupon_id` int unsigned NOT NULL,
  `member_id` int unsigned NOT NULL,
  `order_id` int unsigned NULL,
  `created_at` datetime NULL,
  PRIMARY KEY (`id`), KEY `k_coupon` (`coupon_id`), KEY `k_member` (`member_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- 对账流水（packages-pro：所有课包变动一笔一账）
CREATE TABLE `package_ledger` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `member_id` int unsigned NOT NULL,
  `kind` varchar(16) NOT NULL COMMENT 'sale/use/bonus/redeem/refund',
  `order_id` int unsigned NULL,
  `booking_id` int unsigned NULL,
  `sessions` int NOT NULL COMMENT '正=入账 负=核销',
  `amount_cents` bigint NOT NULL DEFAULT 0,
  `note` varchar(200) NULL,
  `created_at` datetime NULL,
  PRIMARY KEY (`id`), KEY `k_member` (`member_id`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- 会员（自助中心；phone 唯一，数据仅本人可见）
CREATE TABLE `members` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `phone` varchar(30) NOT NULL,
  `name` varchar(40) NULL,
  `email` varchar(120) NULL COMMENT '会员邮箱（通知主通道）',
  `password_hash` varchar(255) NULL COMMENT '密码（bcrypt；手机号+密码注册/登录）',
  `notify_prefs` varchar(100) NULL COMMENT '通知偏好 JSON：{"mail":1,"wechat":1}',
  `status` varchar(20) NOT NULL DEFAULT 'active' COMMENT 'active/delete_requested',
  `created_at` datetime NULL,
  `last_login_at` datetime NULL,
  `last_login_ip_hash` char(32) NULL,
  `openid` varchar(64) NULL COMMENT '公众号 OpenID（网页授权/JSAPI 支付）',
  `unionid` varchar(64) NULL COMMENT '微信开放平台 UnionID（跨端唯一）',
  `wx_openid` varchar(64) NULL COMMENT '开放平台扫码登录 OpenID（与公众号不同）',
  `avatar` varchar(255) NULL COMMENT '微信头像 URL',
  PRIMARY KEY (`id`), UNIQUE KEY `uk_phone` (`phone`), KEY `k_status` (`status`),
  UNIQUE KEY `uk_openid` (`openid`), KEY `k_unionid` (`unionid`), KEY `k_wx_openid` (`wx_openid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- 会员通知台账（v2.9.0：邮件/微信/中台 全通道投递记录，排查触达问题）
-- 会员站内信（v2.14.0：预约/支付/套餐/系统等会员侧通知）
CREATE TABLE `member_notifications` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `member_id` int unsigned NOT NULL,
  `kind` varchar(16) NOT NULL DEFAULT 'booking' COMMENT 'booking/order/assess/system',
  `title` varchar(120) NOT NULL,
  `body` varchar(500) NULL,
  `url` varchar(200) NULL COMMENT '站内跳转路径（/member/...）',
  `ref_type` varchar(20) NULL,
  `ref_id` varchar(20) NULL,
  `is_read` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` datetime NULL,
  PRIMARY KEY (`id`), KEY `k_member` (`member_id`,`is_read`), KEY `k_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- 咨询师站内信（工作台消息：预约变动/小结提醒/系统）
CREATE TABLE `counselor_notifications` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `counselor_id` int unsigned NOT NULL,
  `kind` varchar(16) NOT NULL DEFAULT 'booking',
  `title` varchar(120) NOT NULL,
  `body` varchar(500) NULL,
  `url` varchar(200) NULL,
  `booking_id` int unsigned NULL,
  `is_read` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` datetime NULL,
  PRIMARY KEY (`id`), KEY `k_csl` (`counselor_id`,`is_read`), KEY `k_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- 咨询师微信绑定（服务号 OpenID；wxhub-client/wxlogin 咨询师侧绑定写入）
CREATE TABLE `counselor_notify_log` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `counselor_id` int unsigned NOT NULL,
  `event` varchar(24) NOT NULL,
  `channel` varchar(12) NOT NULL DEFAULT 'sms',
  `status` varchar(10) NOT NULL DEFAULT 'ok',
  `created_at` datetime NULL,
  PRIMARY KEY (`id`), KEY `k_csl` (`counselor_id`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `counselor_wx` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `counselor_id` int unsigned NOT NULL,
  `openid` varchar(64) NOT NULL,
  `unionid` varchar(64) NULL,
  `nickname` varchar(80) NULL,
  `created_at` datetime NULL,
  PRIMARY KEY (`id`), UNIQUE KEY `uk_openid` (`openid`), KEY `k_counselor` (`counselor_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `member_notify_log` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `member_id` int unsigned NOT NULL,
  `event` varchar(30) NOT NULL COMMENT 'booking_confirm/order_paid/ledger_change/…',
  `channel` varchar(12) NOT NULL COMMENT 'mail/wechat/skipped',
  `status` varchar(10) NOT NULL DEFAULT 'ok' COMMENT 'ok/fail/skipped',
  `skip_reason` varchar(120) NULL,
  `title` varchar(100) NULL,
  `created_at` datetime NULL,
  PRIMARY KEY (`id`), KEY `k_member` (`member_id`,`event`), KEY `k_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- 通知兜底台账（v2.17.0：短信兜底制——微信/邮件推送 30 分钟无查阅回执 → Cron sweep 补发短信）
CREATE TABLE `notify_escalations` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `member_id` int unsigned NOT NULL,
  `event` varchar(30) NOT NULL,
  `ref_type` varchar(20) NULL,
  `ref_id` varchar(20) NULL,
  `inapp_id` int unsigned NULL COMMENT '关联 member_notifications.id（已读=查阅）',
  `click_token` char(16) NOT NULL COMMENT '中转短链 /n/{token}（点击=查阅）',
  `wechat_ok` tinyint(1) NOT NULL DEFAULT 0,
  `mail_ok` tinyint(1) NOT NULL DEFAULT 0,
  `read_at` datetime NULL COMMENT '任一查阅信号落点（已读/点击）',
  `sms_at` datetime NULL COMMENT '兜底短信处理时间（含无手机号/偏好关闭的跳过）',
  `created_at` datetime NULL,
  PRIMARY KEY (`id`), UNIQUE KEY `uk_token` (`click_token`), KEY `k_member` (`member_id`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- 咨询套餐购买申请（v2.9.0：会员自助下单，线下收款模式；机构确认收款后入账）
CREATE TABLE `package_requests` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `request_no` varchar(24) NOT NULL COMMENT '申请号 REQ-YYYYMMDD-XXXXXX',
  `member_id` int unsigned NOT NULL,
  `package_id` int unsigned NOT NULL,
  `amount_cents` int NOT NULL DEFAULT 0 COMMENT '下单时套餐价格（分）',
  `pay_channel` varchar(20) NOT NULL DEFAULT 'offline' COMMENT 'offline/offline_transfer（线下转账/收款码）',
  `status` varchar(12) NOT NULL DEFAULT 'pending' COMMENT 'pending/confirmed/rejected',
  `payer_note` varchar(200) NULL COMMENT '会员付款留言（转账流水号等）',
  `confirm_note` varchar(200) NULL COMMENT '机构确认备注',
  `confirmed_by` int unsigned NULL,
  `order_id` int unsigned NULL COMMENT '确认后生成的 package_orders id',
  `created_at` datetime NULL,
  `confirmed_at` datetime NULL,
  PRIMARY KEY (`id`), UNIQUE KEY `uk_request_no` (`request_no`),
  KEY `k_member` (`member_id`), KEY `k_status` (`status`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- 授权
CREATE TABLE `licenses` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `license_key` varchar(100) NOT NULL,
  `domain` varchar(120) NULL,
  `edition` varchar(20) NOT NULL DEFAULT 'trial' COMMENT 'trial/studio/standard/pro',
  `features` text NULL COMMENT 'JSON 功能矩阵',
  `owner_email` varchar(120) NULL,
  `issued_at` datetime NULL,
  `expires_at` datetime NULL COMMENT 'NULL=永久',
  `activated_at` datetime NULL,
  `last_check_at` datetime NULL,
  `status` varchar(20) NOT NULL DEFAULT 'active',
  `client_ip` varchar(64) NULL COMMENT '升级分发绑定 IP（主站记录，空=未绑定）',
  `last_ip` varchar(64) NULL COMMENT '最近一次请求 IP（主站记录）',
  `update_enabled` tinyint NOT NULL DEFAULT 1 COMMENT '是否允许远程升级分发',
  PRIMARY KEY (`id`), UNIQUE KEY `uk_key` (`license_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- 升级历史
CREATE TABLE `upgrade_history` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `from_version` varchar(20) NULL,
  `to_version` varchar(20) NOT NULL,
  `package_file` varchar(255) NULL,
  `backup_file` varchar(255) NULL,
  `result` varchar(20) NULL COMMENT 'success/failed/rolledback',
  `detail` text NULL,
  `created_at` datetime NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- 升级分发主站：发行包登记（仅主站使用）
CREATE TABLE `upgrade_packages` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `kind` varchar(10) NOT NULL DEFAULT 'core' COMMENT 'core=整站升级包 / plugin=插件包',
  `slug` varchar(60) NULL COMMENT '插件包的 slug，core 为 NULL',
  `version` varchar(20) NOT NULL,
  `file_path` varchar(255) NOT NULL COMMENT 'storage/releases/ 下相对文件名',
  `md5` char(32) NOT NULL,
  `size` int unsigned NOT NULL DEFAULT 0,
  `min_from` varchar(20) NULL COMMENT '可直升的最低来源版本',
  `changelog` text NULL,
  `status` varchar(20) NOT NULL DEFAULT 'active' COMMENT 'active/retired',
  `downloads` int unsigned NOT NULL DEFAULT 0,
  `released_at` datetime NULL,
  `created_at` datetime NULL,
  PRIMARY KEY (`id`), KEY `k_kind` (`kind`,`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- 升级分发 API 审计（仅主站使用）
CREATE TABLE `upgrade_api_logs` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `action` varchar(20) NOT NULL COMMENT 'activate/check/token/download/deny',
  `license` varchar(100) NULL,
  `domain` varchar(120) NULL,
  `ip` varchar(64) NULL,
  `result` varchar(10) NOT NULL COMMENT 'ok/deny/error',
  `detail` varchar(255) NULL,
  `created_at` datetime NULL,
  PRIMARY KEY (`id`), KEY `k_created` (`created_at`), KEY `k_license` (`license`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SET FOREIGN_KEY_CHECKS = 1;
-- 心理自评量表（内置 PHQ-9/GAD-7，仅筛查提示不构成诊断）
CREATE TABLE `assessments` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `slug` varchar(60) NOT NULL,
  `name` varchar(80) NOT NULL,
  `description` varchar(300) NULL,
  `items` text NOT NULL COMMENT 'JSON: [{q, options:[{label,score}]}]',
  `scoring` text NOT NULL COMMENT 'JSON: [{max, level, advice}] 升序区间',
  `disclaimer` varchar(300) NULL,
  `sort` int NOT NULL DEFAULT 0,
  `status` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NULL,
  PRIMARY KEY (`id`), UNIQUE KEY `uk_slug` (`slug`), KEY `idx_status` (`status`,`sort`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- 量表提交记录（隐私：不存答案明细，只存分数/分级/IP哈希）
CREATE TABLE `assessment_results` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `assessment_id` int unsigned NOT NULL,
  `score` smallint unsigned NOT NULL,
  `level` varchar(30) NOT NULL,
  `ip_hash` char(32) NULL,
  `member_id` int unsigned NULL COMMENT '提交时已登录的会员（可空=匿名）',
  `answers` text NULL COMMENT 'JSON 每题答案（报告页维度计分用；量表免责声明已明示）',
  `report_token` char(32) NULL COMMENT '报告短链 token（脱敏回看）',
  `created_at` datetime NULL,
  PRIMARY KEY (`id`), KEY `idx_a` (`assessment_id`,`created_at`), KEY `idx_member` (`member_id`,`created_at`), KEY `idx_token` (`report_token`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- 会员心声（授权+匿名化的展示评价）
CREATE TABLE `testimonials` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `alias` varchar(40) NOT NULL COMMENT '化名，如 L 女士',
  `content` varchar(500) NOT NULL,
  `meta` varchar(100) NULL COMMENT '如：情绪困扰 · 咨询 12 次',
  `sort` int NOT NULL DEFAULT 0,
  `status` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NULL,
  PRIMARY KEY (`id`), KEY `idx_status` (`status`,`sort`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- 活动/工作坊/讲座（机构获客利器）
CREATE TABLE `events` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `title` varchar(120) NOT NULL,
  `slug` varchar(120) NOT NULL,
  `cover` varchar(255) NULL,
  `summary` varchar(300) NULL,
  `content` mediumtext NULL,
  `event_date` datetime NULL COMMENT '开始时间',
  `duration_hours` decimal(4,1) NULL COMMENT '时长小时',
  `location` varchar(200) NULL,
  `capacity` int unsigned NULL COMMENT '名额上限',
  `fee` varchar(40) NULL COMMENT '费用描述（免费/XX元）',
  `status` varchar(20) NOT NULL DEFAULT 'draft' COMMENT 'draft/published/full/closed',
  `sort` int NOT NULL DEFAULT 0,
  `seo_title` varchar(150) NULL,
  `seo_description` varchar(300) NULL,
  `created_at` datetime NULL,
  `updated_at` datetime NULL,
  PRIMARY KEY (`id`), UNIQUE KEY `uk_slug` (`slug`), KEY `idx_status_date` (`status`,`event_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- 活动报名名单
CREATE TABLE `event_registrations` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `event_id` int unsigned NOT NULL,
  `name` varchar(40) NOT NULL,
  `phone` varchar(30) NOT NULL,
  `people_count` tinyint unsigned NOT NULL DEFAULT 1,
  `note` varchar(300) NULL,
  `status` varchar(20) NOT NULL DEFAULT 'pending' COMMENT 'pending/confirmed/cancelled',
  `ip` varchar(64) NULL,
  `created_at` datetime NULL,
  PRIMARY KEY (`id`), KEY `idx_event` (`event_id`,`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
ALTER TABLE `messages` ADD COLUMN `is_featured` tinyint(1) NOT NULL DEFAULT 0 COMMENT '精选公开问答' AFTER `status`;
ALTER TABLE `counselors` ADD COLUMN `video_url` varchar(255) NULL COMMENT '视频介绍（B站/腾讯视频）' AFTER `avatar`;
-- 内容修订版本（文章/单页快照，保留最近 10 版）
CREATE TABLE `revisions` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `target_type` varchar(20) NOT NULL COMMENT 'article/page',
  `target_id` int unsigned NOT NULL,
  `snapshot` mediumtext NOT NULL COMMENT 'JSON 全字段快照',
  `created_by` int unsigned NULL,
  `created_at` datetime NULL,
  PRIMARY KEY (`id`), KEY `idx_target` (`target_type`,`target_id`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
ALTER TABLE `articles` ADD COLUMN `deleted_at` datetime NULL COMMENT '回收站' AFTER `is_top`;
ALTER TABLE `pages` ADD COLUMN `deleted_at` datetime NULL AFTER `show_in_nav`;
-- ============ v2.8.0：多门店 / 企业 EAP / 知情同意电子签署 ============

-- 门店（多门店授权 licenses.features.multisite 开启后可见）
CREATE TABLE `stores` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(60) NOT NULL,
  `address` varchar(200) NULL,
  `phone` varchar(30) NULL,
  `hours` varchar(120) NULL,
  `latitude` decimal(10,7) NULL COMMENT '纬度',
  `longitude` decimal(10,7) NULL COMMENT '经度',
  `landmark` varchar(120) NULL COMMENT '最近地标（地铁站/大厦名）',
  `navigation_note` text NULL COMMENT '到访指引（最后几百米步行说明）',
  `sort` int NOT NULL DEFAULT 0,
  `status` tinyint NOT NULL DEFAULT 1,
  `created_at` datetime NULL,
  PRIMARY KEY (`id`), KEY `k_status` (`status`,`sort`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- 企业（EAP 客户；企业码供员工进入预约）
CREATE TABLE `enterprises` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `code` varchar(20) NOT NULL COMMENT '企业码（员工入口）',
  `contact_person` varchar(40) NULL,
  `contact_phone` varchar(30) NULL,
  `seats` int NOT NULL DEFAULT 0 COMMENT '名额上限（0=不限）',
  `note` varchar(200) NULL,
  `status` tinyint NOT NULL DEFAULT 1,
  `created_at` datetime NULL,
  PRIMARY KEY (`id`), UNIQUE KEY `uk_code` (`code`), KEY `k_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- 知情同意书模板（签署时全文快照进签署记录，模板后续修改不影响已签记录）
CREATE TABLE `consent_templates` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `title` varchar(100) NOT NULL,
  `version` int NOT NULL DEFAULT 1 COMMENT '同标题递增版本号',
  `content` mediumtext NOT NULL COMMENT '富文本正文（入库前 Purifier 清洗）',
  `status` tinyint NOT NULL DEFAULT 1 COMMENT '1=可发起签署',
  `created_at` datetime NULL,
  `updated_at` datetime NULL,
  PRIMARY KEY (`id`), KEY `k_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- 签署记录（存证：全文快照 + 哈希链 + 时间戳；evidence_hash 逐条链接，篡改/删除断链可检测）
CREATE TABLE `consent_signatures` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `evidence_no` varchar(24) NOT NULL COMMENT '存证号 CST-YYYYMMDD-XXXXXX',
  `template_id` int unsigned NOT NULL,
  `template_title` varchar(100) NOT NULL,
  `template_version` int NOT NULL DEFAULT 1,
  `template_snapshot` mediumtext NOT NULL COMMENT '签署时模板全文快照',
  `content_hash` char(64) NOT NULL COMMENT '全文 sha256',
  `signer_type` varchar(10) NOT NULL DEFAULT 'self' COMMENT 'self/guardian',
  `signer_name` varchar(40) NOT NULL,
  `signer_phone` varchar(30) NOT NULL,
  `guardian_name` varchar(40) NULL COMMENT '监护人姓名（guardian 时）',
  `member_id` int unsigned NULL,
  `booking_id` int unsigned NULL,
  `token` char(32) NULL COMMENT '签署链接 token（pending 期有效）',
  `status` varchar(10) NOT NULL DEFAULT 'pending' COMMENT 'pending/signed/revoked',
  `signature_image` varchar(255) NULL COMMENT '手写签名 PNG（uploads/consents/）',
  `signed_at` datetime NULL,
  `ip_hash` char(32) NULL,
  `ua_hash` char(64) NULL,
  `prev_hash` char(64) NULL COMMENT '链上前一条存证哈希',
  `evidence_hash` char(64) NULL COMMENT '本条存证哈希',
  `initiated_by` int unsigned NULL COMMENT '发起人（后台用户）',
  `expire_at` datetime NULL COMMENT '签署链接有效期',
  `created_at` datetime NULL,
  PRIMARY KEY (`id`), UNIQUE KEY `uk_evidence_no` (`evidence_no`), UNIQUE KEY `uk_token` (`token`),
  KEY `k_status` (`status`,`created_at`), KEY `k_member` (`member_id`), KEY `k_booking` (`booking_id`), KEY `k_phone` (`signer_phone`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- 业务表门店/企业归属（store_id 0=未指定/总店）
ALTER TABLE `bookings` ADD COLUMN `store_id` int NOT NULL DEFAULT 0 COMMENT '门店' AFTER `counselor_id`;
ALTER TABLE `bookings` ADD COLUMN `enterprise_id` int unsigned NULL COMMENT '企业 EAP 来源' AFTER `store_id`;
ALTER TABLE `counselors` ADD COLUMN `store_id` int NOT NULL DEFAULT 0 COMMENT '归属门店' AFTER `video_url`;
-- ============ v2.10.0：咨询师工作台 ============

-- 咨询师 ↔ 后台账号关联（咨询师登录后台工作台）
ALTER TABLE `counselors` ADD COLUMN `user_id` int unsigned NULL COMMENT '关联后台账号（咨询师工作台）' AFTER `store_id`;
-- 后台账号微信绑定（咨询师微信快捷登录）
ALTER TABLE `users` ADD COLUMN `openid` varchar(64) NULL COMMENT '微信 OpenID（快捷登录）',
  ADD COLUMN `unionid` varchar(64) NULL COMMENT '微信 UnionID',
  ADD COLUMN `avatar` varchar(255) NULL COMMENT '微信头像';
ALTER TABLE `users` ADD UNIQUE KEY `uk_openid` (`openid`);
-- 咨询小结与作业训练（咨询师发给指定咨客阅读）
CREATE TABLE `counselor_notes` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `counselor_id` int unsigned NOT NULL,
  `member_id` int unsigned NOT NULL COMMENT '目标咨客（会员）',
  `booking_id` int unsigned NULL COMMENT '关联预约（选填）',
  `kind` varchar(10) NOT NULL DEFAULT 'summary' COMMENT 'summary=咨询小结 homework=作业训练',
  `title` varchar(120) NOT NULL,
  `content` mediumtext NOT NULL COMMENT '富文本正文',
  `visible` tinyint NOT NULL DEFAULT 1 COMMENT '对咨客可见',
  `created_by` int unsigned NULL,
  `created_at` datetime NULL,
  `updated_at` datetime NULL,
  PRIMARY KEY (`id`),
  KEY `k_counselor` (`counselor_id`,`created_at`), KEY `k_member` (`member_id`,`visible`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================================
-- v2.22.0 · 个案主线（P1-A/B/C/D）：以下 DDL 与 database/migrations/*.sql 逐字一致
-- 全新安装直接建表；已装站点由 Migrator 按迁移文件增量执行，两条路径结果相同。
-- =============================================================

-- 个案主档与会次（P1-A）
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
  UNIQUE KEY `uk_case_session_booking` (`booking_id`),
  KEY `k_counselor_date` (`counselor_id`,`held_at`),
  KEY `k_booking` (`booking_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='个案会次（P1-A）';

-- 结构化咨询记录与版本快照（P1-B）
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
  `ai_task_id` int unsigned NULL COMMENT 'AI 任务来源（AI 只是草稿，人工确认后才有此值）',
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

-- AI 草稿任务（P1-C）
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

-- 危机 / 作业 / 随访（P1-D）
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
  `booking_id` int unsigned NULL COMMENT '来源预约',
  `session_id` int unsigned NULL COMMENT '来源会次',
  `source` varchar(32) NOT NULL DEFAULT 'manual' COMMENT 'manual/booking_feedback',
  `source_key` varchar(190) NULL COMMENT '来源幂等键',
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
  UNIQUE KEY `uk_followup_source_key` (`source_key`),
  KEY `k_case` (`case_id`,`status`),
  KEY `k_counselor_due` (`counselor_id`,`status`,`due_at`),
  KEY `k_followup_booking` (`booking_id`),
  KEY `k_followup_session` (`session_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='个案随访计划（P1-D）';

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

-- 随访计划（P2-A）
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
