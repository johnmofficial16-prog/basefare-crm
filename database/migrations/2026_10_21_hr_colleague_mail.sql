-- =============================================================================
-- Trio HR — Trio Mail between colleagues (21 Oct 2026)
-- =============================================================================
-- Staff can write to each other inside Trio HR, but only to colleagues who
-- have an active CRM login (picked from a list, never typed). Nothing is
-- sent outside Trio HR: no email, no outside addresses. Every message is kept
-- (nobody can delete one) and HR admins can read every conversation; each
-- time an admin opens one it is written to the audit log.
--
-- Only new tables, plus two columns on hr_mail_files so attachments are
-- shared with Trio Mail's encrypted file store.
-- =============================================================================

-- One conversation between two or more colleagues.
CREATE TABLE IF NOT EXISTS `hr_chat_threads` (
  `id`              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `subject`         VARCHAR(160) NOT NULL,
  `created_by`      INT UNSIGNED NOT NULL COMMENT 'hr_employees.id of who started it',
  `last_at`         DATETIME     NOT NULL,
  `last_preview`    VARCHAR(160) NOT NULL DEFAULT '',
  `last_message_id` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `last_sender`     INT UNSIGNED NULL COMMENT 'hr_employees.id',
  `created_at`      DATETIME     NOT NULL,
  `updated_at`      DATETIME     NOT NULL,
  PRIMARY KEY (`id`),
  KEY `ix_hr_chat_threads_last` (`last_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Who is in each conversation, and how far they have read.
CREATE TABLE IF NOT EXISTS `hr_chat_members` (
  `thread_id`   BIGINT UNSIGNED NOT NULL,
  `employee_id` INT UNSIGNED    NOT NULL,
  `read_upto`   BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Newest message they have seen',
  `added_at`    DATETIME        NOT NULL,
  PRIMARY KEY (`thread_id`, `employee_id`),
  KEY `ix_hr_chat_members_employee` (`employee_id`, `thread_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Every message, kept for good (there is no delete).
CREATE TABLE IF NOT EXISTS `hr_chat_messages` (
  `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `thread_id`   BIGINT UNSIGNED NOT NULL,
  `employee_id` INT UNSIGNED    NOT NULL COMMENT 'Who wrote it',
  `account_id`  INT UNSIGNED    NOT NULL COMMENT 'The Trio HR sign-in it came from',
  `body`        TEXT            NOT NULL,
  `ip`          VARCHAR(45)     NULL,
  `created_at`  DATETIME        NOT NULL,
  PRIMARY KEY (`id`),
  KEY `ix_hr_chat_messages_thread` (`thread_id`, `id`),
  KEY `ix_hr_chat_messages_author` (`employee_id`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Attachments on colleague messages use Trio Mail's encrypted file store.
ALTER TABLE `hr_mail_files`
  ADD COLUMN `chat_message_id` BIGINT UNSIGNED NULL DEFAULT NULL AFTER `thread_id`,
  ADD COLUMN `chat_thread_id`  BIGINT UNSIGNED NULL DEFAULT NULL AFTER `chat_message_id`,
  ADD KEY `ix_hr_mail_files_chat` (`chat_message_id`);
