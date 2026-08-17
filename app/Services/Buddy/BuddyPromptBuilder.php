<?php

namespace App\Services\Buddy;

use App\Services\ErrorLogService;

/**
 * BuddyPromptBuilder — the single choke point through which text reaches Gemini.
 *
 * Two jobs:
 *  1. Assemble the request: persona + conversation history (char-capped,
 *     oldest-first pruning — the Jarvis _prune_history idea in PHP).
 *  2. SCRUB (Wall 2, AI_BUDDY_PLAN.md §1): a belt-and-braces PII filter over
 *     every string headed to Google — user input AND tool results. Tools are
 *     already whitelisted-aggregate-only; a scrub hit therefore means a tool
 *     leaked something it shouldn't, so every hit is logged as a bug report.
 *
 * Scrubbed classes:
 *  - card numbers: 13–19 digit runs (allowing space/dash separators) that pass
 *    Luhn — replaced with [card-redacted]
 *  - emails — replaced with [email-redacted]
 *  - phone-like runs: 10–15 digits with optional separators/+ — replaced with
 *    [phone-redacted] (after the card pass, so cards win)
 */
class BuddyPromptBuilder
{
    /** Total character budget for conversation history sent to the model. */
    public const HISTORY_CHAR_BUDGET = 8000;

    /**
     * Max messages of history regardless of size. Raised from 12 when the P5
     * feed started writing Aisha-initiated rows into the same conversation —
     * a busy morning's nudges alone could fill 12 slots and evict the actual
     * dialogue. The char budget above still caps the real payload.
     */
    public const HISTORY_MAX_MESSAGES = 20;

    // =========================================================================
    // HISTORY
    // =========================================================================

    /**
     * Convert stored messages (oldest→newest, each ['role'=>'user'|'model',
     * 'content'=>string]) into Gemini contents, dropping oldest first when over
     * budget. The current user turn is appended by the caller.
     *
     * History is SCRUBBED on the way out, not trusted because it is already
     * stored. This class claims to be the single choke point through which text
     * reaches Gemini, and until P5 that claim quietly excluded history: stored
     * messages were assumed clean because user input is scrubbed on the way in.
     * Aisha-initiated messages broke that assumption — an admin's free-typed
     * message, relayed verbatim to the agent, is a stored model message that no
     * scrubber had ever seen. Scrubbing here closes the gap for every message
     * class at once, at the cost of three regexes over ~8KB per turn.
     *
     * The scrub applies ONLY to what Google sees. The stored transcript, and so
     * the agent's view of what their admin actually wrote, is left intact.
     */
    public static function buildContents(array $history): array
    {
        $history = array_slice($history, -self::HISTORY_MAX_MESSAGES);

        // Walk newest→oldest accumulating until the budget is spent.
        $kept  = [];
        $spent = 0;
        for ($i = count($history) - 1; $i >= 0; $i--) {
            $len = mb_strlen($history[$i]['content']);
            if ($spent + $len > self::HISTORY_CHAR_BUDGET && $kept !== []) {
                break;
            }
            $spent += $len;
            $kept[] = $history[$i];
        }
        $kept = array_reverse($kept);

        // Two Gemini shape constraints, both learned the hard way:
        //
        // 1. Since P5, Aisha initiates — feed deliveries land as several model
        //    rows back to back. Consecutive same-role contents are exactly the
        //    kind of proto-shape trap that 400'd us on 16 Aug (role-user rule),
        //    so merge runs of the same role into one content instead of finding
        //    out live.
        $merged = [];
        foreach ($kept as $m) {
            $last = count($merged) - 1;
            if ($last >= 0 && $merged[$last]['role'] === $m['role']) {
                $merged[$last]['content'] .= "\n\n" . $m['content'];
            } else {
                $merged[] = $m;
            }
        }

        // 2. The FIRST content must have role 'user' (found live, 16 Aug: every
        //    post-greeting turn 400'd). The old fix DROPPED leading model
        //    messages — tolerable when that was one greeting, but post-P5 the
        //    head of history is often a whole run of Aisha-initiated nudges,
        //    and dropping them meant she had no memory of what she just told
        //    the agent ("which booking did you mention?" → blank). A tiny
        //    synthetic user turn keeps the API happy AND keeps her own words
        //    in context. Prompt scaffolding only — never stored.
        if ($merged !== [] && $merged[0]['role'] === 'model') {
            array_unshift($merged, ['role' => 'user', 'content' => '[conversation resumes]']);
        }
        $kept = $merged;

        $hits = 0;
        $out  = array_map(static function (array $m) use (&$hits): array {
            [$clean, $h] = self::scrub((string) $m['content']);
            $hits += $h;
            return [
                'role'  => $m['role'] === 'model' ? 'model' : 'user',
                'parts' => [['text' => $clean]],
            ];
        }, $kept);

        if ($hits > 0) {
            ErrorLogService::log(
                'warning',
                "[BuddyScrub] {$hits} PII pattern(s) scrubbed from conversation history — "
                . 'something reached the transcript without passing an input scrub.'
            );
        }

        return $out;
    }

