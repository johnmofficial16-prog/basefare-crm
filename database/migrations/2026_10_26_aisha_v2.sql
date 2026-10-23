-- =============================================================================
-- Aisha v2 (23 Oct 2026) — the upgrade John approved after the usage report
-- showed a 13% habit rate, 9 Aisha messages for every 1 from staff, and
-- agents asking for help she refused to give.
-- =============================================================================
-- Additive only. Nothing is dropped or rewritten.
--
-- 1. buddy_messages learns what each message WAS (a chat reply, a greeting, a
--    nudge, a fallback, a notice), which model wrote it, how long it took and
--    whether the AI wrote it. Until now every row looked the same, so habit,
--    speed and failure rates could only be guessed from message text.
-- 2. buddy_nudges gets 'expired' and 'done' states (expired ones used to be
--    marked "delivered" and silently ate the daily cap), a due_at for personal
--    reminders ("remind me at 2:30") and acted_at for the new action buttons.
-- 3. buddy_events: what people actually do with the widget (open, chip, action
--    button, thumbs, quiet mode) — the engagement funnel.
-- 4. buddy_ai_usage: AI spend per shift day and model. The daily budget cap
--    reads this, so Aisha can never run up a surprise bill.
-- =============================================================================

ALTER TABLE `buddy_messages`
  ADD COLUMN `kind` VARCHAR(20) DEFAULT NULL COMMENT 'chat | greeting | nudge | fallback | notice',
  ADD COLUMN `model` VARCHAR(40) DEFAULT NULL,
  ADD COLUMN `latency_ms` INT DEFAULT NULL,
  ADD COLUMN `ai` TINYINT(1) DEFAULT NULL,
  ADD COLUMN `nudge_id` BIGINT UNSIGNED DEFAULT NULL,
  ADD KEY `idx_kind` (`kind`);

ALTER TABLE `buddy_nudges`
  MODIFY COLUMN `status` ENUM('pending','delivered','seen','expired','done') NOT NULL DEFAULT 'pending',
  ADD COLUMN `due_at` DATETIME DEFAULT NULL COMMENT 'personal reminders: not delivered before this',
  ADD COLUMN `acted_at` DATETIME DEFAULT NULL COMMENT 'agent pressed the action button or Done';

CREATE TABLE IF NOT EXISTS `buddy_events` (
  `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`    INT UNSIGNED    NOT NULL,
  `event`      VARCHAR(40)     NOT NULL COMMENT 'open | close | starter | chip | action | toast_open | toast_close | thumbs | quiet | voice | new_chat | error',
  `detail`     VARCHAR(255)    DEFAULT NULL,
  `created_at` DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_user_time` (`user_id`, `created_at`),
  KEY `idx_event_time` (`event`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `buddy_ai_usage` (
  `bday`       DATE            NOT NULL COMMENT 'shift day (6 PM to 6 PM)',
  `model`      VARCHAR(40)     NOT NULL COMMENT 'gemini model, or "tts" for the voice',
  `calls`      INT UNSIGNED    NOT NULL DEFAULT 0,
  `tokens_in`  BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `tokens_out` BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'for tts: characters spoken',
  `cost_usd`   DECIMAL(10,4)   NOT NULL DEFAULT 0,
  `updated_at` DATETIME        DEFAULT NULL,
  PRIMARY KEY (`bday`, `model`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
