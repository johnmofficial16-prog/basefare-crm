-- =============================================================================
-- Simple view for admin accounts (12 Oct 2026)
-- =============================================================================
-- An admin account with simple_view = 1 still has full admin access, but signs
-- in to a simplified interface: a short menu (Home, Approvals, Team, Sales,
-- Reports), plain-English pages and a confirmation box before every approval
-- or irreversible action. "Switch to full CRM" is always one click away.
-- Turned on per account from Users → Edit (admins only).
-- =============================================================================

ALTER TABLE `users`
  ADD COLUMN IF NOT EXISTS `simple_view` TINYINT(1) NOT NULL DEFAULT 0
  COMMENT 'Admin signs in to the simplified interface (SimpleViewController)'
  AFTER `grace_period_mins`;
