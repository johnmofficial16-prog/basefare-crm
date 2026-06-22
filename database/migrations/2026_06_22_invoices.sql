-- =============================================================================
-- Migration: Invoice Generator Module
-- Base Fare CRM — 2026-06-22
-- =============================================================================
-- Run ONCE per environment after deployment (registered in hostinger_migrate.php).
-- All changes are additive — zero impact on existing data.
--
-- An invoice is a customer-facing charge document (itinerary + charge breakdown)
-- generated in the CRM, optionally grounded on an existing booking.
--
-- Lifecycle (enforced in PHP, not SQL):
--   pending_approval → approved → sent      (or → rejected)
--   Agents/supervisors create in 'pending_approval'.
--   Managers/admins create as 'approved' and may send immediately.
-- =============================================================================

CREATE TABLE IF NOT EXISTS `invoices` (
    `id`               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `invoice_no`       VARCHAR(50)     NOT NULL  COMMENT 'Human invoice number, e.g. INV-A1B2C3',
    `agent_id`         INT             NOT NULL  COMMENT 'FK → users.id (owner / creator)',
    `transaction_id`   BIGINT UNSIGNED NULL      COMMENT 'FK → transactions.id (optional prefill source)',

    -- Purpose of charge
    `purpose`          ENUM('new_booking','changes','seat_type','other')
                       NOT NULL DEFAULT 'new_booking',
    `purpose_other`    VARCHAR(255)    NULL       COMMENT 'Free text when purpose = other',

    -- Customer / passenger
    `customer_name`    VARCHAR(255)    NOT NULL   COMMENT 'Passenger name(s)',
    `customer_email`   VARCHAR(255)    NULL,
    `customer_phone`   VARCHAR(50)     NULL,
    `pnr`              VARCHAR(50)      NULL,
    `airline`          VARCHAR(120)    NULL,

    -- Card (NON-sensitive fields only — never the PAN/CVV)
    `cardholder_name`  VARCHAR(255)    NULL       COMMENT 'CCH — cardholder name',
    `card_billing`     TEXT            NULL       COMMENT 'CC billing address',

    -- Itinerary: JSON array of flight segments {from,to,flight,date,dep,arr,cabin}
    `itinerary`        JSON            NULL,

    -- Charges
    `airline_charge`   DECIMAL(10,2)   NULL       COMMENT 'Optional — omitted on the invoice when NULL',
    `base_fare_charge` DECIMAL(10,2)   NOT NULL DEFAULT 0.00 COMMENT 'Base Fare service charge',
    `total_amount`     DECIMAL(10,2)   NOT NULL DEFAULT 0.00 COMMENT 'Server-computed: airline + base fare',
    `currency`         VARCHAR(10)     NOT NULL DEFAULT 'USD',

    `issue_date`       DATE            NOT NULL,
    `notes`            TEXT            NULL       COMMENT 'Internal note (not shown to customer)',

    -- Approval / delivery state machine
    `status`           ENUM('pending_approval','approved','sent','rejected')
                       NOT NULL DEFAULT 'pending_approval',
    `rejected_reason`  VARCHAR(500)    NULL,
    `approved_by`      INT             NULL       COMMENT 'FK → users.id (manager/admin)',
    `approved_at`      DATETIME        NULL,
    `sent_at`          DATETIME        NULL,
    `sent_to`          VARCHAR(255)    NULL,
    `send_error`       TEXT            NULL       COMMENT 'Failure reason when a send fails',

    `created_by`       INT             NOT NULL   COMMENT 'FK → users.id',
    `created_at`       TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`       TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (`id`),
    UNIQUE KEY `idx_invoice_no`     (`invoice_no`),
    INDEX `idx_inv_agent`           (`agent_id`),
    INDEX `idx_inv_transaction`     (`transaction_id`),
    INDEX `idx_inv_status`          (`status`),
    INDEX `idx_inv_created_by`      (`created_by`),
    INDEX `idx_inv_customer`        (`customer_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Customer invoices — itinerary + charge breakdown with manager approval.';

-- -----------------------------------------------------------------------------
-- Expand record_notes entity_type ENUM to include 'invoice' for the audit trail.
-- (Keeps every prior value; purely additive.)
-- -----------------------------------------------------------------------------
ALTER TABLE `record_notes`
    MODIFY COLUMN `entity_type` ENUM('acceptance', 'transaction', 'eticket', 'customer_email', 'invoice') NOT NULL
    COMMENT 'Which record this note belongs to';

-- =============================================================================
-- End of migration.
-- =============================================================================
