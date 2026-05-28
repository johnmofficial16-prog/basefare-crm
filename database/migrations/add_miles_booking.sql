-- ============================================================
-- Migration: Add Miles / Award Booking support
-- Target:    MariaDB (tested on MariaDB 10.x+)
-- Tables:    acceptance_requests, transactions, etickets
-- Safe:      All columns use ALTER TABLE ADD COLUMN (additive only)
--            No existing columns touched, no data modified.
-- ============================================================

-- 1. acceptance_requests
ALTER TABLE `acceptance_requests`
  ADD COLUMN `is_miles_booking` TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Flag: 1 = miles/award redemption booking',
  ADD COLUMN `miles_used`       BIGINT UNSIGNED NULL DEFAULT NULL COMMENT 'Miles redeemed (e.g. 25000)',
  ADD COLUMN `miles_program`    VARCHAR(100) NULL DEFAULT NULL COMMENT 'Optional loyalty program name (e.g. United MileagePlus)';

-- 2. transactions
ALTER TABLE `transactions`
  ADD COLUMN `is_miles_booking` TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Flag: 1 = miles/award redemption booking',
  ADD COLUMN `miles_used`       BIGINT UNSIGNED NULL DEFAULT NULL COMMENT 'Miles redeemed (inherited from acceptance)',
  ADD COLUMN `miles_program`    VARCHAR(100) NULL DEFAULT NULL COMMENT 'Optional loyalty program name';

-- 3. etickets
ALTER TABLE `etickets`
  ADD COLUMN `is_miles_booking` TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Flag: 1 = miles/award redemption booking',
  ADD COLUMN `miles_used`       BIGINT UNSIGNED NULL DEFAULT NULL COMMENT 'Miles redeemed (inherited from transaction)',
  ADD COLUMN `miles_program`    VARCHAR(100) NULL DEFAULT NULL COMMENT 'Optional loyalty program name';
