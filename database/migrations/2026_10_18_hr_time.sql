-- =============================================================================
-- Trio HR — Phase 2: Time (18 Oct 2026)
-- =============================================================================
-- Punch in / punch out, holidays, month-end decisions and the monthly lock.
--
-- John's rule: no shift timings, no assumptions. HR records when someone
-- punched in and when they punched out, and nothing else. There is no
-- "late", no "absent" and no half day worked out against a shift. A day with
-- no punch is shown as "No punch"; the admin decides what it means for pay at
-- month end (hr_day_marks), then locks the month (hr_attendance_months).
--
-- Agents' punch is their CRM clock-in (read live from attendance_sessions);
-- the HR punch button is for everyone else, and works on the office network
-- only (the CRM's own ip_whitelist).
--
-- Only new tables, plus one column on hr_employees. Nothing in the CRM changes.
-- =============================================================================

-- How a person's punch is recorded. NULL = decided from their CRM role: floor
-- roles (agent, CSA, team leader, manager) clock in on the CRM, everyone else
-- uses the Trio HR button. An admin can set it either way on the person page.
ALTER TABLE `hr_employees`
  ADD COLUMN `punch_via` ENUM('crm','hr') NULL DEFAULT NULL COMMENT 'NULL = from their CRM role' AFTER `crm_user_id`;

-- One punch: in, and (once they leave) out. Belongs to the work day it was
-- punched IN on (the CRM's business-day rule, so a punch at 1 am belongs to
-- the evening before). Never guessed: an open punch stays open until the
-- person punches out or an approved fix closes it.
CREATE TABLE IF NOT EXISTS `hr_punches` (
  `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `employee_id`   INT UNSIGNED NOT NULL,
  `work_day`      DATE         NOT NULL,
  `punch_in`      DATETIME     NOT NULL,
  `punch_out`     DATETIME     NULL,
  `in_ip`         VARCHAR(45)  NOT NULL,
  `out_ip`        VARCHAR(45)  NULL,
  `out_source`    ENUM('punch','fix','admin') NULL COMMENT 'punch = they punched out, fix = approved fix request, admin = set by an admin',
  `fix_request_id` BIGINT UNSIGNED NULL,
  `out_by`        INT UNSIGNED NULL COMMENT 'hr_accounts.id when an admin set the punch-out',
  `created_at`    DATETIME     NOT NULL,
  `updated_at`    DATETIME     NOT NULL,
  PRIMARY KEY (`id`),
  KEY `ix_hr_punches_day` (`employee_id`, `work_day`),
  KEY `ix_hr_punches_open` (`punch_out`, `punch_in`),
  KEY `ix_hr_punches_work_day` (`work_day`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Company holidays. A holiday is a paid day off; if someone punches on it,
-- the day shows both facts and the admin decides (no automatic double pay).
CREATE TABLE IF NOT EXISTS `hr_holidays` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `day`         DATE         NOT NULL,
  `name`        VARCHAR(80)  NOT NULL,
  `centres`     VARCHAR(20)  NULL COMMENT 'NULL = every centre, else a comma list such as MOH,JSR',
  `created_by`  INT UNSIGNED NULL,
  `created_at`  DATETIME     NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_hr_holidays_day` (`day`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- What an admin decided a day means for pay, at month end. Only facts are
-- recorded automatically; these are the human decisions on top.
CREATE TABLE IF NOT EXISTS `hr_day_marks` (
  `employee_id` INT UNSIGNED NOT NULL,
  `work_day`    DATE         NOT NULL,
  `mark`        ENUM('day_off','unpaid','worked') NOT NULL COMMENT 'day_off = paid weekly off, unpaid = loss of pay, worked = they worked but the punch was missed',
  `note`        VARCHAR(255) NULL,
  `by_account`  INT UNSIGNED NOT NULL,
  `at`          DATETIME     NOT NULL,
  PRIMARY KEY (`employee_id`, `work_day`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- The monthly lock. Once locked, that month's punches, fixes, leave and
-- decisions can't change until an admin unlocks it (with a reason). The
-- snapshot is what payroll reads.
CREATE TABLE IF NOT EXISTS `hr_attendance_months` (
  `month`        CHAR(7)      NOT NULL COMMENT 'YYYY-MM',
  `status`       ENUM('locked','unlocked') NOT NULL,
  `snapshot`     JSON         NULL COMMENT 'Per person: days worked, paid leave, unpaid days, hours — as locked',
  `locked_by`    INT UNSIGNED NULL,
  `locked_at`    DATETIME     NULL,
  `unlocked_by`  INT UNSIGNED NULL,
  `unlocked_at`  DATETIME     NULL,
  `unlock_reason` VARCHAR(255) NULL,
  PRIMARY KEY (`month`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- A note from Trio HR itself can carry one action for the staff member, such
-- as "Tell HR when you left" on a missed punch-out (p12 = HR punch 12,
-- s345 = CRM session 345).
ALTER TABLE `hr_threads`
  ADD COLUMN `action_ref` VARCHAR(24) NULL DEFAULT NULL COMMENT 'p<punch id> or s<CRM session id>: the missed punch-out this note asks about' AFTER `request_id`;

-- One-time nudges ("You didn't punch out on 14 Oct"), so each is sent once.
CREATE TABLE IF NOT EXISTS `hr_nudges` (
  `kind`        VARCHAR(30)  NOT NULL,
  `ref`         VARCHAR(60)  NOT NULL,
  `employee_id` INT UNSIGNED NOT NULL,
  `sent_at`     DATETIME     NOT NULL,
  PRIMARY KEY (`kind`, `ref`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
