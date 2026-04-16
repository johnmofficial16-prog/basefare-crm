-- =============================================================================
-- BaseFare CRM — Four-Tier RBAC Migration
-- Run this once on the live database BEFORE deploying the updated code.
-- Safe to run multiple times (uses IF NOT EXISTS / MODIFY only when needed).
-- =============================================================================

-- 1. Add 'supervisor' to the users.role ENUM
ALTER TABLE `users`
    MODIFY COLUMN `role` ENUM('admin', 'manager', 'supervisor', 'agent') NOT NULL DEFAULT 'agent';

-- 2. Add reports_to_id column (agents/supervisors report to a manager or supervisor)
ALTER TABLE `users`
    ADD COLUMN IF NOT EXISTS `reports_to_id` INT DEFAULT NULL AFTER `role`,
    ADD CONSTRAINT `fk_user_reports_to`
        FOREIGN KEY IF NOT EXISTS (`reports_to_id`) REFERENCES `users` (`id`) ON DELETE SET NULL;

-- 3. Add shift approval workflow columns to shift_schedules
ALTER TABLE `shift_schedules`
    ADD COLUMN IF NOT EXISTS `publish_status` ENUM('draft','pending_approval','published') NOT NULL DEFAULT 'published' AFTER `schedule_week`,
    ADD COLUMN IF NOT EXISTS `approved_by` INT DEFAULT NULL AFTER `publish_status`,
    ADD COLUMN IF NOT EXISTS `approved_at` DATETIME DEFAULT NULL AFTER `approved_by`,
    ADD CONSTRAINT `fk_shift_approver`
        FOREIGN KEY IF NOT EXISTS (`approved_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

-- 4. Ensure existing shifts default to 'published' (should already be the case via DEFAULT above)
UPDATE `shift_schedules` SET `publish_status` = 'published' WHERE `publish_status` IS NULL OR `publish_status` = '';

SELECT 'Migration complete. Four-tier RBAC schema is ready.' AS status;
