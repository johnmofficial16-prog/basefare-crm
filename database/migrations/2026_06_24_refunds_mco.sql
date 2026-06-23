-- =============================================================================
-- Migration: Refunds + Net MCO
-- Base Fare CRM — 2026-06-24
-- =============================================================================
-- Run ONCE per environment (registered in hostinger_migrate.php). Additive only.
--
-- Adds an admin "Refund" action alongside Void. A refund KEEPS the original sale
-- (status stays 'approved') and is shown as a merchant-statement-style
-- "Refund −$X" line. It reduces the booking's NET MCO:
--   * full refund    → Net MCO = 0
--   * partial refund → Net MCO = Gross MCO − refunded amount  (floored at 0)
-- The reduction is stored once as `refund_mco_impact` so every report can do a
-- single subtraction (Net = profit_mco − refund_mco_impact).
-- =============================================================================

ALTER TABLE `transactions`
    ADD COLUMN `refund_status`     ENUM('none','partial','full') NOT NULL DEFAULT 'none'
        COMMENT 'Refund state of this booking' AFTER `payment_status`,
    ADD COLUMN `refunded_amount`   DECIMAL(10,2) NOT NULL DEFAULT 0.00
        COMMENT 'Cumulative amount refunded to the customer' AFTER `refund_status`,
    ADD COLUMN `refund_mco_impact` DECIMAL(10,2) NOT NULL DEFAULT 0.00
        COMMENT 'Profit/MCO removed by refunds (Net MCO = profit_mco - this)' AFTER `refunded_amount`,
    ADD COLUMN `refund_reason`     VARCHAR(500) NULL
        COMMENT 'Reason for the most recent refund' AFTER `refund_mco_impact`,
    ADD COLUMN `refunded_at`       DATETIME NULL AFTER `refund_reason`,
    ADD COLUMN `refunded_by`       INT NULL COMMENT 'FK → users.id (admin)' AFTER `refunded_at`;

-- Speeds up "refunded bookings" filtering on the list.
ALTER TABLE `transactions`
    ADD INDEX `idx_txn_refund_status` (`refund_status`);

-- =============================================================================
-- End of migration.
-- =============================================================================
