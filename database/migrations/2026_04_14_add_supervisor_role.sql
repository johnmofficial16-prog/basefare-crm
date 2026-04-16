-- ============================================================
-- Migration: 2026_04_14_add_supervisor_role
-- Purpose:   Add 4-tier RBAC (agent, supervisor, manager, admin)
-- Safe to re-run: YES (uses IF NOT EXISTS / MODIFY with no data loss)
-- ============================================================

-- 1. Add 'supervisor' to the users.role ENUM
--    MODIFY preserves existing rows — they keep their current role value.
ALTER TABLE `users`
  MODIFY COLUMN `role` ENUM('admin','manager','supervisor','agent') NOT NULL DEFAULT 'agent';

-- 2. Add reports_to_id — every agent/supervisor maps to their direct superior.
--    Nullable: admins/managers at the top have no "reports_to".
ALTER TABLE `users`
  ADD COLUMN IF NOT EXISTS `reports_to_id` INT NULL AFTER `role`,
  ADD CONSTRAINT IF NOT EXISTS `fk_user_reports_to`
    FOREIGN KEY (`reports_to_id`) REFERENCES `users`(`id`) ON DELETE SET NULL;

-- 3. Add shift publish workflow columns to shift_schedules.
--    Default = 'published' so ALL existing rows continue to work normally.
ALTER TABLE `shift_schedules`
  ADD COLUMN IF NOT EXISTS `publish_status`
    ENUM('draft','pending_approval','published') NOT NULL DEFAULT 'published' AFTER `created_by`,
  ADD COLUMN IF NOT EXISTS `approved_by` INT NULL AFTER `publish_status`,
  ADD COLUMN IF NOT EXISTS `approved_at` DATETIME NULL AFTER `approved_by`,
  ADD CONSTRAINT IF NOT EXISTS `fk_shift_approved_by`
    FOREIGN KEY (`approved_by`) REFERENCES `users`(`id`) ON DELETE SET NULL;
