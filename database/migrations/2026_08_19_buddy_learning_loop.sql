-- Buddy learning loop (P11) — the difference between an assistant that talks
-- and one that finds out whether talking worked.
--
-- 1. Nudge outcomes: when a lag/dry-spell nudge is delivered, did the thing it
--    asked for actually happen, and how fast? Filled by the trigger cron's
--    outcome pass; read by get_my_patterns ("after I flag an e-ticket you
--    close it in Xh") and, later, admin analytics. This is the evidence layer
--    behind any "Aisha improves productivity" claim.
ALTER TABLE `buddy_nudges` ADD COLUMN `outcome` ENUM('resolved','unresolved') DEFAULT NULL;
ALTER TABLE `buddy_nudges` ADD COLUMN `outcome_at` DATETIME DEFAULT NULL;
ALTER TABLE `buddy_nudges` ADD COLUMN `outcome_hours` DECIMAL(6,1) DEFAULT NULL;

-- 2. Message feedback: 1 = thumbs-up, -1 = thumbs-down, NULL = none. The only
--    true learning signal available without fine-tuning: the consolidator
--    reads disliked messages weekly and distills durable "avoid this" /
--    "more of this" preferences into buddy_agent_facts.
ALTER TABLE `buddy_messages` ADD COLUMN `feedback` TINYINT DEFAULT NULL;
