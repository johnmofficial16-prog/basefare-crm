-- =============================================================================
-- Migration: Add message_id to eticket_replies
-- Base Fare CRM — 2026-05-29
-- =============================================================================
-- Adds a unique message_id column to eticket_replies so the IMAP cron can
-- deduplicate based on the email Message-ID header instead of relying on
-- UNSEEN/SEEN status. This means the cron can safely search ALL recent emails
-- without re-processing ones it has already handled — even if someone reads
-- the reply in Gmail before the cron runs.
-- =============================================================================

ALTER TABLE `eticket_replies`
    ADD COLUMN `message_id` VARCHAR(500) NULL DEFAULT NULL
        COMMENT 'RFC 2822 Message-ID header value — used to deduplicate IMAP processing'
        AFTER `raw_headers`,
    ADD INDEX `idx_reply_message_id` (`message_id`(191));

-- =============================================================================
-- End of migration. Run ONCE on production.
-- =============================================================================
