<?php

namespace App\Hr\Support;

use App\Hr\Kernel;
use Throwable;

/**
 * Shift-day arithmetic. Trio's floor works nights, so "today" is the work
 * night that starts at the CRM's business-day rollover hour (default 18:00
 * IST), not the calendar date. A clock-in at 02:00 belongs to the night that
 * began at 18:00 the evening before. Mirrors ShiftService::businessDayBounds().
 */
final class Clock
{
    private static ?int $hour = null;

    public static function rolloverHour(): int
    {
        if (self::$hour === null) {
            try {
                $v = Kernel::crm()->table('system_config')->where('key', 'business_day_start_hour')->value('value');
            } catch (Throwable) {
                $v = null;
            }
            self::$hour = max(0, min(23, $v === null ? 18 : (int) $v));
        }
        return self::$hour;
    }

    /** The shift day a moment belongs to (Y-m-d). */
    public static function shiftDay(?int $ts = null): string
    {
        return date('Y-m-d', ($ts ?? time()) - self::rolloverHour() * 3600);
    }

    /** [start, end) of a shift day as DATETIME strings. */
    public static function window(string $day): array
    {
        $start = strtotime($day . ' 00:00:00') + self::rolloverHour() * 3600;
        return [date('Y-m-d H:i:s', $start), date('Y-m-d H:i:s', $start + 86400)];
    }

    /**
     * How early a night's clock-ins can start. The CRM lets people clock in
     * from shift start minus their grace, on that night's calendar day, and the
     * 24/7 roster's grace is 12 hours: so a night starting 18:00 takes
     * clock-ins from 06:00 that day until 06:00 the next morning.
     */
    private const EARLY_HOURS = 12;

    /** The night a CRM session belongs to, from its clock-in ("2026-10-13 17:45:00" → 2026-10-13). */
    public static function sessionNight(string $clockIn): string
    {
        return date('Y-m-d', strtotime($clockIn) - (self::rolloverHour() - self::EARLY_HOURS) * 3600);
    }

    /** [start, end) of the clock-ins that belong to a night. */
    public static function sessionWindow(string $day): array
    {
        $start = strtotime($day . ' 00:00:00') + (self::rolloverHour() - self::EARLY_HOURS) * 3600;
        return [date('Y-m-d H:i:s', $start), date('Y-m-d H:i:s', $start + 86400)];
    }

    public static function addDays(string $day, int $n): string
    {
        return date('Y-m-d', strtotime($day . ' 12:00:00') + $n * 86400);
    }

    /** Whole days from $from to $to (both Y-m-d). */
    public static function daysBetween(string $from, string $to): int
    {
        return (int) round((strtotime($to . ' 12:00:00') - strtotime($from . ' 12:00:00')) / 86400);
    }

    /** Every Y-m-d from $from to $to inclusive. */
    public static function range(string $from, string $to, int $cap = 400): array
    {
        $out = [];
        for ($d = $from; $d <= $to && count($out) < $cap; $d = self::addDays($d, 1)) {
            $out[] = $d;
        }
        return $out;
    }
}
