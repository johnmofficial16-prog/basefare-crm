-- =============================================================================
-- E-Ticket Module — Database Migration
-- Base Fare CRM
-- =============================================================================
-- Approval gate (enforced in PHP, NOT SQL):
--   Transaction.status = 'approved' AND gateway_status = 'charge_successful'
-- Public URL: https://base-fare.com/eticket?token={token}
-- No token expiry — link is valid indefinitely once issued.
-- =============================================================================

CREATE TABLE IF NOT EXISTS `etickets` (

    -- -------------------------------------------------------------------------
    -- Core Identifiers
    -- -------------------------------------------------------------------------
    `id`                INT UNSIGNED        NOT NULL AUTO_INCREMENT,
    `token`             VARCHAR(64)         NOT NULL,   -- bin2hex(random_bytes(32)) — public URL token
    `transaction_id`    BIGINT UNSIGNED     NOT NULL,   -- FK → transactions.id (required, 1:1)
    `acceptance_id`     INT UNSIGNED        NULL,       -- FK → acceptance_requests.id (convenience link)
    `agent_id`          INT                 NOT NULL,   -- FK → users.id (creator)

    -- -------------------------------------------------------------------------
    -- Customer Info (denormalized for fast display without joins)
    -- -------------------------------------------------------------------------
    `customer_name`     VARCHAR(255)        NOT NULL,
    `customer_email`    VARCHAR(255)        NOT NULL,   -- primary delivery address
    `customer_phone`    VARCHAR(30)         NULL,

    -- -------------------------------------------------------------------------
    -- Booking Reference
    -- -------------------------------------------------------------------------
    `pnr`               VARCHAR(20)         NOT NULL,
    `airline`           VARCHAR(100)        NULL,
    `order_id`          VARCHAR(100)        NULL,

    -- -------------------------------------------------------------------------
    -- Per-Passenger Ticket Data (JSON array)
    -- [{pax_name, pax_type, ticket_number, seat, dob}]
    -- ticket_number: airline-issued e-ticket number (e.g. 0141234567890)
    --   — mandatory; agent must enter, or auto-generated as BF-{txn_id}-{pax_index}
    -- -------------------------------------------------------------------------
    `ticket_data`       JSON                NOT NULL,

    -- -------------------------------------------------------------------------
    -- Flight Itinerary (copied from acceptance/transaction at creation time)
    -- Same structure as acceptance_requests.flight_data
    -- -------------------------------------------------------------------------
    `flight_data`       JSON                NULL,

    -- -------------------------------------------------------------------------
    -- Fare & Financials
    -- -------------------------------------------------------------------------
    `fare_breakdown`    JSON                NULL,       -- [{label, amount}]
    `total_amount`      DECIMAL(10,2)       NOT NULL,
    `currency`          VARCHAR(3)          NOT NULL DEFAULT 'USD',

    -- -------------------------------------------------------------------------
    -- Ticket Conditions (copied & editable)
    -- -------------------------------------------------------------------------
    `endorsements`      TEXT                NULL,       -- e.g. "NON END/NON REF"
    `baggage_info`      TEXT                NULL,
    `fare_rules`        TEXT                NULL,

    -- -------------------------------------------------------------------------
    -- Policy / T&C (shown to customer)
    -- -------------------------------------------------------------------------
    `policy_text`       TEXT                NULL,

    -- -------------------------------------------------------------------------
    -- Type-Specific Extra Data (carried over from acceptance)
    -- -------------------------------------------------------------------------
    `extra_data`        JSON                NULL,

    -- -------------------------------------------------------------------------
    -- Agent Notes (internal only)
    -- -------------------------------------------------------------------------
    `agent_notes`       TEXT                NULL,

    -- -------------------------------------------------------------------------
    -- E-Ticket Lifecycle Status
    -- draft       — created, not yet sent
    -- sent        — email delivered to customer
    -- acknowledged — customer clicked "I have read"
    -- -------------------------------------------------------------------------
    `status`            ENUM('draft','sent','acknowledged') NOT NULL DEFAULT 'draft',

    -- -------------------------------------------------------------------------
    -- Email Tracking (outbound to customer)
    -- -------------------------------------------------------------------------
    `email_status`      ENUM('PENDING','SENT','FAILED','RESENT') NOT NULL DEFAULT 'PENDING',
    `email_attempts`    INT                 NOT NULL DEFAULT 0,
    `last_emailed_at`   DATETIME            NULL,
    `sent_to_email`     VARCHAR(255)        NULL,   -- actual email address last sent to (may differ from customer_email on resend)

    -- -------------------------------------------------------------------------
    -- Acknowledgment Tracking (customer "I have read" click)
    -- -------------------------------------------------------------------------
    `acknowledged_at`   DATETIME            NULL,
    `acknowledged_ip`   VARCHAR(45)         NULL,
    `acknowledged_ua`   TEXT                NULL,

    -- -------------------------------------------------------------------------
    -- Timestamps
    -- -------------------------------------------------------------------------
    `created_at`        TIMESTAMP           NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`        TIMESTAMP           NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    -- -------------------------------------------------------------------------
    -- Keys & Indexes
    -- -------------------------------------------------------------------------
    PRIMARY KEY (`id`),
    UNIQUE KEY  `uq_token`          (`token`),
    UNIQUE KEY  `uq_transaction`    (`transaction_id`),   -- enforce 1:1 with transaction
    INDEX       `idx_status`        (`status`),
    INDEX       `idx_pnr`           (`pnr`),
    INDEX       `idx_agent`         (`agent_id`),
    INDEX       `idx_acceptance`    (`acceptance_id`),
    INDEX       `idx_customer_email`(`customer_email`),
    INDEX       `idx_created`       (`created_at`),

    CONSTRAINT `fk_eticket_transaction`
        FOREIGN KEY (`transaction_id`) REFERENCES `transactions` (`id`) ON DELETE RESTRICT,

    CONSTRAINT `fk_eticket_acceptance`
        FOREIGN KEY (`acceptance_id`) REFERENCES `acceptance_requests` (`id`) ON DELETE SET NULL,

    CONSTRAINT `fk_eticket_agent`
        FOREIGN KEY (`agent_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='E-Ticket records. 1:1 with transactions. Token-based public acknowledgment.';

-- =============================================================================
-- End of etickets.sql
-- Run ONCE per environment after deployment.
-- =============================================================================
