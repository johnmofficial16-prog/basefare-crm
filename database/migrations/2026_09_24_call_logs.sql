-- =============================================================================
-- Migration: Agent Call Logs
-- Base Fare CRM — 2026-09-24
-- =============================================================================
-- Additive only. Picked up automatically by hostinger_migrate.php.
--
-- Agents log every customer call (number, reason, airline + outcome). Managers
-- and admins monitor it; a cron (cron/call_log_compliance.php) raises bell
-- notifications when clocked-in agents are not logging calls. Sat/Sun shift
-- days are off and never flagged.
--
-- `shift_date` is the operations business day the call belongs to (the date
-- the 18:00 shift started — see ShiftService::businessDayBounds), stored at
-- insert time so compliance and "today" queries are a plain index lookup.
-- =============================================================================

CREATE TABLE IF NOT EXISTS `call_logs` (
    `id`              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `agent_id`        INT             NOT NULL,
    `shift_date`      DATE            NOT NULL COMMENT 'Business day (18:00 rollover) the call belongs to',
    `direction`       ENUM('inbound','outbound') NOT NULL DEFAULT 'inbound',
    `phone`           VARCHAR(32)     NOT NULL COMMENT 'As entered by the agent',
    `phone_digits`    VARCHAR(20)     NOT NULL COMMENT 'Digits only, last 10 — for repeat-caller lookup',
    `customer_name`   VARCHAR(120)    NULL,
    `reason`          VARCHAR(40)     NOT NULL COMMENT 'Key from CallLog::REASONS',
    `airline`         VARCHAR(80)     NOT NULL,
    `outcome`         VARCHAR(30)     NOT NULL COMMENT 'Key from CallLog::OUTCOMES',
    `transaction_id`  BIGINT UNSIGNED NULL COMMENT 'Booking made on this call, if any',
    `follow_up_at`    DATETIME        NULL COMMENT 'Agent asked to be reminded to call back',
    `follow_up_notified_at` DATETIME  NULL,
    `follow_up_done`  TINYINT(1)      NOT NULL DEFAULT 0,
    `notes`           TEXT            NULL,
    `created_at`      TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`      TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (`id`),
    INDEX `idx_cl_agent_day`  (`agent_id`, `shift_date`),
    INDEX `idx_cl_day`        (`shift_date`),
    INDEX `idx_cl_phone`      (`phone_digits`),
    INDEX `idx_cl_followup`   (`follow_up_done`, `follow_up_notified_at`, `follow_up_at`),
    CONSTRAINT `fk_cl_agent` FOREIGN KEY (`agent_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_cl_txn`   FOREIGN KEY (`transaction_id`) REFERENCES `transactions` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Per-call log filled by agents; monitored by managers/admins.';

-- One row per compliance alert already sent, so the hourly cron never repeats
-- itself. `kind` = 'no_logs' (agent clocked in, nothing logged) or
-- 'shift_summary' (end-of-shift digest; agent_id NULL).
CREATE TABLE IF NOT EXISTS `call_log_alerts` (
    `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `shift_date`  DATE            NOT NULL,
    `kind`        VARCHAR(30)     NOT NULL,
    `agent_id`    INT             NULL,
    `sent_at`     DATETIME        NOT NULL,

    PRIMARY KEY (`id`),
    INDEX `idx_cla_lookup` (`shift_date`, `kind`, `agent_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='De-dupe ledger for call-log compliance notifications.';

-- =============================================================================
-- End of migration.
-- =============================================================================
