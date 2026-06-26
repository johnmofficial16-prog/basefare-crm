-- =============================================================================
-- Migration: Chargebacks & Refunds (centre analysis)
-- Base Fare CRM — 2026-06-26
-- =============================================================================
-- Run ONCE per environment (registered in hostinger_migrate.php). Additive only.
--
-- Admin manually records each chargeback / refund event and tags it to a CENTRE.
-- The Chargebacks & Refunds dashboard bifurcates the totals per centre over a
-- selected period. Centre is chosen on the entry (this data is not yet in the
-- CRM, so it can't be derived from an agent the way performance is).
-- =============================================================================

CREATE TABLE IF NOT EXISTS `chargeback_refunds` (
    `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `centre`        ENUM('DMR','MOH','JSR') NOT NULL,
    `kind`          ENUM('chargeback','refund') NOT NULL,
    `event_date`    DATE            NOT NULL  COMMENT 'When the chargeback/refund occurred',
    `amount`        DECIMAL(12,2)   NOT NULL,
    `currency`      VARCHAR(10)     NOT NULL DEFAULT 'USD',
    `pnr`           VARCHAR(20)     NULL,
    `customer_name` VARCHAR(255)    NULL,
    `reason`        VARCHAR(255)    NULL  COMMENT 'e.g. Friendly fraud, Service not rendered, Duplicate charge',
    `outcome`       ENUM('pending','won','lost','processed') NULL
                    COMMENT 'Chargebacks: pending/won/lost. Refunds: processed.',
    `notes`         VARCHAR(500)    NULL,
    `created_by`    INT             NOT NULL,
    `created_at`    TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`    TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (`id`),
    INDEX `idx_cbr_centre` (`centre`),
    INDEX `idx_cbr_kind`   (`kind`),
    INDEX `idx_cbr_date`   (`event_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Manually-entered chargebacks & refunds, tagged to a centre, for analysis.';

-- =============================================================================
-- End of migration.
-- =============================================================================
