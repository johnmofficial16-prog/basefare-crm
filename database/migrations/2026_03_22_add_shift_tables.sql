-- Phase 2: Shift Scheduling Tables Migration
-- Run AFTER initial schema.sql has been applied

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
