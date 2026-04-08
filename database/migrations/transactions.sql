-- =============================================================================
-- Transaction Recorder — Database Migration
-- Base Fare CRM — Phase 4
-- Created: 2026-04-07
--
-- Tables:
--   1. transactions           — Core transaction records (8 types)
--   2. transaction_passengers — Normalized passenger list per transaction
--   3. payment_cards          — AES-256-GCM encrypted card storage
--
-- Safe to re-run: all statements use CREATE TABLE IF NOT EXISTS
-- =============================================================================

SET FOREIGN_KEY_CHECKS = 0;

-- -----------------------------------------------------------------------------
-- 1. transactions
-- -----------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS `transactions` (
  `id`                    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,

  -- Agent & Acceptance links
  `agent_id`              INT NOT NULL,
  `acceptance_id`         INT UNSIGNED NULL          COMMENT 'FK to acceptance_requests if imported',

  -- Transaction classification
  `type`                  ENUM(
                            'new_booking',
                            'exchange',
                            'seat_purchase',
                            'cabin_upgrade',
                            'cancel_refund',
                            'cancel_credit',
                            'name_correction',
                            'other'
                          ) NOT NULL,

  -- Customer details (duplicated here for fast queries without JSON parsing)
  `customer_name`         VARCHAR(255) NOT NULL,
  `customer_email`        VARCHAR(255) NOT NULL,
  `customer_phone`        VARCHAR(30)  NULL,

  -- PNR stored directly for indexing; also in JSON data for full context
  `pnr`                   VARCHAR(20)  NOT NULL,
  `airline`               VARCHAR(100) NULL,
  `order_id`              VARCHAR(100) NULL,

  -- Travel dates (used for check-in notification system later)
  `travel_date`           DATE         NULL          COMMENT 'Primary departure date — check-in notification anchor',
  `departure_time`        TIME         NULL          COMMENT 'For accurate 24hr notification calculation',
  `return_date`           DATE         NULL,

  -- Financials
  `total_amount`          DECIMAL(10,2) NOT NULL     COMMENT 'Amount charged to customer',
  `cost_amount`           DECIMAL(10,2) NOT NULL DEFAULT 0.00 COMMENT 'Agency cost (net from airline)',
  `profit_mco`            DECIMAL(10,2) NOT NULL DEFAULT 0.00 COMMENT 'Profit / MCO — manual entry',
  `currency`              VARCHAR(3)   NOT NULL DEFAULT 'USD',

  -- Payment
  `payment_method`        ENUM(
                            'credit_card',
                            'debit_card',
                            'bank_transfer',
                            'cash',
                            'credit_shell',
                            'cheque',
                            'other'
                          ) NOT NULL DEFAULT 'credit_card',
  `payment_status`        ENUM(
                            'pending',
                            'paid',
                            'partial',
                            'refunded',
                            'credited'
                          ) NOT NULL DEFAULT 'pending',

  -- Type-specific data stored as JSON
  -- Structure varies by type:
  --   new_booking:    { pnr, airline, route, fare_breakdown[], old_tickets[] }
  --   exchange:       { old_pnr, new_pnr, fare_diff, penalty, new_flights[] }
  --   seat_purchase:  { seats[], per_seat_cost }
  --   cabin_upgrade:  { old_cabin, new_cabin, upgrade_cost }
  --   cancel_refund:  { cancel_fee, refund_amount, refund_method }
  --   cancel_credit:  { credit_amount, credit_expiry, airline_credit_code }
  --   name_correction:{ old_name, new_name, correction_fee }
  --   other:          { category, description }
  `data`                  JSON         NULL,

  -- Immutability & Status
  `status`                ENUM(
                            'pending_review',
                            'approved',
                            'voided'
                          ) NOT NULL DEFAULT 'pending_review',
  `void_reason`           TEXT         NULL,
  `voided_at`             DATETIME     NULL,
  `voided_by`             INT          NULL          COMMENT 'FK to users.id — admin who voided',
  `void_of_transaction_id` BIGINT UNSIGNED NULL      COMMENT 'If this is a reversal, points to original transaction id',

  -- Check-in notifications (Phase 6 — not active yet)
  `checkin_notified`      TINYINT(1)   NOT NULL DEFAULT 0,
  `checkin_completed`     TINYINT(1)   NOT NULL DEFAULT 0,

  -- Notes & Audit
  `agent_notes`           TEXT         NULL,
  `created_at`            DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`            DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  PRIMARY KEY (`id`),

  -- Indexes for common queries
  KEY `idx_agent_id`       (`agent_id`),
  KEY `idx_acceptance_id`  (`acceptance_id`),
  KEY `idx_pnr`            (`pnr`),
  KEY `idx_type`           (`type`),
  KEY `idx_status`         (`status`),
  KEY `idx_customer_email` (`customer_email`),
  KEY `idx_travel_date`    (`travel_date`),
  KEY `idx_created_at`     (`created_at`),
  KEY `idx_void_of`        (`void_of_transaction_id`),

  CONSTRAINT `fk_txn_agent`
    FOREIGN KEY (`agent_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT,

  CONSTRAINT `fk_txn_acceptance`
    FOREIGN KEY (`acceptance_id`) REFERENCES `acceptance_requests` (`id`) ON DELETE SET NULL

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Core transaction records for all 8 transaction types';


-- -----------------------------------------------------------------------------
-- 2. transaction_passengers
-- One row per passenger per transaction
-- -----------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS `transaction_passengers` (
  `id`              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `transaction_id`  BIGINT UNSIGNED NOT NULL,
  `first_name`      VARCHAR(100)    NOT NULL,
  `last_name`       VARCHAR(100)    NOT NULL,
  `dob`             DATE            NULL,
  `pax_type`        ENUM('adult','child','infant') NOT NULL DEFAULT 'adult',
  `ticket_number`   VARCHAR(25)     NULL          COMMENT 'Issued ticket number (e.g. 0011234567890)',
  `frequent_flyer`  VARCHAR(30)     NULL          COMMENT 'FFN if applicable',
  `created_at`      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,

  PRIMARY KEY (`id`),
  KEY `idx_pax_transaction` (`transaction_id`),

  CONSTRAINT `fk_pax_transaction`
    FOREIGN KEY (`transaction_id`) REFERENCES `transactions` (`id`) ON DELETE CASCADE

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Passenger list per transaction — one row per pax';


-- -----------------------------------------------------------------------------
-- 3. payment_cards
-- AES-256-GCM encrypted card storage.
-- card_number_enc and cvv_enc are encrypted by EncryptionService.php.
-- Agents see card_last_4 only. Admin reveal is password-gated and logged.
-- -----------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS `payment_cards` (
  `id`                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `transaction_id`    BIGINT UNSIGNED NOT NULL,

  -- Card identification (unencrypted — safe for display)
  `card_type`         VARCHAR(20)     NOT NULL  COMMENT 'Visa, Mastercard, Amex, Discover, etc.',
  `card_last_4`       CHAR(4)         NOT NULL,
  `holder_name`       VARCHAR(255)    NOT NULL,
  `expiry`            VARCHAR(7)      NOT NULL  COMMENT 'MM/YYYY format',
  `billing_address`   TEXT            NULL,

  -- Encrypted fields (AES-256-GCM via EncryptionService)
  -- Format: base64(iv . tag . ciphertext)
  `card_number_enc`   TEXT            NULL      COMMENT 'Full PAN — encrypted. Agent-entry only, never shown to agents.',
  `cvv_enc`           TEXT            NULL      COMMENT 'CVV — encrypted. Admin reveal only.',

  -- Amount charged to this specific card (for split-charge scenarios)
  `amount`            DECIMAL(10,2)   NOT NULL DEFAULT 0.00,
  `is_primary`        TINYINT(1)      NOT NULL DEFAULT 1  COMMENT '1 = primary card, 0 = additional split card',

  -- Audit trail for reveals
  `last_revealed_by`  INT             NULL      COMMENT 'user_id of last admin who decrypted',
  `last_revealed_at`  DATETIME        NULL,
  `reveal_count`      SMALLINT        NOT NULL DEFAULT 0,

  `created_at`        DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,

  PRIMARY KEY (`id`),
  KEY `idx_card_transaction`  (`transaction_id`),
  KEY `idx_card_last_4`       (`card_last_4`),

  CONSTRAINT `fk_card_transaction`
    FOREIGN KEY (`transaction_id`) REFERENCES `transactions` (`id`) ON DELETE CASCADE

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Encrypted payment card data. card_number_enc and cvv_enc use AES-256-GCM.';


SET FOREIGN_KEY_CHECKS = 1;

-- =============================================================================
-- End of transactions.sql
-- Run this ONCE on each environment after deployment.
-- =============================================================================
