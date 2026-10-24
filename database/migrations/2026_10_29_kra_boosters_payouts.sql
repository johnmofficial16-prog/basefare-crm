-- =============================================================================
-- KRA & incentives — phases 2 and 3 (24 Oct 2026)
-- =============================================================================
-- kra_boosters  A daily booster the admin switches on for a slow night:
--               "₹300 for every approved sale over $150 MCO until 6 AM". What
--               it has paid is worked out from the sales themselves, so it can
--               never drift; it stops at its end time, when stopped, or when
--               its budget runs out.
-- kra_payouts   A closed month: what each person is paid, frozen. Written once
--               when the admin closes the month (after the refund cut-off), so
--               payroll always reads the same figure the statement showed.
-- =============================================================================

CREATE TABLE IF NOT EXISTS `kra_boosters` (
  `id`          INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `amount_inr`  INT UNSIGNED  NOT NULL COMMENT 'paid per qualifying sale',
  `min_mco`     DECIMAL(10,2) NULL COMMENT 'sale must make at least this MCO ($); NULL = every sale',
  `scope`       VARCHAR(20)   NOT NULL DEFAULT 'all' COMMENT 'all, or team:<team leader user id>',
  `budget_inr`  INT UNSIGNED  NOT NULL,
  `starts_at`   DATETIME      NOT NULL,
  `ends_at`     DATETIME      NOT NULL,
  `stopped_at`  DATETIME      NULL,
  `created_by`  INT UNSIGNED  NULL COMMENT 'hr_accounts.id',
  `created_at`  DATETIME      NOT NULL,
  PRIMARY KEY (`id`),
  KEY `ix_kra_boosters_time` (`starts_at`, `ends_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `kra_payouts` (
  `month`        CHAR(7)       NOT NULL,
  `crm_user_id`  INT           NOT NULL,
  `name`         VARCHAR(120)  NOT NULL COMMENT 'as it was when the month closed',
  `role`         VARCHAR(20)   NOT NULL,
  `net_mco`      DECIMAL(12,2) NOT NULL,
  `monthly_inr`  INT           NOT NULL,
  `team_inr`     INT           NOT NULL,
  `spot_inr`     INT           NOT NULL,
  `booster_inr`  INT           NOT NULL,
  `scale`        DECIMAL(6,4)  NOT NULL DEFAULT 1 COMMENT 'cap applied to everyone this month (1 = none)',
  `total_inr`    INT           NOT NULL,
  `closed_by`    INT UNSIGNED  NULL,
  `closed_at`    DATETIME      NOT NULL,
  PRIMARY KEY (`month`, `crm_user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- A closed month, even one where nobody earned anything (so it can't be
-- closed twice), and the one place that says it's locked.
CREATE TABLE IF NOT EXISTS `kra_closed_months` (
  `month`      CHAR(7)       NOT NULL,
  `scale`      DECIMAL(6,4)  NOT NULL DEFAULT 1,
  `total_inr`  INT           NOT NULL,
  `closed_by`  INT UNSIGNED  NULL,
  `closed_at`  DATETIME      NOT NULL,
  PRIMARY KEY (`month`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Spot rewards and booster payments once shown as paid. They keep their place
-- ahead of anything newer, so a sale approved late can't take back money
-- someone was already told they'd earned. Keys like 'boost:12:4581'.
CREATE TABLE IF NOT EXISTS `kra_award_log` (
  `k`             VARCHAR(80)  NOT NULL,
  `committed_at`  DATETIME     NOT NULL,
  PRIMARY KEY (`k`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
