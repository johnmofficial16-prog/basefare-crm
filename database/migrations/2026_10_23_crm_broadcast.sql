-- =============================================================================
-- CRM Messages — broadcasts from admins (23 Oct 2026)
-- =============================================================================
-- An admin can send one message to everyone, or to chosen roles. A broadcast
-- is a conversation whose members are everyone it went to; only admins can
-- write in it (staff reply privately to the sender instead, so there is no
-- reply-all flood).
-- =============================================================================

ALTER TABLE `chat_threads`
  ADD COLUMN `kind`     ENUM('chat','broadcast') NOT NULL DEFAULT 'chat' AFTER `subject`,
  ADD COLUMN `audience` VARCHAR(80) NULL DEFAULT NULL COMMENT 'Broadcasts: who it went to, e.g. Everyone, or Agents, Team leaders' AFTER `kind`;
