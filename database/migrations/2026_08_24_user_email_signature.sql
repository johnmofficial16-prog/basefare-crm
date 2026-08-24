-- =============================================================================
-- Per-agent email signature
-- =============================================================================
-- Adds one column to `users` holding the agent-specific parts of their email
-- signature. The branding around those parts — logo, colours, layout, legal
-- footer — lives in App\Services\EmailSignature, NOT here, so a rebrand is one
-- code change rather than a row-by-row data migration.
--
-- Shape:
--   {"title":"Reservation Desk","direct":"888 608 4011","ext":"214","enabled":true}
--
-- NULL is the normal state for a new account: EmailSignature falls back to the
-- user's name and role, so every agent has a correct signature from day one
-- without anyone filling a form.
--
-- Safe to re-run: ADD COLUMN IF NOT EXISTS is a no-op once applied.
-- =============================================================================

ALTER TABLE `users`
  ADD COLUMN IF NOT EXISTS `email_signature` JSON NULL
  COMMENT 'Agent-specific signature fields; branding lives in EmailSignature service'
  AFTER `grace_period_mins`;
