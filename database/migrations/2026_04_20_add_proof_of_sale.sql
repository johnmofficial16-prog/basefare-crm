-- Migration: Add proof_of_sale_path to transactions

ALTER TABLE `transactions` 
ADD COLUMN `proof_of_sale_path` VARCHAR(255) NULL AFTER `checkin_completed`;
