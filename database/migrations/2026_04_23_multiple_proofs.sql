-- Migration: Widen proof_of_sale_path to TEXT to store JSON arrays of multiple file paths.
-- Existing single paths (e.g. "storage/proofs/file.jpg") are converted to valid JSON arrays.
-- Safe to run multiple times — column widening is idempotent.

ALTER TABLE `transactions`
  MODIFY COLUMN `proof_of_sale_path` TEXT NULL COMMENT 'JSON array of proof file paths';

-- Convert any existing plain-string paths to single-element JSON arrays
UPDATE `transactions`
SET `proof_of_sale_path` = CONCAT('["', REPLACE(proof_of_sale_path, '"', '\\"'), '"]')
WHERE `proof_of_sale_path` IS NOT NULL
  AND `proof_of_sale_path` != ''
  AND LEFT(proof_of_sale_path, 1) != '[';
