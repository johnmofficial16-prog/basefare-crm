-- =============================================================================
-- Trio HR — Phase 1b: Trio Mail, announcements, sign-in from the CRM (17 Oct 2026)
-- =============================================================================
-- Trio Mail is an inbox inside Trio HR that looks like email. Staff write to
-- HR (with a topic, and for leave, attendance and letters the request is filed
-- with the message) and admins reply. It is stored here, not sent as real
-- email: a real email or CRM-bell nudge only says that a reply is waiting.
--
-- Only new tables. Nothing in the CRM schema changes.
-- =============================================================================

-- One conversation between one staff member and HR (the admins as a team).
CREATE TABLE IF NOT EXISTS `hr_threads` (
  `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `employee_id`   INT UNSIGNED NOT NULL COMMENT 'The staff member the conversation is with',
  `topic`         VARCHAR(20)  NOT NULL COMMENT 'leave, attendance, pay, documents, letter, shift, complaint, other',
  `subject`       VARCHAR(160) NOT NULL,
  `status`        ENUM('open','done') NOT NULL DEFAULT 'open' COMMENT 'Done = an admin closed it, a new staff message reopens it',
  `private`       TINYINT(1)   NOT NULL DEFAULT 0 COMMENT 'Complaints: every open is logged',
  `request_id`    BIGINT UNSIGNED NULL COMMENT 'hr_requests.id filed with the first message, if any',
  `started_by`    ENUM('staff','hr') NOT NULL,
  `last_from`     ENUM('staff','hr','system') NOT NULL,
  `last_at`       DATETIME     NOT NULL,
  `last_preview`  VARCHAR(160) NOT NULL DEFAULT '',
  `last_message_id` BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Newest message staff can see',
  `staff_read_upto` BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Newest message the staff member has seen (unread = less than last_message_id)',
  `hr_read_upto`  BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Newest message an admin has seen',
  `hr_read_at`    DATETIME NULL COMMENT 'When an admin last opened it (shown to staff as Seen by HR)',
  `hr_read_by`    INT UNSIGNED NULL COMMENT 'hr_accounts.id',
  `escalated_at`  DATETIME NULL COMMENT 'When the owner was told it had waited too long',
  `created_at`    DATETIME NOT NULL,
  `updated_at`    DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  KEY `ix_hr_threads_employee` (`employee_id`, `last_at`),
  KEY `ix_hr_threads_inbox` (`status`, `last_from`, `last_at`),
  KEY `ix_hr_threads_request` (`request_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `hr_messages` (
  `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `thread_id`  BIGINT UNSIGNED NOT NULL,
  `sender`     ENUM('staff','hr','system') NOT NULL COMMENT 'System = written by Trio HR, e.g. a decision',
  `account_id` INT UNSIGNED NULL COMMENT 'Who wrote it (hr_accounts.id)',
  `body`       TEXT NOT NULL,
  `internal`   TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'A note between admins, never shown to staff',
  `request_id` BIGINT UNSIGNED NULL COMMENT 'The request this message filed or decided',
  `created_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  KEY `ix_hr_messages_thread` (`thread_id`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Attachments: encrypted like documents, stored outside the web root.
-- Uploaded first (message_id NULL), then attached when the message is sent.
CREATE TABLE IF NOT EXISTS `hr_mail_files` (
  `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `message_id`  BIGINT UNSIGNED NULL,
  `thread_id`   BIGINT UNSIGNED NULL,
  `uploaded_by` INT UNSIGNED NOT NULL COMMENT 'hr_accounts.id',
  `file_name`   VARCHAR(160) NOT NULL,
  `mime`        VARCHAR(60)  NOT NULL,
  `size`        INT UNSIGNED NOT NULL,
  `storage_key` CHAR(40)     NOT NULL,
  `sha256`      CHAR(64)     NOT NULL,
  `created_at`  DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_hr_mail_files_key` (`storage_key`),
  KEY `ix_hr_mail_files_message` (`message_id`),
  KEY `ix_hr_mail_files_owner` (`uploaded_by`, `message_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Announcements: one message to many (everyone, a centre or chosen people).
CREATE TABLE IF NOT EXISTS `hr_announcements` (
  `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `title`       VARCHAR(160) NOT NULL,
  `body`        TEXT NOT NULL,
  `audience`    JSON NOT NULL COMMENT '{"all":true} or {"centres":[..]} or {"people":[ids]}',
  `pinned`      TINYINT(1) NOT NULL DEFAULT 0,
  `expires_on`  DATE NULL,
  `created_by`  INT UNSIGNED NOT NULL COMMENT 'hr_accounts.id',
  `created_at`  DATETIME NOT NULL,
  `withdrawn_at` DATETIME NULL,
  PRIMARY KEY (`id`),
  KEY `ix_hr_announcements_live` (`withdrawn_at`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Who it went to, and when they read it (read receipts).
CREATE TABLE IF NOT EXISTS `hr_announcement_reads` (
  `announcement_id` BIGINT UNSIGNED NOT NULL,
  `employee_id`     INT UNSIGNED NOT NULL,
  `read_at`         DATETIME NULL,
  PRIMARY KEY (`announcement_id`, `employee_id`),
  KEY `ix_hr_announcement_reads_employee` (`employee_id`, `read_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- One-time sign-in links from the CRM ("HR" button). Each link's id is stored
-- once used, so a link can never be used twice.
CREATE TABLE IF NOT EXISTS `hr_handoffs` (
  `nonce`       CHAR(32) NOT NULL,
  `crm_user_id` INT NOT NULL,
  `used_at`     DATETIME NOT NULL,
  PRIMARY KEY (`nonce`),
  KEY `ix_hr_handoffs_used` (`used_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
