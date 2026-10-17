-- =============================================================================
-- Trio HR — Phase 1 foundation (15 Oct 2026)
-- =============================================================================
-- The HR portal (hr.triotoursandtravels.in, code in app/Hr + public_hr) keeps
-- its own tables, all prefixed hr_, in the CRM database. The HR code reaches
-- them through a separately named connection ("hr"), so moving them to their
-- own database later is a config change.
--
-- HR NEVER touches CRM logins or CRM sessions: it has its own accounts,
-- sessions and cookie. CRM data (users, clock-ins, roster) is read-only from
-- here, except through the guarded bridge commands listed in HR_PLAN.md §6.3.
--
-- Only new tables. Nothing in the CRM schema changes.
-- =============================================================================

-- People ----------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS `hr_employees` (
  `id`                 INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `employee_code`      VARCHAR(20)  NOT NULL COMMENT 'TT-0001 style, shown on letters and payslips',
  `crm_user_id`        INT NULL COMMENT 'users.id of their CRM login, if they have one',
  `full_name`          VARCHAR(120) NOT NULL,
  `preferred_name`     VARCHAR(60)  NULL,
  `work_email`         VARCHAR(190) NULL,
  `personal_email`     VARCHAR(190) NULL,
  `phone`              VARCHAR(20)  NULL,
  `date_of_birth`      DATE NULL,
  `gender`             ENUM('female','male','other','undisclosed') NULL,
  `designation`        VARCHAR(80)  NULL,
  `team`               VARCHAR(80)  NULL,
  `centre`             ENUM('MOH','DMR','JSR') NULL COMMENT 'Work centre; its state decides which labour rules apply',
  `employment_type`    ENUM('permanent','fixed_term','trainee') NOT NULL DEFAULT 'permanent',
  `contract_end`       DATE NULL COMMENT 'Fixed-term only',
  `date_joined`        DATE NULL,
  `probation_end`      DATE NULL,
  `confirmed_on`       DATE NULL,
  `notice_days`        SMALLINT UNSIGNED NULL,
  `reports_to_id`      INT UNSIGNED NULL COMMENT 'hr_employees.id of their team leader',
  `status`             ENUM('joining','active','notice','left') NOT NULL DEFAULT 'active',
  `last_working_day`   DATE NULL,
  `address`            TEXT NULL,
  `emergency_name`     VARCHAR(120) NULL,
  `emergency_relation` VARCHAR(40)  NULL,
  `emergency_phone`    VARCHAR(20)  NULL,
  `pan_status`         ENUM('not_checked','valid','inoperative') NOT NULL DEFAULT 'not_checked' COMMENT 'Checked against the income-tax portal by an admin',
  `uan`                VARCHAR(12)  NULL COMMENT 'EPF Universal Account Number, if PF applies',
  `esic_ip`            VARCHAR(17)  NULL COMMENT 'ESIC insurance number, if ESI applies',
  `profile_completed_at` DATETIME NULL COMMENT 'Set when the employee finishes their own profile',
  `created_at`         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_hr_employees_code` (`employee_code`),
  UNIQUE KEY `uq_hr_employees_crm_user` (`crm_user_id`),
  KEY `ix_hr_employees_status` (`status`),
  KEY `ix_hr_employees_reports_to` (`reports_to_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- PAN, Aadhaar and bank details, encrypted with the HR-only key (libsodium,
-- key outside the database). `hint` holds the last few characters for masking.
CREATE TABLE IF NOT EXISTS `hr_employee_secrets` (
  `employee_id` INT UNSIGNED NOT NULL,
  `field`       ENUM('pan','aadhaar','bank_account','bank_ifsc','bank_name','bank_holder') NOT NULL,
  `ciphertext`  VARBINARY(512) NOT NULL,
  `hint`        VARCHAR(12) NULL,
  `updated_by`  INT UNSIGNED NULL COMMENT 'hr_accounts.id',
  `updated_at`  DATETIME NOT NULL,
  PRIMARY KEY (`employee_id`, `field`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dated history: joined, job changes, status changes, invites. Drives the
-- timeline on each person's record. Never updated, only appended.
CREATE TABLE IF NOT EXISTS `hr_employee_history` (
  `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `employee_id`   INT UNSIGNED NOT NULL,
  `kind`          VARCHAR(40)  NOT NULL,
  `effective_on`  DATE NULL,
  `summary`       VARCHAR(255) NOT NULL,
  `detail`        JSON NULL,
  `by_account_id` INT UNSIGNED NULL,
  `at`            DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  KEY `ix_hr_history_employee` (`employee_id`, `at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Sign-in ---------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS `hr_accounts` (
  `id`                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `employee_id`         INT UNSIGNED NULL COMMENT 'NULL for owner/admin accounts that are not on the staff list',
  `display_name`        VARCHAR(120) NOT NULL,
  `email`               VARCHAR(190) NOT NULL,
  `password_hash`       VARCHAR(255) NULL COMMENT 'Argon2id; NULL until the invite is accepted',
  `role`                ENUM('owner','admin','employee') NOT NULL DEFAULT 'employee',
  `status`              ENUM('invited','active','disabled') NOT NULL DEFAULT 'invited',
  `failed_logins`       TINYINT UNSIGNED NOT NULL DEFAULT 0,
  `locked_until`        DATETIME NULL,
  `last_login_at`       DATETIME NULL,
  `password_changed_at` DATETIME NULL,
  `invited_at`          DATETIME NULL,
  `created_at`          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_hr_accounts_email` (`email`),
  UNIQUE KEY `uq_hr_accounts_employee` (`employee_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- One row per signed-in device. Only a SHA-256 of the cookie token is stored.
CREATE TABLE IF NOT EXISTS `hr_sessions` (
  `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `account_id`   INT UNSIGNED NOT NULL,
  `token_hash`   CHAR(64) NOT NULL,
  `csrf_token`   CHAR(64) NOT NULL,
  `ip`           VARCHAR(45)  NULL,
  `user_agent`   VARCHAR(255) NULL,
  `created_at`   DATETIME NOT NULL,
  `last_seen_at` DATETIME NOT NULL,
  `expires_at`   DATETIME NOT NULL COMMENT 'Absolute cap, whatever the activity',
  `reauth_at`    DATETIME NULL COMMENT 'Last password re-check, for sensitive actions',
  `via`          ENUM('password','crm') NOT NULL DEFAULT 'password' COMMENT 'crm = opened from the CRM without a password: bank changes and ID documents need a password sign-in',
  `revoked_at`   DATETIME NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_hr_sessions_token` (`token_hash`),
  KEY `ix_hr_sessions_account` (`account_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Single-use links: invitations and password resets. Hash only.
CREATE TABLE IF NOT EXISTS `hr_tokens` (
  `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `account_id` INT UNSIGNED NOT NULL,
  `purpose`    ENUM('invite','reset') NOT NULL,
  `token_hash` CHAR(64) NOT NULL,
  `expires_at` DATETIME NOT NULL,
  `used_at`    DATETIME NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_hr_tokens_hash` (`token_hash`),
  KEY `ix_hr_tokens_account` (`account_id`, `purpose`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `hr_login_attempts` (
  `id`    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `ip`    VARCHAR(45)  NOT NULL,
  `email` VARCHAR(190) NULL,
  `ok`    TINYINT(1)   NOT NULL,
  `kind`  ENUM('signin','reset','handoff') NOT NULL DEFAULT 'signin',
  `at`    DATETIME     NOT NULL,
  PRIMARY KEY (`id`),
  KEY `ix_hr_login_attempts_ip` (`ip`, `kind`, `at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Requests and approvals ------------------------------------------------------

-- Every staff request lands here and waits for an admin. `payload` is what was
-- asked for; `decision` is what was approved (e.g. 2 of 3 nights).
CREATE TABLE IF NOT EXISTS `hr_requests` (
  `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `employee_id`   INT UNSIGNED NOT NULL,
  `type`          ENUM('leave','attendance_fix','profile_change','bank_change','letter','document') NOT NULL,
  `status`        ENUM('pending','approved','partly_approved','sent_back','withdrawn') NOT NULL DEFAULT 'pending',
  `summary`       VARCHAR(255) NOT NULL,
  `payload`       JSON NOT NULL,
  `note`          TEXT NULL COMMENT 'What the employee wrote',
  `decided_by`    INT UNSIGNED NULL COMMENT 'hr_accounts.id',
  `decided_at`    DATETIME NULL,
  `decision_note` VARCHAR(255) NULL,
  `decision`      JSON NULL,
  `created_at`    DATETIME NOT NULL,
  `updated_at`    DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  KEY `ix_hr_requests_status` (`status`, `created_at`),
  KEY `ix_hr_requests_employee` (`employee_id`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Leave -----------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS `hr_leave_types` (
  `code`            VARCHAR(20) NOT NULL,
  `name`            VARCHAR(40) NOT NULL,
  `paid`            TINYINT(1)  NOT NULL DEFAULT 1,
  `needs_balance`   TINYINT(1)  NOT NULL DEFAULT 1,
  `min_notice_days` TINYINT UNSIGNED NOT NULL DEFAULT 0,
  `sort`            SMALLINT NOT NULL DEFAULT 0,
  PRIMARY KEY (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `hr_leave_types` (`code`, `name`, `paid`, `needs_balance`, `min_notice_days`, `sort`) VALUES
  ('casual',   'Casual leave', 1, 1, 3, 1),
  ('sick',     'Sick leave',   1, 1, 0, 2),
  ('earned',   'Earned leave', 1, 1, 7, 3),
  ('comp_off', 'Comp-off',     1, 1, 1, 4),
  ('unpaid',   'Unpaid leave', 0, 0, 3, 5);

CREATE TABLE IF NOT EXISTS `hr_leave_balances` (
  `employee_id` INT UNSIGNED NOT NULL,
  `leave_type`  VARCHAR(20) NOT NULL,
  `year`        SMALLINT UNSIGNED NOT NULL,
  `opening`     DECIMAL(5,1) NOT NULL DEFAULT 0,
  `accrued`     DECIMAL(5,1) NOT NULL DEFAULT 0,
  `used`        DECIMAL(5,1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`employee_id`, `leave_type`, `year`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Approved leave, one row per shift day (the work night that starts at 6 pm).
CREATE TABLE IF NOT EXISTS `hr_leave_days` (
  `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `employee_id` INT UNSIGNED NOT NULL,
  `request_id`  BIGINT UNSIGNED NOT NULL,
  `leave_type`  VARCHAR(20) NOT NULL,
  `shift_day`   DATE NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_hr_leave_days_employee_day` (`employee_id`, `shift_day`),
  KEY `ix_hr_leave_days_day` (`shift_day`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Company, compliance, audit, email --------------------------------------------

CREATE TABLE IF NOT EXISTS `hr_settings` (
  `key`        VARCHAR(60) NOT NULL,
  `value`      JSON NOT NULL,
  `updated_by` INT UNSIGNED NULL,
  `updated_at` DATETIME NOT NULL,
  PRIMARY KEY (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Statutory due dates are data, not code: each has a source and a status.
CREATE TABLE IF NOT EXISTS `hr_compliance_items` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `title`      VARCHAR(160) NOT NULL,
  `due_on`     DATE NOT NULL,
  `status`     ENUM('open','done','not_applicable') NOT NULL DEFAULT 'open',
  `note`       VARCHAR(255) NULL,
  `source`     VARCHAR(255) NULL,
  `done_at`    DATETIME NULL,
  `done_by`    INT UNSIGNED NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `ix_hr_compliance_due` (`status`, `due_on`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Every sign-in, change, approval, and every VIEW of pay or ID data.
-- `summary` is plain English for the "What happened" feed.
CREATE TABLE IF NOT EXISTS `hr_audit_log` (
  `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `at`          DATETIME NOT NULL,
  `account_id`  INT UNSIGNED NULL,
  `action`      VARCHAR(60) NOT NULL,
  `employee_id` INT UNSIGNED NULL COMMENT 'Whose data it was about',
  `entity`      VARCHAR(40) NULL,
  `entity_id`   BIGINT UNSIGNED NULL,
  `summary`     VARCHAR(255) NULL,
  `detail`      JSON NULL,
  `ip`          VARCHAR(45) NULL,
  PRIMARY KEY (`id`),
  KEY `ix_hr_audit_at` (`at`),
  KEY `ix_hr_audit_employee` (`employee_id`, `at`),
  KEY `ix_hr_audit_action` (`action`, `at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Every email goes through here. The unique dedupe key means the same email can
-- never be queued twice; sending happens after the database work is committed.
CREATE TABLE IF NOT EXISTS `hr_outbox` (
  `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `dedupe_key` VARCHAR(120) NOT NULL,
  `to_email`   VARCHAR(190) NOT NULL,
  `subject`    VARCHAR(200) NOT NULL,
  `body_text`  TEXT NOT NULL,
  `body_html`  MEDIUMTEXT NULL,
  `status`     ENUM('queued','sending','sent','failed') NOT NULL DEFAULT 'queued',
  `attempts`   TINYINT UNSIGNED NOT NULL DEFAULT 0,
  `last_error` VARCHAR(255) NULL,
  `created_at` DATETIME NOT NULL,
  `claimed_at` DATETIME NULL,
  `sent_at`    DATETIME NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_hr_outbox_dedupe` (`dedupe_key`),
  KEY `ix_hr_outbox_status` (`status`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Sign-in: the optional emailed code, remembered devices -----------------------
-- (No authenticator app: email + password only, John 16 Oct 2026.)

-- A password that checked out but still needs the emailed code (staff on a new
-- phone, only when the owner switches it on). Single use, 10 minutes.
CREATE TABLE IF NOT EXISTS `hr_login_challenges` (
  `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `account_id` INT UNSIGNED NOT NULL,
  `kind`       ENUM('email') NOT NULL DEFAULT 'email',
  `token_hash` CHAR(64) NOT NULL,
  `code_hash`  CHAR(64) NULL COMMENT 'Emailed code, hashed with the challenge token',
  `attempts`   TINYINT UNSIGNED NOT NULL DEFAULT 0,
  `ip`         VARCHAR(45)  NULL,
  `user_agent` VARCHAR(255) NULL,
  `expires_at` DATETIME NOT NULL,
  `used_at`    DATETIME NULL,
  `created_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_hr_login_challenges_token` (`token_hash`),
  KEY `ix_hr_login_challenges_account` (`account_id`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- A device that has signed in before ("remember this phone"): no new-device
-- alert from it, and no emailed code when that switch is on.
CREATE TABLE IF NOT EXISTS `hr_trusted_devices` (
  `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `account_id`   INT UNSIGNED NOT NULL,
  `token_hash`   CHAR(64) NOT NULL,
  `user_agent`   VARCHAR(255) NULL,
  `created_at`   DATETIME NOT NULL,
  `last_used_at` DATETIME NOT NULL,
  `revoked_at`   DATETIME NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_hr_trusted_devices_token` (`token_hash`),
  KEY `ix_hr_trusted_devices_account` (`account_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Notifications (the HR bell) -------------------------------------------------

CREATE TABLE IF NOT EXISTS `hr_notifications` (
  `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `account_id` INT UNSIGNED NOT NULL,
  `kind`       VARCHAR(40)  NOT NULL,
  `title`      VARCHAR(200) NOT NULL,
  `body`       VARCHAR(500) NULL,
  `link`       VARCHAR(255) NULL COMMENT 'In-app link, e.g. #/approvals?id=12',
  `created_at` DATETIME NOT NULL,
  `read_at`    DATETIME NULL,
  PRIMARY KEY (`id`),
  KEY `ix_hr_notifications_account` (`account_id`, `read_at`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- The law as data -------------------------------------------------------------

-- Every statutory rate, ceiling and limit, with the date it applies from and
-- its source. Values are never edited: a change adds a new row from a new date
-- and the old one is marked superseded, so past months keep their rules.
CREATE TABLE IF NOT EXISTS `hr_law_values` (
  `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `key`            VARCHAR(60)  NOT NULL,
  `label`          VARCHAR(160) NOT NULL,
  `value`          VARCHAR(60)  NOT NULL,
  `unit`           VARCHAR(30)  NULL,
  `scope`          VARCHAR(40)  NOT NULL DEFAULT 'India' COMMENT 'India, Punjab, Jharkhand',
  `effective_from` DATE NOT NULL,
  `status`         ENUM('in_force','draft','superseded') NOT NULL DEFAULT 'in_force',
  `source`         VARCHAR(255) NOT NULL,
  `note`           VARCHAR(255) NULL,
  `created_by`     INT UNSIGNED NULL,
  `created_at`     DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  KEY `ix_hr_law_values_key` (`key`, `effective_from`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Policies and acknowledgements -----------------------------------------------

-- Each published version is a row. Staff acknowledge a specific version.
CREATE TABLE IF NOT EXISTS `hr_policies` (
  `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `code`         VARCHAR(40)  NOT NULL,
  `version`      SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  `title`        VARCHAR(160) NOT NULL,
  `summary`      VARCHAR(255) NULL,
  `body`         MEDIUMTEXT NOT NULL COMMENT 'Plain text with light formatting (# heading, - list, **bold**)',
  `audience`     ENUM('all','women_nights') NOT NULL DEFAULT 'all',
  `kind`         ENUM('policy','consent') NOT NULL DEFAULT 'policy' COMMENT 'A consent can be withdrawn by the employee',
  `status`       ENUM('draft','published','retired') NOT NULL DEFAULT 'draft',
  `published_at` DATETIME NULL,
  `published_by` INT UNSIGNED NULL,
  `created_by`   INT UNSIGNED NULL,
  `created_at`   DATETIME NOT NULL,
  `updated_at`   DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_hr_policies_code_version` (`code`, `version`),
  KEY `ix_hr_policies_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `hr_policy_acks` (
  `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `policy_id`    INT UNSIGNED NOT NULL,
  `employee_id`  INT UNSIGNED NOT NULL,
  `typed_name`   VARCHAR(120) NOT NULL,
  `acked_at`     DATETIME NOT NULL,
  `ip`           VARCHAR(45)  NULL,
  `user_agent`   VARCHAR(255) NULL,
  `withdrawn_at` DATETIME NULL COMMENT 'Consents only: the employee took it back',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_hr_policy_acks` (`policy_id`, `employee_id`),
  KEY `ix_hr_policy_acks_employee` (`employee_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Letters -----------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS `hr_letter_templates` (
  `code`       VARCHAR(40)  NOT NULL,
  `name`       VARCHAR(120) NOT NULL,
  `subject`    VARCHAR(160) NOT NULL,
  `body`       MEDIUMTEXT NOT NULL COMMENT 'Plain text with light formatting and {{fields}}',
  `fields`     JSON NULL COMMENT 'Extra details asked for when preparing: [{key,label,type,required,help}]',
  `needs_ack`  TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'The employee must acknowledge it',
  `staff_can_request` TINYINT(1) NOT NULL DEFAULT 0,
  `sort`       SMALLINT NOT NULL DEFAULT 0,
  `updated_by` INT UNSIGNED NULL,
  `updated_at` DATETIME NOT NULL,
  PRIMARY KEY (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Every letter is numbered, archived exactly as issued and carries a
-- verification code. A second admin checks it before it is issued.
CREATE TABLE IF NOT EXISTS `hr_letters` (
  `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `number`        VARCHAR(30)  NULL COMMENT 'Given on issue, e.g. TT/HR/2026/0007',
  `employee_id`   INT UNSIGNED NOT NULL,
  `template_code` VARCHAR(40)  NOT NULL,
  `subject`       VARCHAR(160) NOT NULL,
  `data`          JSON NOT NULL COMMENT 'The details used, frozen when prepared',
  `body_html`     MEDIUMTEXT NOT NULL COMMENT 'Rendered body, frozen on issue',
  `status`        ENUM('draft','pending_check','issued','acknowledged','void') NOT NULL DEFAULT 'draft',
  `request_id`    BIGINT UNSIGNED NULL COMMENT 'The staff request it answers, if any',
  `prepared_by`   INT UNSIGNED NULL,
  `prepared_at`   DATETIME NULL,
  `checked_by`    INT UNSIGNED NULL,
  `check_note`    VARCHAR(255) NULL,
  `issued_at`     DATETIME NULL,
  `ack_at`        DATETIME NULL,
  `ack_name`      VARCHAR(120) NULL,
  `ack_ip`        VARCHAR(45)  NULL,
  `verify_code`   CHAR(12) NULL,
  `void_reason`   VARCHAR(255) NULL,
  `created_at`    DATETIME NOT NULL,
  `updated_at`    DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_hr_letters_number` (`number`),
  UNIQUE KEY `uq_hr_letters_verify` (`verify_code`),
  KEY `ix_hr_letters_employee` (`employee_id`, `created_at`),
  KEY `ix_hr_letters_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Documents vault -----------------------------------------------------------------

-- Files are stored outside the web root, encrypted with the HR-only key.
CREATE TABLE IF NOT EXISTS `hr_documents` (
  `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `employee_id`  INT UNSIGNED NOT NULL,
  `kind`         ENUM('aadhaar','pan','photo','education','experience','bank_proof','address_proof','other') NOT NULL,
  `title`        VARCHAR(160) NOT NULL,
  `file_name`    VARCHAR(160) NOT NULL,
  `storage_key`  CHAR(40) NOT NULL COMMENT 'Random file name in storage/hr',
  `mime`         VARCHAR(60)  NOT NULL,
  `size`         INT UNSIGNED NOT NULL,
  `sha256`       CHAR(64) NOT NULL COMMENT 'Of the original file',
  `expires_on`   DATE NULL,
  `status`       ENUM('pending','verified','rejected') NOT NULL DEFAULT 'pending',
  `uploaded_by`  INT UNSIGNED NULL COMMENT 'hr_accounts.id',
  `verified_by`  INT UNSIGNED NULL,
  `verified_at`  DATETIME NULL,
  `reject_note`  VARCHAR(255) NULL,
  `created_at`   DATETIME NOT NULL,
  `deleted_at`   DATETIME NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_hr_documents_storage` (`storage_key`),
  KEY `ix_hr_documents_employee` (`employee_id`, `deleted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
