-- =============================================================================
-- KRA & incentives (23 Oct 2026) — Phase 1
-- =============================================================================
-- The admin fills these in Trio HR; the CRM's "My KRA" page, Aisha and the
-- monthly incentive all follow them. HR and the CRM share this database, so
-- the tables live here once and both sides read them.
--
-- kra_months  The rules for a month (levels, rates, caps, lever targets) as
--             JSON. A month with no row uses the latest earlier month, so the
--             admin only re-saves when something changes. Until the first
--             month is saved, staff see no KRA at all.
-- kra_people  Each person's monthly cost (₹, from HR) and, optionally, a
--             target the admin typed instead of the one worked out from cost.
--             Same carry-forward rule, per person.
-- kra_feedback  Written by HR about one person; shown on their KRA card.
-- =============================================================================

CREATE TABLE IF NOT EXISTS `kra_months` (
  `month`      CHAR(7)      NOT NULL COMMENT 'YYYY-MM',
  `config`     TEXT         NOT NULL COMMENT 'JSON: levels, rates, caps, lever targets',
  `saved_by`   INT UNSIGNED NULL COMMENT 'hr_accounts.id',
  `saved_at`   DATETIME     NOT NULL,
  PRIMARY KEY (`month`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `kra_people` (
  `month`        CHAR(7)       NOT NULL,
  `crm_user_id`  INT           NOT NULL,
  `monthly_cost` INT UNSIGNED  NULL COMMENT '₹ per month: salary plus seat cost',
  `target_usd`   DECIMAL(10,2) NULL COMMENT 'Net MCO target typed by the admin; NULL = worked out from cost',
  `saved_by`     INT UNSIGNED  NULL,
  `saved_at`     DATETIME      NOT NULL,
  PRIMARY KEY (`month`, `crm_user_id`),
  KEY `ix_kra_people_user` (`crm_user_id`, `month`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `kra_feedback` (
  `id`           INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `crm_user_id`  INT           NOT NULL,
  `body`         TEXT          NOT NULL,
  `written_by`   INT UNSIGNED  NULL COMMENT 'hr_accounts.id',
  `created_at`   DATETIME      NOT NULL,
  PRIMARY KEY (`id`),
  KEY `ix_kra_feedback_user` (`crm_user_id`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
