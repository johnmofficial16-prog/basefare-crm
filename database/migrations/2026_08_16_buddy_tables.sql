-- AI Buddy — core tables (P0) + dev-mode flag
-- First migration to run through the schema_migrations ledger for real
-- (everything before it was baselined). See AI_BUDDY_PLAN.md.

-- One row per conversation. kind separates the three buddy surfaces so their
-- histories, quotas and audits never mix.
CREATE TABLE IF NOT EXISTS `buddy_conversations` (
  `id`              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`         INT UNSIGNED    NOT NULL,
  `kind`            ENUM('agent','maintenance','admin') NOT NULL DEFAULT 'agent',
  `title`           VARCHAR(120)    DEFAULT NULL,
  `created_at`      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `last_message_at` DATETIME        DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_user_kind` (`user_id`, `kind`, `last_message_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Full transcript, both directions. Doubles as the audit trail and the quota
-- counter (role='user' rows per day).
CREATE TABLE IF NOT EXISTS `buddy_messages` (
  `id`              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `conversation_id` BIGINT UNSIGNED NOT NULL,
  `role`            ENUM('user','model','system') NOT NULL,
  `content`         MEDIUMTEXT      NOT NULL,
  `tokens_in`       INT UNSIGNED    DEFAULT NULL,
  `tokens_out`      INT UNSIGNED    DEFAULT NULL,
  `created_at`      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_conv` (`conversation_id`, `id`),
  KEY `idx_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Every tool invocation the model makes: what, with which args, how many rows
-- came back. This is the "what did the AI actually look at" audit.
CREATE TABLE IF NOT EXISTS `buddy_tool_calls` (
  `id`              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `conversation_id` BIGINT UNSIGNED NOT NULL,
  `user_id`         INT UNSIGNED    NOT NULL,
  `tool_name`       VARCHAR(64)     NOT NULL,
  `args_json`       TEXT            DEFAULT NULL,
  `result_rows`     INT             DEFAULT NULL,
  `duration_ms`     INT             DEFAULT NULL,
  `created_at`      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_conv` (`conversation_id`),
  KEY `idx_user_time` (`user_id`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Durable per-agent memory (onboarding interview + facts the buddy saves).
-- Used from P1; created now so there is exactly one buddy migration.
CREATE TABLE IF NOT EXISTS `buddy_agent_facts` (
  `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`    INT UNSIGNED    NOT NULL,
  `fact`       VARCHAR(500)    NOT NULL,
  `source`     ENUM('interview','chat','consolidator') NOT NULL DEFAULT 'chat',
  `active`     TINYINT(1)      NOT NULL DEFAULT 1,
  `created_at` DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME        DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_user_active` (`user_id`, `active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Deterministic trigger-engine output (P1). dedupe_key is the idempotency
-- guarantee: one nudge per (rule, entity), ever.
CREATE TABLE IF NOT EXISTS `buddy_nudges` (
  `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`      INT UNSIGNED    NOT NULL,
  `type`         VARCHAR(40)     NOT NULL,
  `ref_table`    VARCHAR(40)     DEFAULT NULL,
  `ref_id`       BIGINT UNSIGNED DEFAULT NULL,
  `payload_json` TEXT            DEFAULT NULL,
  `status`       ENUM('pending','delivered','seen') NOT NULL DEFAULT 'pending',
  `dedupe_key`   VARCHAR(120)    NOT NULL,
  `created_at`   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `delivered_at` DATETIME        DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_dedupe` (`dedupe_key`),
  KEY `idx_user_status` (`user_id`, `status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Per-user buddy preferences (voice toggle, display name, onboarding stamp).
CREATE TABLE IF NOT EXISTS `buddy_settings` (
  `user_id`       INT UNSIGNED NOT NULL,
  `enabled`       TINYINT(1)   NOT NULL DEFAULT 1,
  `voice_enabled` TINYINT(1)   NOT NULL DEFAULT 1,
  `display_name`  VARCHAR(60)  DEFAULT NULL,
  `onboarded_at`  DATETIME     DEFAULT NULL,
  `extra_json`    TEXT         DEFAULT NULL,
  PRIMARY KEY (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dev-mode flag. Deliberately a boolean on users, NOT a sixth role enum value:
-- there are 174 role-comparison sites in the codebase and the role enum has a
-- history of migration warnings. is_dev layers ON TOP of the user's role
-- (John keeps admin) and gates the maintenance buddy + future dev tooling.
ALTER TABLE `users` ADD COLUMN `is_dev` TINYINT(1) NOT NULL DEFAULT 0;
