-- Migration: Add encrypted full CC columns to acceptance_requests
-- Run in Hostinger SSH: mysql -u USER -p DBNAME < this_file.sql

ALTER TABLE `acceptance_requests`
  ADD COLUMN `card_number_enc` TEXT NULL AFTER `card_last_four`,
  ADD COLUMN `card_expiry_enc` TEXT NULL AFTER `card_number_enc`,
  ADD COLUMN `card_cvv_enc`    TEXT NULL AFTER `card_expiry_enc`;
