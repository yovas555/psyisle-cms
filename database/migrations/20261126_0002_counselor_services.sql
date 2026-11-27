CREATE TABLE IF NOT EXISTS `counselor_services` (
  `counselor_id` int unsigned NOT NULL,
  `service_id` int unsigned NOT NULL,
  `created_at` datetime NULL,
  PRIMARY KEY (`counselor_id`,`service_id`),
  KEY `idx_service_counselor` (`service_id`,`counselor_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
