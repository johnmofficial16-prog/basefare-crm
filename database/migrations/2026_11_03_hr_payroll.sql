-- Trio HR, Phase 3 (Money): salaries, the monthly payroll run, payslips,
-- advances and tax profiles. John's decisions (26 Oct 2026): one person runs and
-- locks payroll (no second approver); one monthly salary per person, split on
-- the payslip into Basic / HRA / Special by a ratio set once; unpaid days cut
-- salary pro rata from the locked attendance month; the bank file is ICICI's
-- PAB-SAL bulk-upload format.

-- Each person's fixed monthly salary, with history. The one in force for a
-- month is the latest row whose effective_from is on or before the month's
-- last day (or the last working day of someone leaving).
CREATE TABLE IF NOT EXISTS `hr_salaries` (
  `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `employee_id`    INT UNSIGNED NOT NULL,
  `monthly`        INT UNSIGNED NOT NULL COMMENT 'Rupees, whole',
  `effective_from` DATE NOT NULL,
  `note`           VARCHAR(255) NULL,
  `created_by`     INT UNSIGNED NULL,
  `created_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_hr_salaries_emp` (`employee_id`, `effective_from`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- A payroll run: the regular month for everyone, or one person's final
-- settlement. run_key makes each one unique ("regular:2026-11",
-- "settlement:2026-11:12"). draft -> locked (figures frozen, payslips made)
-- -> paid (bank confirmed, payslips published).
CREATE TABLE IF NOT EXISTS `hr_payroll_runs` (
  `id`              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `run_key`         VARCHAR(40) NOT NULL,
  `kind`            ENUM('regular','settlement') NOT NULL DEFAULT 'regular',
  `month`           CHAR(7) NOT NULL COMMENT 'The salary month, YYYY-MM',
  `employee_id`     INT UNSIGNED NULL COMMENT 'Settlement only',
  `incentive_month` CHAR(7) NULL COMMENT 'The KRA month whose incentives this run pays',
  `status`          ENUM('draft','locked','paid') NOT NULL DEFAULT 'draft',
  `pay_date`        DATE NULL,
  `bank_ref`        VARCHAR(60) NULL,
  `totals`          JSON NULL,
  `checks`          JSON NULL,
  `prepared_by`     INT UNSIGNED NULL,
  `prepared_at`     DATETIME NULL,
  `locked_by`       INT UNSIGNED NULL,
  `locked_at`       DATETIME NULL,
  `paid_by`         INT UNSIGNED NULL,
  `paid_at`         DATETIME NULL,
  `created_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_hr_payroll_runs_key` (`run_key`),
  KEY `idx_hr_payroll_runs_month` (`month`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- One person's figures in a run. `detail` holds every line with its "why".
CREATE TABLE IF NOT EXISTS `hr_payroll_lines` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `run_id`      INT UNSIGNED NOT NULL,
  `employee_id` INT UNSIGNED NOT NULL,
  `held`        TINYINT(1) NOT NULL DEFAULT 0,
  `hold_reason` VARCHAR(255) NULL,
  `earned`      INT NOT NULL DEFAULT 0 COMMENT 'Salary for the paid days',
  `incentive`   INT NOT NULL DEFAULT 0,
  `additions`   INT NOT NULL DEFAULT 0,
  `deductions`  INT NOT NULL DEFAULT 0 COMMENT 'Advance recovery + other deductions + PSDT, not TDS',
  `tds`         INT NOT NULL DEFAULT 0,
  `net`         INT NOT NULL DEFAULT 0,
  `detail`      JSON NULL,
  `paid_at`     DATETIME NULL,
  `bank_file_at` DATETIME NULL COMMENT 'Put in an ICICI file: left out of later files, so nobody is paid twice',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_hr_payroll_lines` (`run_id`, `employee_id`),
  KEY `idx_hr_payroll_lines_emp` (`employee_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- One-off additions and deductions on a draft run, each with a reason.
CREATE TABLE IF NOT EXISTS `hr_payroll_adjustments` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `run_id`      INT UNSIGNED NOT NULL,
  `employee_id` INT UNSIGNED NOT NULL,
  `kind`        ENUM('addition','deduction') NOT NULL,
  `label`       VARCHAR(80) NOT NULL,
  `amount`      INT UNSIGNED NOT NULL,
  `taxable`     TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'Additions: part of taxable salary (arrears, bonus) or not (reimbursement)',
  `reason`      VARCHAR(255) NOT NULL,
  `created_by`  INT UNSIGNED NULL,
  `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_hr_payroll_adj` (`run_id`, `employee_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- The payslip made when a run is locked; staff see it once the run is paid.
-- `data` is frozen (letterhead, person, every line, YTD), so it never changes.
CREATE TABLE IF NOT EXISTS `hr_payslips` (
  `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `run_id`       INT UNSIGNED NOT NULL,
  `line_id`      INT UNSIGNED NOT NULL,
  `employee_id`  INT UNSIGNED NOT NULL,
  `number`       VARCHAR(30) NOT NULL,
  `verify_code`  CHAR(12) NOT NULL,
  `data`         JSON NOT NULL,
  `published_at` DATETIME NULL,
  `created_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_hr_payslips_number` (`number`),
  UNIQUE KEY `uq_hr_payslips_code` (`verify_code`),
  UNIQUE KEY `uq_hr_payslips_line` (`line_id`),
  KEY `idx_hr_payslips_emp` (`employee_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Salary advances / loans, recovered from payroll in monthly instalments
-- (never more than the deduction cap allows). Recovered = sum of recoveries
-- in locked runs.
CREATE TABLE IF NOT EXISTS `hr_advances` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `employee_id` INT UNSIGNED NOT NULL,
  `amount`      INT UNSIGNED NOT NULL,
  `monthly`     INT UNSIGNED NOT NULL COMMENT 'Instalment per payroll month',
  `start_month` CHAR(7) NOT NULL,
  `reason`      VARCHAR(255) NOT NULL,
  `status`      ENUM('active','closed','cancelled') NOT NULL DEFAULT 'active',
  `created_by`  INT UNSIGNED NULL,
  `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `closed_at`   DATETIME NULL,
  PRIMARY KEY (`id`),
  KEY `idx_hr_advances_emp` (`employee_id`, `status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `hr_advance_recoveries` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `advance_id` INT UNSIGNED NOT NULL,
  `run_id`     INT UNSIGNED NOT NULL,
  `amount`     INT UNSIGNED NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_hr_advance_recoveries` (`advance_id`, `run_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Each person's tax set-up for a tax year (April-March): regime (new is the
-- default), income and TDS from a previous employer this year (Form 122), and
-- old-regime deductions they declared (Form 124 total).
CREATE TABLE IF NOT EXISTS `hr_tax_profiles` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `employee_id` INT UNSIGNED NOT NULL,
  `tax_year`    CHAR(7) NOT NULL COMMENT 'e.g. 2026-27',
  `regime`      ENUM('new','old') NOT NULL DEFAULT 'new',
  `prev_income` INT UNSIGNED NOT NULL DEFAULT 0,
  `prev_tds`    INT UNSIGNED NOT NULL DEFAULT 0,
  `deductions`  INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Old regime only',
  `note`        VARCHAR(255) NULL,
  `updated_by`  INT UNSIGNED NULL,
  `updated_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_hr_tax_profiles` (`employee_id`, `tax_year`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
