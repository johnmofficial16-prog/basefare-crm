-- =============================================================================
-- Migration: record_notes — Polymorphic note/activity log for acceptances & transactions
-- Base Fare CRM
-- =============================================================================

CREATE TABLE IF NOT EXISTS `record_notes` (
  `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `entity_type` ENUM('acceptance','transaction') NOT NULL COMMENT 'Which record this note belongs to',
  `entity_id`   BIGINT UNSIGNED NOT NULL              COMMENT 'acceptance_requests.id or transactions.id',
  `user_id`     INT NOT NULL                          COMMENT 'FK → users.id — who wrote the note',
  `note`        TEXT NOT NULL                         COMMENT 'Free-text note content',
  `action`      VARCHAR(50) NULL                      COMMENT 'created, edited, viewed, approved, voided, emailed, etc.',
  `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

  PRIMARY KEY (`id`),
  INDEX `idx_rn_entity`  (`entity_type`, `entity_id`),
  INDEX `idx_rn_user`    (`user_id`),
  INDEX `idx_rn_created` (`created_at`),

  CONSTRAINT `fk_rn_user`
    FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Polymorphic notes/activity log per acceptance or transaction';
