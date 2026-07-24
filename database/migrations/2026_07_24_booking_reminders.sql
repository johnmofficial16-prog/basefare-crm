-- =============================================================================
-- Migration: Booking Reminders + In-App Notifications
-- Base Fare CRM — 2026-07-24
-- =============================================================================
-- Run ONCE per environment (registered in hostinger_migrate.php). Additive only.
--
-- Admins & managers attach reminders to a booking (transaction). Each reminder
-- carries a confirmed departure datetime and a fire time (`remind_at`) — either
-- a preset offset before departure (72h / 48h / 24h / custom) or an absolute
-- date/time. A cron dispatcher fires due reminders, materialising one
-- `notifications` row per recipient (the booking's agent, that agent's manager,
-- and every admin), surfaced by the in-app bell.
--
-- `notifications` is intentionally generic (not reminder-specific) so future
-- modules can reuse the same bell / unread infrastructure.
-- =============================================================================

CREATE TABLE IF NOT EXISTS `booking_reminders` (
    `id`             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `transaction_id` BIGINT UNSIGNED NOT NULL,
    `created_by`     INT             NOT NULL,
    `title`          VARCHAR(160)    NOT NULL,
    `message`        TEXT            NULL,
    `departure_at`   DATETIME        NOT NULL COMMENT 'Confirmed departure (creator-verified) in app timezone',
    `timing_mode`    ENUM('preset','absolute') NOT NULL DEFAULT 'preset',
    `offset_hours`   INT             NULL COMMENT 'Hours before departure (preset mode): 72/48/24/custom',
    `remind_at`      DATETIME        NOT NULL COMMENT 'When the reminder fires',
    `status`         ENUM('scheduled','fired','dismissed','cancelled') NOT NULL DEFAULT 'scheduled',
    `fired_at`       DATETIME        NULL,
    `created_at`     TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`     TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (`id`),
    INDEX `idx_br_txn`      (`transaction_id`),
    INDEX `idx_br_due`      (`status`, `remind_at`),
    INDEX `idx_br_creator`  (`created_by`),
    CONSTRAINT `fk_br_txn` FOREIGN KEY (`transaction_id`) REFERENCES `transactions` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_br_creator` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Reminders admins/managers attach to bookings (fire before departure).';

CREATE TABLE IF NOT EXISTS `notifications` (
    `id`             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id`        INT             NOT NULL COMMENT 'Recipient',
    `type`           VARCHAR(50)     NOT NULL DEFAULT 'booking_reminder',
    `reminder_id`    BIGINT UNSIGNED NULL,
    `transaction_id` BIGINT UNSIGNED NULL,
    `title`          VARCHAR(200)    NOT NULL,
    `body`           TEXT            NULL,
    `link`           VARCHAR(255)    NULL,
    `read_at`        DATETIME        NULL,
    `created_at`     TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (`id`),
    INDEX `idx_nf_user_unread` (`user_id`, `read_at`),
    INDEX `idx_nf_reminder`    (`reminder_id`),
    CONSTRAINT `fk_nf_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_nf_reminder` FOREIGN KEY (`reminder_id`) REFERENCES `booking_reminders` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Generic in-app notifications (bell). Reused across modules.';

-- =============================================================================
-- End of migration.
-- =============================================================================
