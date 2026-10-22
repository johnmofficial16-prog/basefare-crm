-- =============================================================================
-- CRM Messages — files on messages (25 Oct 2026)
-- =============================================================================
-- Staff can attach files (PDF, images, Word, Excel, CSV, TXT) to a message.
-- The files live in storage/chat/YYYY/MM/ (never web-reachable) and are only
-- served through /messages/file/{id}, to the people in that conversation and to
-- admins (an admin opening a file they are not part of is logged).
-- =============================================================================

CREATE TABLE IF NOT EXISTS `chat_attachments` (
  `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `message_id`    BIGINT UNSIGNED NOT NULL,
  `thread_id`     BIGINT UNSIGNED NOT NULL,
  `user_id`       INT             NOT NULL COMMENT 'Who attached it (users.id)',
  `original_name` VARCHAR(160)    NOT NULL,
  `stored_path`   VARCHAR(255)    NOT NULL,
  `mime_type`     VARCHAR(100)    NOT NULL,
  `size_bytes`    INT UNSIGNED    NOT NULL,
  `created_at`    DATETIME        NOT NULL,
  PRIMARY KEY (`id`),
  KEY `ix_chat_attachments_message` (`message_id`),
  KEY `ix_chat_attachments_thread` (`thread_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
