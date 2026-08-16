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

    /** Max messages of history regardless of size. */
    public const HISTORY_MAX_MESSAGES = 12;

    // =========================================================================
    // HISTORY
    // =========================================================================

    /**
     * Convert stored messages (oldest→newest, each ['role'=>'user'|'model',
     * 'content'=>string]) into Gemini contents, dropping oldest first when over
     * budget. The current user turn is appended by the caller.
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

        // Gemini requires the FIRST content to have role 'user'. Our history
        // legitimately starts with a model message whenever the business-day
        // greeting opened the conversation — sending that verbatim made every
        // post-greeting turn 400 while a fresh conversation worked (found live
        // on production, 16 Aug). Drop leading model messages; their substance
        // (the agent's numbers) is always re-derivable through tools.
        while ($kept !== [] && $kept[0]['role'] === 'model') {
            array_shift($kept);
        }

        return array_map(static fn(array $m) => [
            'role'  => $m['role'] === 'model' ? 'model' : 'user',
            'parts' => [['text' => $m['content']]],
        ], $kept);
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
