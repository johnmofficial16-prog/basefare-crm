-- =============================================================================
-- Migration: allow MANUAL e-tickets (no linked transaction)
-- Base Fare CRM — 2026-08-10
-- =============================================================================
-- Run ONCE per environment (registered in hostinger_migrate.php).
--
-- WHY: e-tickets were hard-wired 1:1 to a logged transaction. When the hosting
-- layer blocked transaction saves (Aug 2026 incident), agents could not send
-- customers their e-tickets at all — the dependency chain had no escape hatch.
-- Managers/admins can now issue an e-ticket with no linked booking; the
-- application layer enforces WHO may do that (ETicketController), this
-- migration only makes it storable.
--
-- transaction_id becomes NULLable. The UNIQUE key on it still enforces 1:1 for
-- linked tickets (MySQL allows any number of NULLs in a UNIQUE column), and the
-- FK still guards real references. Existing rows are untouched.
--
-- Idempotent: nullability is checked via information_schema before altering.
-- =============================================================================

SET @is_nullable := (SELECT IS_NULLABLE FROM information_schema.COLUMNS
                     WHERE TABLE_SCHEMA = DATABASE()
                       AND TABLE_NAME   = 'etickets'
                       AND COLUMN_NAME  = 'transaction_id');

-- 1. Drop the FK first — MODIFY on an FK column is refused by some MySQL builds.
SET @fk := (SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME   = 'etickets'
              AND COLUMN_NAME  = 'transaction_id'
              AND REFERENCED_TABLE_NAME = 'transactions'
            LIMIT 1);
SET @s := IF(@is_nullable = 'NO' AND @fk IS NOT NULL,
  CONCAT('ALTER TABLE `etickets` DROP FOREIGN KEY `', @fk, '`'),
  'SELECT 1');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 2. Make the column nullable.
SET @s := IF(@is_nullable = 'NO',
  'ALTER TABLE `etickets`
     MODIFY COLUMN `transaction_id` BIGINT UNSIGNED NULL
     COMMENT ''FK -> transactions.id; NULL = manual e-ticket (manager/admin issued, no linked booking)''',
  'SELECT 1');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 3. Re-add the FK (same semantics; NULLs are simply not checked).
SET @s := IF(@is_nullable = 'NO',
  'ALTER TABLE `etickets`
     ADD CONSTRAINT `fk_etickets_transaction`
     FOREIGN KEY (`transaction_id`) REFERENCES `transactions` (`id`) ON DELETE RESTRICT',
  'SELECT 1');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- =============================================================================
-- End of migration.
-- =============================================================================
