-- =============================================================================
-- Migration: Add Pre-Authorization columns to acceptance_requests
-- =============================================================================

ALTER TABLE `acceptance_requests`
    ADD COLUMN IF NOT EXISTS `is_preauth`  TINYINT(1)   NOT NULL DEFAULT 0    COMMENT '1 = Quick Pre-Auth (total only), 0 = Full acceptance',
    ADD COLUMN IF NOT EXISTS `preauth_id`  INT UNSIGNED NULL                   COMMENT 'FK → acceptance_requests.id — links full acceptance to parent pre-auth';

-- Index for fast lookup of "what full acceptances came from this pre-auth"
ALTER TABLE `acceptance_requests`
    ADD INDEX IF NOT EXISTS `idx_preauth_id` (`preauth_id`);
