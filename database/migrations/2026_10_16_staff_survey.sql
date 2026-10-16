-- =============================================================================
-- Migration: Staff survey (experience, mindset, goals, feedback)
-- Base Fare CRM — 2026-10-16
-- =============================================================================
-- Additive only. Picked up automatically by hostinger_migrate.php.
--
-- Every non-admin (agent, CSA, supervisor, manager) is asked to fill the
-- current survey (StaffSurveyService::KEY). It pops up after sign-in and when
-- they are idle; it can be skipped a few times, then it has to be filled.
-- Admins read the answers at /admin/survey.
--
-- staff_survey_responses: one row per person per survey (unique), answers as
--   JSON text (MEDIUMTEXT, not a JSON column, to stay MariaDB-neutral).
-- staff_survey_prompts: how often each person skipped, and when it may pop up
--   again (the snooze after a skip).
-- =============================================================================

CREATE TABLE IF NOT EXISTS `staff_survey_responses` (
    `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `survey_key`  VARCHAR(40)     NOT NULL COMMENT 'StaffSurveyService::KEY at the time',
    `user_id`     INT             NOT NULL,
    `role`        VARCHAR(20)     NOT NULL COMMENT 'Role when they answered',
    `answers`     MEDIUMTEXT      NOT NULL COMMENT 'JSON: question key => answer',
    `feedback`    TEXT            NULL     COMMENT 'Free feedback for management (also in answers)',
    `skips`       TINYINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Times they chose "Not now" first',
    `created_at`  TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_ssr_survey_user` (`survey_key`, `user_id`),
    INDEX `idx_ssr_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `staff_survey_prompts` (
    `survey_key`    VARCHAR(40)      NOT NULL,
    `user_id`       INT              NOT NULL,
    `skips`         TINYINT UNSIGNED NOT NULL DEFAULT 0,
    `snoozed_until` DATETIME         NULL COMMENT 'No idle pop-up before this time',
    `updated_at`    TIMESTAMP        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (`survey_key`, `user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
