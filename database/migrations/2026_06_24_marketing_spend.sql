-- =============================================================================
-- Migration: Marketing Spend (centre analytics)
-- Base Fare CRM — 2026-06-24
-- =============================================================================
-- Run ONCE per environment (registered in hostinger_migrate.php). Additive only.
--
-- Admin records marketing spend per CENTRE for a flexible date range (a campaign,
-- a week, a month). The analytics dashboard prorates whatever overlaps the
-- selected window to compute ROI / cost-per-booking against performance.
--
-- Centre membership itself is derived live (CentreService) + stored config in
-- system_config (keys "centre.*") — no schema needed for that.
-- =============================================================================

CREATE TABLE IF NOT EXISTS `marketing_spend` (
    `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `centre`       ENUM('DMR','MOH','JSR') NOT NULL,
    `period_start` DATE            NOT NULL,
    `period_end`   DATE            NOT NULL,
    `amount`       DECIMAL(12,2)   NOT NULL,
    `currency`     VARCHAR(10)     NOT NULL DEFAULT 'USD',
    `channel`      VARCHAR(100)    NULL  COMMENT 'e.g. Google Ads, Meta, etc.',
    `notes`        VARCHAR(500)    NULL,
    `created_by`   INT             NOT NULL,
    `created_at`   TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`   TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (`id`),
    INDEX `idx_ms_centre` (`centre`),
    INDEX `idx_ms_period` (`period_start`, `period_end`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Marketing spend per centre over a date range (for ROI analytics).';

-- =============================================================================
-- End of migration.
-- =============================================================================
