-- =============================================================================
-- Migration: Make user_id nullable in record_notes table
-- Base Fare CRM — 2026-05-23
-- =============================================================================

ALTER TABLE `record_notes`
    MODIFY COLUMN `user_id` INT NULL DEFAULT NULL
    COMMENT 'FK → users.id — who wrote the note (NULL for system/customer)';
