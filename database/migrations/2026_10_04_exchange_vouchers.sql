-- =============================================================================
-- Exchange (reissue) Future Travel Vouchers
-- =============================================================================
-- On an Exchange / Date Change, the amount charged under Base Fare (fare line 1)
-- is returned to the customer as a Future Travel Voucher of the same amount.
-- The amount is set on the acceptance (acceptance_requests.extra_data.ftv — no
-- schema change needed there), carried to the transaction (transactions.data)
-- and the e-ticket (etickets.extra_data). When the agent previews the e-ticket,
-- a travel_vouchers row is issued and its PDF (rendered in the agent's browser
-- from the standard voucher design) is stored and attached to the e-ticket email.
--
-- These columns link that voucher back to its booking so it shows up in the
-- Vouchers list for redemption, and record where its PDF lives.
-- Existing hand-made vouchers keep source = 'manual' and NULL links.
--
-- Safe to re-run: ADD COLUMN / ADD INDEX IF NOT EXISTS (MariaDB).
-- =============================================================================

ALTER TABLE `travel_vouchers`
  ADD COLUMN IF NOT EXISTS `source` VARCHAR(20) NOT NULL DEFAULT 'manual'
    COMMENT 'manual = Vouchers maker; exchange = issued from an exchange e-ticket' AFTER `status`,
  ADD COLUMN IF NOT EXISTS `eticket_id` BIGINT UNSIGNED NULL COMMENT 'FK → etickets.id' AFTER `source`,
  ADD COLUMN IF NOT EXISTS `transaction_id` BIGINT UNSIGNED NULL COMMENT 'FK → transactions.id' AFTER `eticket_id`,
  ADD COLUMN IF NOT EXISTS `acceptance_id` BIGINT UNSIGNED NULL COMMENT 'FK → acceptance_requests.id' AFTER `transaction_id`,
  ADD COLUMN IF NOT EXISTS `pdf_path` VARCHAR(255) NULL
    COMMENT 'storage/vouchers/<file>.pdf — the PDF emailed to the customer' AFTER `acceptance_id`,
  ADD INDEX IF NOT EXISTS `idx_tv_eticket` (`eticket_id`),
  ADD INDEX IF NOT EXISTS `idx_tv_transaction` (`transaction_id`);
