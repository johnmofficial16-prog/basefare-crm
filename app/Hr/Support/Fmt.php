<?php

namespace App\Hr\Support;

/**
 * Plain-English formatting for sentences the server writes ("Needs you",
 * the activity feed, emails). Dates in words, Indian digit grouping.
 */
final class Fmt
{
    /** "Tue 3 Nov", with the year only when it isn't this year. */
    public static function day(?string $date): string
    {
        if (!$date) {
            return '';
        }
        $ts = strtotime(substr($date, 0, 10) . ' 12:00:00');
        return date('Y', $ts) === date('Y') ? date('D j M', $ts) : date('D j M Y', $ts);
    }

    /** "7:40 pm" */
    public static function time(?string $dt): string
    {
        return $dt ? date('g:i a', strtotime($dt)) : '';
    }

    /** ₹1,23,456 (rupees, no paise unless present). */
    public static function rupees(float|int $amount): string
    {
        $neg = $amount < 0;
        $amount = abs($amount);
        $whole = (string) (int) floor($amount);
        $paise = (int) round(($amount - floor($amount)) * 100);
        if (strlen($whole) > 3) {
            $last3 = substr($whole, -3);
            $rest = substr($whole, 0, -3);
            $rest = preg_replace('/\B(?=(\d{2})+(?!\d))/', ',', $rest);
            $whole = $rest . ',' . $last3;
        }
        return ($neg ? '− ' : '') . '₹' . $whole . ($paise ? '.' . str_pad((string) $paise, 2, '0', STR_PAD_LEFT) : '');
    }

    /** Words that keep their capital mid-sentence (acronyms like PAN keep theirs anyway). */
    private const NAMES = ['Aadhaar', 'Trio', 'India', 'Mohali', 'Punjab', 'Jamshedpur', 'Jharkhand'];

    private static function lowerWord(string $w): string
    {
        return (mb_strlen($w) > 1 && mb_strtoupper($w) === $w) || in_array($w, self::NAMES, true) ? $w : mb_strtolower($w);
    }

    /** One of our labels mid-sentence: "Experience certificate" → "experience certificate", "Aadhaar card" and "PAN" stay. */
    public static function lower(string $label): string
    {
        return preg_replace_callback('/\p{L}[\p{L}\p{M}]*/u', static fn(array $m): string => self::lowerWord($m[0]), $label) ?? $label;
    }

    /** Someone's own words mid-sentence: only an ordinary first word is lowered ("Traffic near Kharar" → "traffic near Kharar"). */
    public static function lowerFirst(string $text): string
    {
        return preg_replace_callback('/^\P{L}*\K\p{L}[\p{L}\p{M}]*/u', static fn(array $m): string => self::lowerWord($m[0]), $text, 1) ?? $text;
    }

    /** "an experience certificate", "a salary certificate", "a PAN card", "an SMS". */
    public static function an(string $phrase): string
    {
        $first = (string) strtok($phrase, ' ');
        $acronym = mb_strlen($first) > 1 && mb_strtoupper($first) === $first;
        $vowel = $acronym ? (bool) preg_match('/^[AEFHILMNORSX]/', $first) : (bool) preg_match('/^[aeiou]/i', $first);
        return ($vowel ? 'an ' : 'a ') . $phrase;
    }

    public static function plural(int|float $n, string $one, ?string $many = null): string
    {
        $num = (float) $n == (int) $n ? (string) (int) $n : rtrim(rtrim(number_format((float) $n, 1), '0'), '.');
        return $num . ' ' . ((float) $n == 1.0 ? $one : ($many ?? $one . 's'));
    }

    /** "today", "yesterday", "3 days ago", "Tue 3 Nov" (older than a week). */
    public static function ago(?string $dt): string
    {
        if (!$dt) {
            return '';
        }
        $days = Clock::daysBetween(substr($dt, 0, 10), date('Y-m-d'));
        return match (true) {
            $days <= 0 => 'today',
            $days === 1 => 'yesterday',
            $days < 7 => $days . ' days ago',
            default => self::day($dt),
        };
    }

    public static function initials(string $name): string
    {
        $parts = preg_split('/\s+/u', trim($name)) ?: [];
        $first = mb_substr($parts[0] ?? '', 0, 1);
        $last = count($parts) > 1 ? mb_substr((string) end($parts), 0, 1) : '';
        return mb_strtoupper($first . $last);
    }

    /** First name, for greetings and sentences. */
    public static function first(string $name): string
    {
        return preg_split('/\s+/u', trim($name))[0] ?? $name;
    }

    /** "1 yr 4 mo", "7 mo", "3 weeks" */
    public static function tenure(?string $joined, ?string $until = null): string
    {
        if (!$joined) {
            return '';
        }
        $a = new \DateTimeImmutable($joined);
        $b = new \DateTimeImmutable($until ?: 'today');
        if ($b < $a) {
            return 'joins ' . self::day($joined);
        }
        $d = $a->diff($b);
        if ($d->y > 0) {
            return $d->y . ' yr' . ($d->m ? ' ' . $d->m . ' mo' : '');
        }
        if ($d->m > 0) {
            return $d->m . ' mo';
        }
        $w = intdiv($d->days, 7);
        return $w > 0 ? self::plural($w, 'week') : self::plural($d->days, 'day');
    }
}
