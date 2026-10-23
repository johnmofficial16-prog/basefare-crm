-- =============================================================================
-- Late-login denials (23 Oct 2026)
-- =============================================================================
-- AttendanceService::denyOverride() has always saved override_type = 'denial',
-- but the column's ENUM never listed it. On a strict server the Deny button
-- failed ("An error occurred while denying the override"); on a lenient one
-- the row was saved with a blank type, so nothing could tell it was a denial —
-- including the late-login waiting page, which now shows the agent the answer.
--
-- 1. Allow the value.
-- 2. Recover denials already saved blank (new_value is 'denied' only for them).
-- =============================================================================

ALTER TABLE `attendance_overrides`
  MODIFY COLUMN `override_type` ENUM('late_login','early_logout','missed_clockout','manual_entry','time_correction','denial') NOT NULL;

UPDATE `attendance_overrides` SET `override_type` = 'denial'
 WHERE `override_type` = '' AND `new_value` = 'denied';
