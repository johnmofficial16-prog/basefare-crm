-- Phase 3: Attendance & Time Tracking Tables Migration
-- Run AFTER Phase 2 shift tables have been applied

-- --------------------------------------------------------
-- Attendance Sessions (Section 1.4)
-- --------------------------------------------------------

CREATE TABLE IF NOT EXISTS `attendance_sessions` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `user_id` int NOT NULL,
  `clock_in` datetime NOT NULL,
  `clock_out` datetime DEFAULT NULL COMMENT 'NULL = still active',
  `scheduled_start` time NOT NULL COMMENT 'Snapshot from shift_schedules at clock-in',
  `scheduled_end` time NOT NULL COMMENT 'Snapshot from shift_schedules at clock-in',
  `late_minutes` int NOT NULL DEFAULT 0 COMMENT 'Calculated at clock-in',
  `total_work_mins` int DEFAULT NULL COMMENT 'Computed on clock-out: (out - in) - breaks',
  `total_break_mins` int NOT NULL DEFAULT 0 COMMENT 'Sum of all breaks',
  `status` enum('active','completed','admin_override','auto_closed') NOT NULL DEFAULT 'active',
  `resolution_required` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'Flagged for admin review',
  `override_by` int DEFAULT NULL COMMENT 'Admin who approved override',
  `override_reason` text DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL COMMENT 'Audit trail',
  `user_agent` text DEFAULT NULL COMMENT 'Audit trail',
  `date` date NOT NULL COMMENT 'Indexed for fast daily lookups',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_sessions_user_date` (`user_id`, `date`),
  KEY `idx_sessions_status` (`status`),
  KEY `idx_sessions_date` (`date`),
  KEY `fk_sessions_override_by` (`override_by`),
  CONSTRAINT `fk_sessions_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_sessions_override_by` FOREIGN KEY (`override_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Attendance Breaks (Section 1.5)
-- --------------------------------------------------------

CREATE TABLE IF NOT EXISTS `attendance_breaks` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `session_id` bigint NOT NULL,
  `break_type` enum('lunch','short','washroom') NOT NULL,
  `break_start` datetime NOT NULL COMMENT 'Server time',
  `break_end` datetime DEFAULT NULL COMMENT 'NULL = currently on break',
  `duration_mins` smallint DEFAULT NULL COMMENT 'Computed on break-end',
  `flagged` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'Abuse flag',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_breaks_session` (`session_id`),
  KEY `idx_breaks_type` (`session_id`, `break_type`),
  CONSTRAINT `fk_breaks_session` FOREIGN KEY (`session_id`) REFERENCES `attendance_sessions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Attendance Overrides (Supporting Tables)
-- --------------------------------------------------------

CREATE TABLE IF NOT EXISTS `attendance_overrides` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `agent_id` int NOT NULL,
  `shift_date` date NOT NULL,
  `override_type` enum('late_login','early_logout','missed_clockout','manual_entry','time_correction') NOT NULL,
  `override_by` int NOT NULL COMMENT 'Admin who approved',
  `reason` text NOT NULL,
  `original_value` text DEFAULT NULL,
  `new_value` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_override_agent_date` (`agent_id`, `shift_date`),
  KEY `fk_override_admin` (`override_by`),
  CONSTRAINT `fk_override_agent` FOREIGN KEY (`agent_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_override_admin` FOREIGN KEY (`override_by`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Seed system_config with abuse detection thresholds
-- --------------------------------------------------------

INSERT IGNORE INTO `system_config` (`key`, `value`, `updated_at`) VALUES
  ('abuse.single_washroom_max', '15', NOW()),
  ('abuse.washroom_count_max', '4', NOW()),
  ('abuse.washroom_total_max', '45', NOW()),
  ('timezone', 'Asia/Kolkata', NOW());
