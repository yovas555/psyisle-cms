SET NAMES utf8mb4;

SET @has_col := (SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema = DATABASE() AND table_name = 'counselors' AND column_name = 'service_modes');
SET @ddl := IF(@has_col = 0,
  'ALTER TABLE `counselors` ADD COLUMN `service_modes` varchar(120) NULL COMMENT ''服务方式：面对面/视频/电话，空值沿用预约表单全部方式'' AFTER `languages`',
  'SELECT 1');
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_col := (SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema = DATABASE() AND table_name = 'counselors' AND column_name = 'credentials');
SET @ddl := IF(@has_col = 0,
  'ALTER TABLE `counselors` ADD COLUMN `credentials` text NULL COMMENT ''公开资质与注册信息'' AFTER `style`',
  'SELECT 1');
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_col := (SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema = DATABASE() AND table_name = 'counselors' AND column_name = 'supervision');
SET @ddl := IF(@has_col = 0,
  'ALTER TABLE `counselors` ADD COLUMN `supervision` text NULL COMMENT ''公开督导与专业支持信息'' AFTER `credentials`',
  'SELECT 1');
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
