<?php
/**
 * Pure rule helpers for the buddy trigger engine.
 *
 * Split out of buddy_triggers.php so they can be unit-tested without booting a
 * database connection (the cron itself connects to MySQL on include). No state,
 * no I/O — just the escalation arithmetic and the dedupe-key contract.
 *
 * Tested by scripts/buddy_feed_verify.php (F12).
 */

if (!function_exists('lagRound')) {
    /**
     * Escalation round for a lag rule. A friend who reminds you once and then
     * never again isn't much of a friend; one who nags every 15 minutes is
     * worse. Three tiers, each firing at most once — the round is part of the
     * dedupe key, so the UNIQUE index does the enforcing.
     *
     * @param float $waitingHours How long the entity has been stuck.
     * @param float $tier1        First-reminder threshold (4h e-tickets, 6h acceptances).
     * @return int  0 = too early to nudge, else the round number (1–3).
     */
    function lagRound(float $waitingHours, float $tier1): int
    {
        if ($waitingHours >= 72) {
            return 3;
        }
        if ($waitingHours >= 24) {
            return 2;
        }
        return $waitingHours >= $tier1 ? 1 : 0;
    }
}

if (!function_exists('lagKey')) {
    /**
     * Dedupe key for a lag nudge.
     *
     * Round 1 deliberately keeps the ORIGINAL key format ("rule:entity:id") so
     * that deploying escalation does not re-nudge every booking that is already
     * lagging — those rows exist with the old key and the UNIQUE index still
     * suppresses them. Only rounds 2+ carry a suffix.
     */
    function lagKey(string $rule, string $entity, int $id, int $round): string
    {
        return $round <= 1 ? "{$rule}:{$entity}:{$id}" : "{$rule}:{$entity}:{$id}:r{$round}";
    }
}
