-- Base Fare CRM - Initial Database Schema
-- Version 1.0

-- --------------------------------------------------------
-- Users & Core System
-- --------------------------------------------------------

CREATE TABLE IF NOT EXISTS `users` (
  `id` int NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `email` varchar(255) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `role` enum('admin','manager','agent') NOT NULL DEFAULT 'agent',
  `grace_period_mins` int NOT NULL DEFAULT 30,
  `status` enum('active','inactive','suspended') NOT NULL DEFAULT 'active',
  `deleted_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `login_attempts` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `ip_address` varchar(45) NOT NULL,
  `email` varchar(100) DEFAULT NULL,
  `last_attempt_at` timestamp NULL DEFAULT NULL,
  `attempts` int(11) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  KEY `login_attempts_ip_index` (`ip_address`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `sessions` (
  `id` varchar(128) NOT NULL,
  `user_id` int DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` text,
  `payload` longtext NOT NULL,
  `last_activity` int NOT NULL,
  PRIMARY KEY (`id`),
  KEY `sessions_user_id_index` (`user_id`),
  KEY `sessions_last_activity_index` (`last_activity`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `system_config` (
  `key` varchar(100) NOT NULL,
  `value` text NOT NULL,
  `updated_by` int DEFAULT NULL,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`key`),
  KEY `fk_config_updated_by` (`updated_by`),
  CONSTRAINT `fk_config_updated_by` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Activity Logging
-- --------------------------------------------------------

CREATE TABLE IF NOT EXISTS `activity_log` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `user_id` int DEFAULT NULL,
  `action` varchar(100) NOT NULL,
  `entity_type` varchar(50) DEFAULT NULL,
  `entity_id` bigint DEFAULT NULL,
  `details` json DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `fk_log_user_id` (`user_id`),
  CONSTRAINT `fk_log_user_id` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Phase 2: Shift Scheduling
-- --------------------------------------------------------

CREATE TABLE IF NOT EXISTS `shift_templates` (
  `id` int NOT NULL AUTO_INCREMENT,
  `name` varchar(50) NOT NULL,
  `start_time` time NOT NULL,
  `end_time` time NOT NULL,
  `created_by` int DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `fk_tmpl_created_by` (`created_by`),
  CONSTRAINT `fk_tmpl_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `shift_schedules` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `agent_id` int NOT NULL,
  `shift_date` date NOT NULL,
  `shift_start` time NOT NULL,
  `shift_end` time NOT NULL,
  `template_id` int DEFAULT NULL,
  `schedule_week` date NOT NULL COMMENT 'Monday of the ISO week for fast week-level queries',
  `created_by` int DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_agent_shift_date` (`agent_id`, `shift_date`),
  KEY `idx_schedule_week` (`schedule_week`),
  KEY `idx_agent_date` (`agent_id`, `shift_date`),
  KEY `fk_sched_template` (`template_id`),
  KEY `fk_sched_created_by` (`created_by`),
  CONSTRAINT `fk_sched_agent` FOREIGN KEY (`agent_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_sched_template` FOREIGN KEY (`template_id`) REFERENCES `shift_templates` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_sched_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Phase 4: Transaction Recorder
-- --------------------------------------------------------

CREATE TABLE IF NOT EXISTS `transactions` (
  `id`                      BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `agent_id`                INT NOT NULL,
  `acceptance_id`           INT UNSIGNED NULL,
  `type`                    ENUM('new_booking','exchange','seat_purchase','cabin_upgrade','cancel_refund','cancel_credit','name_correction','other') NOT NULL,
  `customer_name`           VARCHAR(255) NOT NULL,
  `customer_email`          VARCHAR(255) NOT NULL,
  `customer_phone`          VARCHAR(30) NULL,
  `pnr`                     VARCHAR(20) NOT NULL,
  `airline`                 VARCHAR(100) NULL,
  `order_id`                VARCHAR(100) NULL,
  `travel_date`             DATE NULL,
  `departure_time`          TIME NULL,
  `return_date`             DATE NULL,
  `total_amount`            DECIMAL(10,2) NOT NULL,
  `cost_amount`             DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `profit_mco`              DECIMAL(10,2) GENERATED ALWAYS AS (`total_amount` - `cost_amount`) STORED,
  `currency`                VARCHAR(3) NOT NULL DEFAULT 'USD',
  `payment_method`          ENUM('credit_card','debit_card','bank_transfer','cash','credit_shell','cheque','other') NOT NULL DEFAULT 'credit_card',
  `payment_status`          ENUM('pending','paid','partial','refunded','credited') NOT NULL DEFAULT 'pending',
  `data`                    JSON NULL,
  `status`                  ENUM('pending_review','approved','voided') NOT NULL DEFAULT 'pending_review',
  `void_reason`             TEXT NULL,
  `voided_at`               DATETIME NULL,
  `voided_by`               INT NULL,
  `void_of_transaction_id`  BIGINT UNSIGNED NULL,
  `checkin_notified`        TINYINT(1) NOT NULL DEFAULT 0,
  `checkin_completed`       TINYINT(1) NOT NULL DEFAULT 0,
  `agent_notes`             TEXT NULL,
  `created_at`              DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`              DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_agent_id` (`agent_id`),
  KEY `idx_acceptance_id` (`acceptance_id`),
  KEY `idx_pnr` (`pnr`),
  KEY `idx_type` (`type`),
  KEY `idx_status` (`status`),
  KEY `idx_customer_email` (`customer_email`),
  KEY `idx_travel_date` (`travel_date`),
  KEY `idx_created_at` (`created_at`),
  CONSTRAINT `fk_txn_agent` FOREIGN KEY (`agent_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_txn_acceptance` FOREIGN KEY (`acceptance_id`) REFERENCES `acceptance_requests` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `transaction_passengers` (
  `id`              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `transaction_id`  BIGINT UNSIGNED NOT NULL,
  `first_name`      VARCHAR(100) NOT NULL,
  `last_name`       VARCHAR(100) NOT NULL,
  `dob`             DATE NULL,
  `pax_type`        ENUM('adult','child','infant') NOT NULL DEFAULT 'adult',
  `ticket_number`   VARCHAR(25) NULL,
  `frequent_flyer`  VARCHAR(30) NULL,
  `created_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_pax_transaction` (`transaction_id`),
  CONSTRAINT `fk_pax_transaction` FOREIGN KEY (`transaction_id`) REFERENCES `transactions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `payment_cards` (
  `id`                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `transaction_id`    BIGINT UNSIGNED NOT NULL,
  `card_type`         VARCHAR(20) NOT NULL,
  `card_last_4`       CHAR(4) NOT NULL,
  `holder_name`       VARCHAR(255) NOT NULL,
  `expiry`            VARCHAR(7) NOT NULL,
  `billing_address`   TEXT NULL,
  `card_number_enc`   TEXT NULL,
  `cvv_enc`           TEXT NULL,
  `amount`            DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `is_primary`        TINYINT(1) NOT NULL DEFAULT 1,
  `last_revealed_by`  INT NULL,
  `last_revealed_at`  DATETIME NULL,
  `reveal_count`      SMALLINT NOT NULL DEFAULT 0,
  `created_at`        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_card_transaction` (`transaction_id`),
  CONSTRAINT `fk_card_transaction` FOREIGN KEY (`transaction_id`) REFERENCES `transactions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