    // =========================================================================
    // SCRUBBER
    // =========================================================================

    /**
     * Scrub one string. Returns [scrubbedString, hitCount].
     */
    public static function scrub(string $text): array
    {
        $hits = 0;

        // 1) Card numbers: digit runs of 13–19 (with optional single space/dash
        //    separators) that pass Luhn.
        $text = preg_replace_callback(
            '/(?<![\d])(?:\d[ -]?){12,18}\d(?![\d])/',
            static function (array $m) use (&$hits): string {
                $digits = preg_replace('/\D/', '', $m[0]);
                $len = strlen($digits);
                if ($len < 13 || $len > 19 || !self::luhn($digits)) {
                    return $m[0];
                }
                $hits++;
                return '[card-redacted]';
            },
            $text
        ) ?? $text;

        // 2) Emails.
        $text = preg_replace_callback(
            '/[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}/',
            static function () use (&$hits): string {
                $hits++;
                return '[email-redacted]';
            },
            $text
        ) ?? $text;

        // 3) Phone-like runs: 10–15 digits, optional +, single separators.
        //    Date/datetime strings ("2026-08-16 17:36") also contain 10+ digits
        //    with the same separators — tool results are FULL of timestamps, so
        //    anything shaped like an ISO date is exempt.
        $text = preg_replace_callback(
            '/(?<![\d\w])\+?(?:\d[ -]?){9,14}\d(?![\d])/',
            static function (array $m) use (&$hits): string {
                if (preg_match('/\d{4}-\d{2}-\d{2}/', $m[0])) {
                    return $m[0];   // looks like a date, not a phone
                }
                $digits = preg_replace('/\D/', '', $m[0]);
                $len = strlen($digits);
                if ($len < 10 || $len > 15) {
                    return $m[0];
                }
                $hits++;
                return '[phone-redacted]';
            },
            $text
        ) ?? $text;

        return [$text, $hits];
    }

    /**
     * Recursively scrub every string leaf of an array (tool results). Logs a
     * warning per call site if anything was caught — a hit here means a tool
     * returned data it should have whitelisted away.
     */
    public static function scrubArray(array $data, string $context = ''): array
    {
        $total = 0;
        $walk = function ($v) use (&$walk, &$total) {
            if (is_array($v)) {
                return array_map($walk, $v);
            }
            if (is_string($v)) {
                [$s, $h] = self::scrub($v);
                $total += $h;
                return $s;
            }
            return $v;
        };
        $data = $walk($data);

        if ($total > 0) {
            ErrorLogService::log(
                'warning',
                "[BuddyScrub] {$total} PII pattern(s) scrubbed" . ($context !== '' ? " in {$context}" : '')
                . ' — a tool returned data it should have whitelisted away.'
            );
        }
        return $data;
    }

    private static function luhn(string $digits): bool
    {
        $sum = 0;
        $alt = false;
        for ($i = strlen($digits) - 1; $i >= 0; $i--) {
            $d = (int) $digits[$i];
            if ($alt) {
                $d *= 2;
                if ($d > 9) {
                    $d -= 9;
                }
            }
            $sum += $d;
            $alt = !$alt;
        }
        return $sum % 10 === 0;
    }
}
