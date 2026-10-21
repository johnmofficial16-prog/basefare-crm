-- =============================================================================
-- Notepad — each CRM user's own notes (24 Oct 2026)
-- =============================================================================
-- A slide-in notepad on every CRM page (sidebar "Notepad" or Alt+N). Notes are
-- private to the person who wrote them. They save as you type. Card numbers
-- are refused (PCI), as in Messages. Deleting is a soft delete.
-- =============================================================================

CREATE TABLE IF NOT EXISTS `user_notes` (
  `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`    INT             NOT NULL COMMENT 'users.id: the only person who can see it',
  `title`      VARCHAR(120)    NOT NULL DEFAULT '',
  `body`       MEDIUMTEXT      NOT NULL,
  `pinned`     TINYINT(1)      NOT NULL DEFAULT 0,
  `created_at` DATETIME        NOT NULL,
  `updated_at` DATETIME        NOT NULL,
  `deleted_at` DATETIME        NULL,
  PRIMARY KEY (`id`),
  KEY `ix_user_notes_user` (`user_id`, `deleted_at`, `pinned`, `updated_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
