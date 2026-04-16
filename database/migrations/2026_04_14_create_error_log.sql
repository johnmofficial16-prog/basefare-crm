-- Error Log Table (Phase 8 — Error Console)
-- Run this against basefare_crm database

CREATE TABLE IF NOT EXISTS `error_log` (
  `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `severity`   VARCHAR(20)     NOT NULL DEFAULT 'error'  COMMENT 'error, warning, notice, info, critical, fatal',
  `message`    TEXT            NOT NULL,
  `file`       VARCHAR(512)    DEFAULT NULL,
  `line`       INT             DEFAULT NULL,
  `trace`      LONGTEXT        DEFAULT NULL               COMMENT 'Stack trace',
  `url`        VARCHAR(1024)   DEFAULT NULL               COMMENT 'Request URL at time of error',
  `ip_address` VARCHAR(45)     DEFAULT NULL,
  `user_id`    INT UNSIGNED    DEFAULT NULL,
  `created_at` DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_severity` (`severity`),
  KEY `idx_created_at` (`created_at`),
  KEY `idx_user_id` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
