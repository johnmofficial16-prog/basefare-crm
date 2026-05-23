-- =============================================================================
-- Migration: E-Ticket Acknowledgment Replies
-- Base Fare CRM — 2026-05-23
-- =============================================================================
-- Run ONCE per environment after deployment.
-- All changes are purely additive — zero impact on existing data.
-- =============================================================================

-- -----------------------------------------------------------------------------
-- 1. Add ack_type column to etickets table
--    NULL default so existing acknowledged rows aren't forced to a type
--    Backfill below sets existing acknowledged rows to 'button'
-- -----------------------------------------------------------------------------
ALTER TABLE `etickets`
    ADD COLUMN `ack_type` ENUM('button', 'web_contact', 'email_reply')
        NULL DEFAULT NULL
        COMMENT 'How customer acknowledged: button=existing flow, web_contact=contact form, email_reply=parsed email'
    AFTER `acknowledged_ua`;

-- Backfill: all existing acknowledged tickets were done via the button
UPDATE `etickets`
    SET `ack_type` = 'button'
    WHERE `status` = 'acknowledged'
      AND `ack_type` IS NULL;

-- -----------------------------------------------------------------------------
-- 2. Create eticket_replies table (one-to-many with etickets)
--    Stores every customer message — web form or email reply.
--    Multiple rows per e-ticket is expected and fully supported.
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `eticket_replies` (
    `id`            BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
    `eticket_id`    INT UNSIGNED     NOT NULL,
    `source`        ENUM('web_contact', 'email_reply') NOT NULL
                    COMMENT 'Origin of this reply',
    `subject`       VARCHAR(500)     NULL
                    COMMENT 'Email subject line — populated for email_reply source only',
    `body`          TEXT             NOT NULL
                    COMMENT 'Message content from the customer',
    `sender_email`  VARCHAR(255)     NULL
                    COMMENT 'Email address the reply came from (email_reply) or customer_email (web_contact)',
    `sender_ip`     VARCHAR(45)      NULL
                    COMMENT 'Client IP — populated for web_contact source only',
    `sender_ua`     TEXT             NULL
                    COMMENT 'User agent string — populated for web_contact source only',
    `raw_headers`   TEXT             NULL
                    COMMENT 'Raw email headers for audit trail — populated for email_reply source only',
    `created_at`    DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY  (`id`),
    INDEX `idx_reply_eticket`  (`eticket_id`),
    INDEX `idx_reply_source`   (`source`),
    INDEX `idx_reply_created`  (`created_at`),

    CONSTRAINT `fk_reply_eticket`
        FOREIGN KEY (`eticket_id`) REFERENCES `etickets` (`id`) ON DELETE CASCADE

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Customer reply messages per e-ticket. One-to-many. Multiple replies per ticket supported.';

-- -----------------------------------------------------------------------------
-- 3. Expand record_notes entity_type ENUM to include 'eticket'
--    The application code already writes entity_type='eticket' but the
--    original schema ENUM only had 'acceptance' and 'transaction'.
--    This fixes that gap without touching any existing rows.
-- -----------------------------------------------------------------------------
ALTER TABLE `record_notes`
    MODIFY COLUMN `entity_type` ENUM('acceptance', 'transaction', 'eticket') NOT NULL
    COMMENT 'Which record this note belongs to';

-- =============================================================================
-- End of migration.
-- =============================================================================
