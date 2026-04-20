-- Migration: Add dispute & gateway columns to transactions
-- Run via SSH: mysql -u USER -p DATABASE < this_file.sql

ALTER TABLE `transactions`
  ADD COLUMN `dispute_status`          ENUM('dispute_opened','chargeback_received','refunded_dispute','resolved') NULL DEFAULT NULL AFTER `agent_notes`,
  ADD COLUMN `dispute_notes`           TEXT NULL AFTER `dispute_status`,
  ADD COLUMN `dispute_flagged_at`      DATETIME NULL AFTER `dispute_notes`,
  ADD COLUMN `dispute_flagged_by`      INT NULL AFTER `dispute_flagged_at`,
  ADD COLUMN `gateway_status`          ENUM('charge_successful','charge_declined') NULL DEFAULT NULL AFTER `dispute_flagged_by`,
  ADD COLUMN `gateway_transaction_id`  VARCHAR(100) NULL AFTER `gateway_status`,
  ADD COLUMN `gateway_actioned_at`     DATETIME NULL AFTER `gateway_transaction_id`,
  ADD COLUMN `gateway_actioned_by`     INT NULL AFTER `gateway_actioned_at`;
