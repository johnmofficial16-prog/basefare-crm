-- =============================================================================
-- CRM Messages — staff writing to each other inside the CRM (22 Oct 2026)
-- =============================================================================
-- Everyone with a CRM login can message colleagues who also have one, picked
-- from a list (never a typed address). Messages stay inside the CRM: no email,
-- nothing sent outside. Every message is kept (no edit, no delete). CRM admins
-- can read every conversation, read-only, and each time they open one it is
-- written to activity_log ('message_conversation_viewed'). Trio HR's admins see
-- the same conversations (Trio Mail → Staff conversations).
--
-- Replaces the short-lived Trio HR colleague mail (hr_chat_*), which needed an
-- HR sign-in; those tables are left in place, unused.
-- =============================================================================

CREATE TABLE IF NOT EXISTS `chat_threads` (
  `id`              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `subject`         VARCHAR(160)    NOT NULL,
  `created_by`      INT             NOT NULL COMMENT 'users.id who started it',
  `last_at`         DATETIME        NOT NULL,
  `last_preview`    VARCHAR(160)    NOT NULL DEFAULT '',
  `last_message_id` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `last_sender`     INT             NULL COMMENT 'users.id',
  `created_at`      DATETIME        NOT NULL,
  `updated_at`      DATETIME        NOT NULL,
  PRIMARY KEY (`id`),
  KEY `ix_chat_threads_last` (`last_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `chat_members` (
  `thread_id` BIGINT UNSIGNED NOT NULL,
  `user_id`   INT             NOT NULL COMMENT 'users.id',
  `read_upto` BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Newest message they have seen',
  `added_at`  DATETIME        NOT NULL,
  PRIMARY KEY (`thread_id`, `user_id`),
  KEY `ix_chat_members_user` (`user_id`, `thread_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `chat_messages` (
  `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `thread_id`  BIGINT UNSIGNED NOT NULL,
  `user_id`    INT             NOT NULL COMMENT 'Who wrote it (users.id)',
  `body`       TEXT            NOT NULL,
  `ip`         VARCHAR(45)     NULL,
  `created_at` DATETIME        NOT NULL,
  PRIMARY KEY (`id`),
  KEY `ix_chat_messages_thread` (`thread_id`, `id`),
  KEY `ix_chat_messages_author` (`user_id`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
