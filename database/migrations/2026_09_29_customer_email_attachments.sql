-- =============================================================================
-- Customer Emails — file attachments on outbound messages
-- =============================================================================
-- Agents attach files (e-ticket PDF, invoice, screenshots…) to an AI-drafted
-- email, the way Gmail does. Files live in storage/customer_emails/YYYY/MM/
-- (storage/ is denied to the web by storage/.htaccess and the root .htaccess)
-- and are only served through /emails/attachment/{id}, which re-checks thread
-- access. For agents the files wait with the draft until a manager approves;
-- they are attached at send time by CustomerEmailService::send().
--
-- One row per file. Deleting a message (or its thread, via the message FK
-- cascade) removes the rows; the files themselves are kept as the record of
-- what the customer was sent.
--
-- Safe to re-run: CREATE TABLE IF NOT EXISTS.
-- =============================================================================

CREATE TABLE IF NOT EXISTS `customer_email_attachments` (
    `id`             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `message_id`     BIGINT UNSIGNED NOT NULL  COMMENT 'FK → customer_email_messages.id',
    `original_name`  VARCHAR(255)    NOT NULL  COMMENT 'Filename as uploaded — what the customer sees',
    `stored_path`    VARCHAR(255)    NOT NULL  COMMENT 'storage/customer_emails/YYYY/MM/<random>.<ext>',
    `mime_type`      VARCHAR(120)    NOT NULL,
    `size_bytes`     INT UNSIGNED    NOT NULL,
    `uploaded_by`    INT             NULL      COMMENT 'FK → users.id',
    `created_at`     DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (`id`),
    INDEX `idx_cea_message` (`message_id`),

    CONSTRAINT `fk_cea_message`
        FOREIGN KEY (`message_id`) REFERENCES `customer_email_messages` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Files attached to outbound customer emails.';
