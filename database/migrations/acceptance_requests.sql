-- =============================================================================
-- Acceptance Requests Table
-- Base Fare CRM — Customer Authorization & E-Signature Module
-- =============================================================================
-- Token expiry: 12 hours from created_at (enforced in AcceptanceService)
-- Public URL:   https://base-fare.com/auth?token={token}
-- =============================================================================

CREATE TABLE IF NOT EXISTS `acceptance_requests` (

    -- -------------------------------------------------------------------------
    -- Core Identifiers
    -- -------------------------------------------------------------------------
    `id`                INT UNSIGNED        NOT NULL AUTO_INCREMENT,
    `token`             VARCHAR(64)         NOT NULL,   -- bin2hex(random_bytes(32)), used in public URL
    `transaction_id`    INT UNSIGNED        NULL,       -- FK → transactions.id (set when created from Transaction Recorder)

    -- -------------------------------------------------------------------------
    -- Transaction Type & Status
    -- -------------------------------------------------------------------------
    `type`  ENUM(
                'new_booking',
                'exchange',
                'cancel_refund',
                'cancel_credit',
                'seat_purchase',
                'cabin_upgrade',
                'name_correction',
                'other'
            ) NOT NULL,

    `status` ENUM(
                 'PENDING',
                 'APPROVED',
                 'EXPIRED',
                 'CANCELLED'
             ) NOT NULL DEFAULT 'PENDING',

    -- -------------------------------------------------------------------------
    -- Customer Info
    -- -------------------------------------------------------------------------
    `customer_name`     VARCHAR(255)        NOT NULL,
    `customer_email`    VARCHAR(255)        NOT NULL,
    `customer_phone`    VARCHAR(30)         NULL,

    -- -------------------------------------------------------------------------
    -- Booking Reference
    -- -------------------------------------------------------------------------
    `pnr`               VARCHAR(20)         NOT NULL,
    `airline`           VARCHAR(100)        NULL,       -- Primary airline (e.g. "Lufthansa")
    `order_id`          VARCHAR(100)        NULL,       -- Internal order ref (optional)

    -- -------------------------------------------------------------------------
    -- Passengers
    -- JSON array of objects: [{name, dob, type}]
    -- type: "adult" | "child" | "infant"
    -- Example: [{"name":"Steven Suarez","dob":"1954-06-27","type":"adult"}]
    -- -------------------------------------------------------------------------
    `passengers`        JSON                NOT NULL,

    -- -------------------------------------------------------------------------
    -- Flight Data (flexible JSON per type)
    -- new_booking:    {flights: [{flight_no, airline, date, from, to, dep_time, arr_time, cabin, duration, transit}]}
    -- exchange:       {old_flights: [...], new_flights: [...]}
    -- cancel_*:       {flights: [...]}  (original flights, read-only reference)
    -- seat_purchase:  {flights: [...]}  (reference)
    -- cabin_upgrade:  {flights: [...]}  (reference)
    -- name_correction:{flights: [...]}  (reference)
    -- other:          null
    -- -------------------------------------------------------------------------
    `flight_data`       JSON                NULL,

    -- -------------------------------------------------------------------------
    -- Fare Breakdown
    -- JSON array of line items: [{label, amount}]
    -- Example: [{"label":"Lufthansa Airlines","amount":916.00},{"label":"Airline Tickets","amount":900.78}]
    -- -------------------------------------------------------------------------
    `fare_breakdown`    JSON                NULL,
    `total_amount`      DECIMAL(10, 2)      NOT NULL,
    `currency`          VARCHAR(3)          NOT NULL DEFAULT 'USD',
    `split_charge_note` TEXT                NULL,       -- "You will see split charges on card ending xxxx. Total will not exceed $X"

    -- -------------------------------------------------------------------------
    -- Type-Specific Extra Data (flexible JSON)
    -- cancel_refund:   {refund_amount, cancel_fee, refund_method, refund_timeline}
    -- cancel_credit:   {credit_amount, credit_expiry, credit_code, credit_instructions}
    -- seat_purchase:   [{passenger, seat_number, seat_type, cost}]
    -- cabin_upgrade:   {old_cabin, new_cabin, upgrade_cost}
    -- name_correction: {old_name, new_name, correction_fee, reason}
    -- -------------------------------------------------------------------------
    `extra_data`        JSON                NULL,

    -- -------------------------------------------------------------------------
    -- Payment Details
    -- -------------------------------------------------------------------------
    `statement_descriptor`  VARCHAR(255)    NULL,       -- e.g. "Lufthansa Airlines / Date Change Fee"
    `card_type`             VARCHAR(20)     NULL,       -- Visa, Mastercard, Amex, Discover
    `cardholder_name`       VARCHAR(255)    NULL,
    `card_last_four`        CHAR(4)         NULL,
    `billing_address`       TEXT            NULL,
    `additional_cards`      JSON            NULL,       -- [{cardholder_name, card_type, card_last_four, amount}]

    -- -------------------------------------------------------------------------
    -- Endorsements, Baggage & Fare Rules (all editable per request)
    -- -------------------------------------------------------------------------
    `endorsements`      TEXT                NULL,       -- e.g. "NON END/NON REF"
    `baggage_info`      TEXT                NULL,       -- e.g. "1 checked bag included. 1 carry-on of 15lbs + 1 personal item free."
    `fare_rules`        TEXT                NULL,       -- Full exchange/cancellation/no-show rules block

    -- -------------------------------------------------------------------------
    -- Policy / T&C (shown to customer, editable per request)
    -- -------------------------------------------------------------------------
    `policy_text`       TEXT                NULL,

    -- -------------------------------------------------------------------------
    -- Agent Controls
    -- -------------------------------------------------------------------------
    `req_passport`      TINYINT(1)          NOT NULL DEFAULT 0,   -- Require passport/ID upload?
    `req_cc_front`      TINYINT(1)          NOT NULL DEFAULT 0,   -- Require CC front upload?
    `agent_id`          INT UNSIGNED        NOT NULL,              -- FK → users.id
    `agent_notes`       TEXT                NULL,                  -- Internal only — NOT shown to customer

    -- -------------------------------------------------------------------------
    -- Token Expiry (12 hours from created_at)
    -- -------------------------------------------------------------------------
    `expires_at`        DATETIME            NOT NULL,

    -- -------------------------------------------------------------------------
    -- Customer Forensic Data (populated on signing)
    -- -------------------------------------------------------------------------
    `ip_address`            VARCHAR(45)     NULL,   -- Customer's IP at time of signing
    `device_fingerprint`    TEXT            NULL,   -- Browser fingerprint hash
    `user_agent`            TEXT            NULL,   -- Customer's browser UA string
    `viewed_at`             DATETIME        NULL,   -- First time the link was opened (even without signing)
    `approved_at`           DATETIME        NULL,   -- Timestamp of signing

    -- -------------------------------------------------------------------------
    -- Uploaded Files (filenames relative to storage/acceptance/)
    -- -------------------------------------------------------------------------
    `digital_signature`     VARCHAR(255)    NULL,   -- signatures/{token}_sig.png
    `passport_image`        VARCHAR(255)    NULL,   -- evidence/{token}_passport.{ext}
    `card_image_front`      VARCHAR(255)    NULL,   -- evidence/{token}_card.{ext}

    -- -------------------------------------------------------------------------
    -- Email Tracking
    -- -------------------------------------------------------------------------
    `email_status`      ENUM('PENDING','SENT','FAILED','RESENT') NOT NULL DEFAULT 'PENDING',
    `email_attempts`    INT                 NOT NULL DEFAULT 0,
    `last_emailed_at`   DATETIME            NULL,

    -- -------------------------------------------------------------------------
    -- Timestamps
    -- -------------------------------------------------------------------------
    `created_at`    TIMESTAMP   NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`    TIMESTAMP   NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    -- -------------------------------------------------------------------------
    -- Keys & Indexes
    -- -------------------------------------------------------------------------
    PRIMARY KEY (`id`),
    UNIQUE KEY  `uq_token`          (`token`),
    INDEX       `idx_status`        (`status`),
    INDEX       `idx_pnr`           (`pnr`),
    INDEX       `idx_agent`         (`agent_id`),
    INDEX       `idx_transaction`   (`transaction_id`),
    INDEX       `idx_customer_email`(`customer_email`),
    INDEX       `idx_expires`       (`expires_at`),
    INDEX       `idx_created`       (`created_at`)

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
