-- =============================================================================
-- Migration: Customer Emails (AI-drafted, two-way) Module
-- Base Fare CRM — 2026-06-10
-- =============================================================================
-- Run ONCE per environment after deployment (registered in hostinger_migrate.php).
-- All changes are additive — zero impact on existing data.
--
-- Model: a THREAD holds one customer conversation; MESSAGES are the individual
-- emails (outbound, AI-drafted + manager-approved; or inbound, pulled via IMAP).
--
-- Outbound lifecycle (enforced in PHP, not SQL):
--   draft → pending_approval → approved → sent   (or → rejected / → failed)
--   Managers/admins who compose their own bypass approval (auto-approved).
-- Inbound messages are stored with status = 'received'.
-- =============================================================================

-- -----------------------------------------------------------------------------
-- 1. Threads — one row per customer conversation
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `customer_email_threads` (
    `id`               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `agent_id`         INT             NOT NULL  COMMENT 'FK → users.id (owner/creator)',
    `transaction_id`   BIGINT UNSIGNED NULL      COMMENT 'FK → transactions.id (optional grounding link)',
    `customer_name`    VARCHAR(255)    NOT NULL,
    `customer_email`   VARCHAR(255)    NOT NULL,
    `subject`          VARCHAR(500)    NULL       COMMENT 'Conversation subject (first message subject)',
    `status`           ENUM('open','awaiting_customer','awaiting_agent','closed')
                       NOT NULL DEFAULT 'open',
    `last_message_at`  DATETIME        NULL       COMMENT 'Timestamp of most recent message (any direction)',
    `created_at`       DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`       DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (`id`),
    INDEX `idx_cet_agent`        (`agent_id`),
    INDEX `idx_cet_transaction`  (`transaction_id`),
    INDEX `idx_cet_status`       (`status`),
    INDEX `idx_cet_last_message` (`last_message_at`),
    INDEX `idx_cet_customer`     (`customer_email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Customer email conversations. One thread per customer dialogue.';

-- -----------------------------------------------------------------------------
-- 2. Messages — individual emails within a thread (outbound or inbound)
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `customer_email_messages` (
    `id`               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `thread_id`        BIGINT UNSIGNED NOT NULL  COMMENT 'FK → customer_email_threads.id',
    `direction`        ENUM('outbound','inbound') NOT NULL,
    `status`           ENUM('draft','pending_approval','approved','sent','failed','rejected','received')
                       NOT NULL,

    -- Audit of the AI step (outbound only) — store intent + RAW model output
    -- separately from the FINAL sent content so the full chain is provable.
    `category`         VARCHAR(40)     NULL       COMMENT 'Email category steer (follow_up, payment_reminder, …)',
    `intent_prompt`    TEXT            NULL       COMMENT 'What the agent typed (the request to the AI)',
    `ai_model`         VARCHAR(60)     NULL       COMMENT 'Model that produced the draft',
    `ai_subject`       VARCHAR(500)    NULL       COMMENT 'Raw AI-suggested subject, before agent edits',
    `ai_body`          MEDIUMTEXT      NULL       COMMENT 'Raw AI-suggested body, before agent edits',

    -- The actual content. For inbound, final_body holds the received message.
    `final_subject`    VARCHAR(500)    NULL,
    `final_body`       MEDIUMTEXT      NOT NULL,

    -- People + approval
    `created_by`       INT             NULL       COMMENT 'FK → users.id (drafting agent; NULL for inbound)',
    `approved_by`      INT             NULL       COMMENT 'FK → users.id (manager/admin who approved)',
    `approved_at`      DATETIME        NULL,
    `rejected_reason`  VARCHAR(500)    NULL,

    -- Email threading / delivery
    `message_id`       VARCHAR(255)    NULL       COMMENT 'RFC 5322 Message-ID we set on send, or inbound Message-ID',
    `in_reply_to`      VARCHAR(255)    NULL       COMMENT 'Parent Message-ID this message replies to',
    `sender_email`     VARCHAR(255)    NULL       COMMENT 'From address (inbound)',
    `raw_headers`      TEXT            NULL       COMMENT 'Raw email headers for audit (inbound)',
    `sent_at`          DATETIME        NULL,
    `sent_to`          VARCHAR(255)    NULL,
    `error`            TEXT            NULL       COMMENT 'Failure reason when status = failed',

    `created_at`       DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (`id`),
    INDEX `idx_cem_thread`     (`thread_id`),
    INDEX `idx_cem_status`     (`status`),
    INDEX `idx_cem_direction`  (`direction`),
    INDEX `idx_cem_message_id` (`message_id`),
    INDEX `idx_cem_in_reply`   (`in_reply_to`),

    CONSTRAINT `fk_cem_thread`
        FOREIGN KEY (`thread_id`) REFERENCES `customer_email_threads` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Individual emails within a customer conversation. Outbound (AI+approved) and inbound (IMAP).';

-- -----------------------------------------------------------------------------
-- 3. Expand record_notes entity_type ENUM to include 'customer_email'
--    The module writes entity_type='customer_email' for its audit trail.
-- -----------------------------------------------------------------------------
ALTER TABLE `record_notes`
    MODIFY COLUMN `entity_type` ENUM('acceptance', 'transaction', 'eticket', 'customer_email') NOT NULL
    COMMENT 'Which record this note belongs to';

-- =============================================================================
-- End of migration.
-- =============================================================================
