-- ============================================================
-- Migration: Add pre-authorization columns to acceptance_requests
-- Date: 2026-04-15
-- NOTE: Run this on Hostinger via SSH before deploying code.
-- ============================================================

ALTER TABLE `acceptance_requests`
  ADD COLUMN `is_preauth`  TINYINT(1) NOT NULL DEFAULT 0
    COMMENT '1 = this is a Quick Pre-Authorization (simplified, no fare breakdown)'
    AFTER `agent_notes`,
  ADD COLUMN `preauth_id`  INT UNSIGNED NULL DEFAULT NULL
    COMMENT 'FK to the parent pre-auth record when this is a Full Acceptance created from a pre-auth'
    AFTER `is_preauth`,
  ADD KEY `idx_preauth_id` (`preauth_id`);
