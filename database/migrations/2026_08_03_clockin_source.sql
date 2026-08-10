-- =============================================================================
-- Migration: record HOW an attendance session was created
-- Base Fare CRM — 2026-08-03
-- =============================================================================
-- Run ONCE per environment (registered in hostinger_migrate.php). Additive only:
-- no existing column is altered or dropped, and no existing row loses data.
--
-- WHY
-- ---
-- An admin or manager can open an attendance session on an agent's behalf via
-- AttendanceService::adminClockIn(). Until now the only trace of that was the
-- string 'admin-manual' stuffed into `user_agent` — a field meant for the
-- browser UA, read by nothing in the application, and shown on no screen.
--
-- The July 2026 review found that only ~24% of one team's attendance days were
-- agents clocking themselves in; the rest were admin-created and indistinguishable
-- in every report. These columns make that visible and attributable.
--
-- `user_agent` keeps its 'admin-manual' value for backward compatibility so any
-- existing query or script that keys off it continues to work.
--
-- Idempotent: every statement checks information_schema first, so re-running is
-- a no-op rather than relying on the migrator swallowing duplicate errors.
-- =============================================================================

-- ── 1. created_via ───────────────────────────────────────────────────────────
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS
           WHERE TABLE_SCHEMA = DATABASE()
             AND TABLE_NAME   = 'attendance_sessions'
             AND COLUMN_NAME  = 'created_via');
SET @s := IF(@c = 0,
  'ALTER TABLE `attendance_sessions`
     ADD COLUMN `created_via` ENUM(''self'',''admin'') NOT NULL DEFAULT ''self''
     COMMENT ''self = agent clocked in themselves, admin = opened on their behalf''',
  'SELECT 1');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ── 2. created_by_user_id ────────────────────────────────────────────────────
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS
           WHERE TABLE_SCHEMA = DATABASE()
             AND TABLE_NAME   = 'attendance_sessions'
             AND COLUMN_NAME  = 'created_by_user_id');
SET @s := IF(@c = 0,
  'ALTER TABLE `attendance_sessions`
     ADD COLUMN `created_by_user_id` INT NULL
     COMMENT ''The admin/manager who opened this session; NULL when self clock-in''',
  'SELECT 1');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ── 3. created_reason ────────────────────────────────────────────────────────
-- Admins/managers must now state WHY they are opening a session on someone
-- else's behalf, the same way a late-login override already demands a reason.
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS
           WHERE TABLE_SCHEMA = DATABASE()
             AND TABLE_NAME   = 'attendance_sessions'
             AND COLUMN_NAME  = 'created_reason');
SET @s := IF(@c = 0,
  'ALTER TABLE `attendance_sessions`
     ADD COLUMN `created_reason` VARCHAR(255) NULL
     COMMENT ''Why an admin opened this session on the agent behalf''',
  'SELECT 1');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ── 4. Index for the per-source reporting split ──────────────────────────────
SET @c := (SELECT COUNT(*) FROM information_schema.STATISTICS
           WHERE TABLE_SCHEMA = DATABASE()
             AND TABLE_NAME   = 'attendance_sessions'
             AND INDEX_NAME   = 'idx_as_created_via');
SET @s := IF(@c = 0,
  'ALTER TABLE `attendance_sessions` ADD INDEX `idx_as_created_via` (`created_via`, `date`)',
  'SELECT 1');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ── 5. Backfill from the legacy marker ───────────────────────────────────────
-- Historic admin-created sessions are identifiable only by user_agent. This
-- reclassifies them so past months report correctly too. Deliberately NOT
-- setting created_by_user_id: the actor for historic rows lives in
-- activity_log (action = 'admin_clock_in', details.agent_id) and guessing it
-- here would invent an attribution the data cannot support.
UPDATE `attendance_sessions`
   SET `created_via` = 'admin'
 WHERE `user_agent` = 'admin-manual'
   AND `created_via` <> 'admin';

-- =============================================================================
-- End of migration.
-- =============================================================================
