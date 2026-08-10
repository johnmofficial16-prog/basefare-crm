<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Database\Capsule\Manager as Capsule;

/**
 * PerformanceHold — hides a window of activity from the Performance scoreboard.
 *
 * Context (August 2026): the merchant placed a hold on the month's sales to date.
 * The client wants the Performance tab to read as though the month starts fresh,
 * so agents are not scored on takings they may never be credited for.
 *
 * DESIGN CONSTRAINT, stated by the client and enforced here:
 * this NEVER writes to any record. No transaction is flagged, voided, backdated
 * or deleted; no acceptance is altered. Transactions, Acceptances, Invoices,
 * E-Tickets, Dashboard, Live Board, Centre Analytics, Chargebacks and every
 * report keep showing the true figures. This is a read-time filter applied to
 * the Performance module alone, so the numbers come straight back when the
 * merchant releases the hold.
 *
 * Mechanism: a held window [from, until] is EXCLUDED from Performance queries
 * for every role except admin.
 *
 * Excluding a window — rather than clamping the query start — matters: an agent
 * can switch the Performance page to July, a custom range or "Till Date". A
 * clamped start would erase July and all prior history along with the held days.
 * Excluding the window leaves everything outside it untouched.
 *
 * Config lives in `system_config`:
 *   performance.hold_enabled  '1' to activate
 *   performance.hold_from     'Y-m-d H:i:s' first held moment
 *   performance.hold_until    'Y-m-d H:i:s' last held moment (inclusive)
 *   performance.hold_notice   banner text shown to non-admins
 *
 * To lift the hold: set performance.hold_enabled to '0'. Every figure returns
 * immediately — nothing to backfill, because nothing was ever changed.
 */
class PerformanceHold
{
    /** Per-request cache so a page render costs at most one config query. */
    private static ?array $cfg = null;

    public static function config(): array
    {
        if (self::$cfg === null) {
            $rows = [];
            try {
                $rows = Capsule::table('system_config')
                    ->whereIn('key', [
                        'performance.hold_enabled',
                        'performance.hold_from',
                        'performance.hold_until',
                        'performance.hold_notice',
                        'performance.hold_exempt_refs',
                    ])
                    ->pluck('value', 'key')
                    ->toArray();
            } catch (\Throwable $e) {
                // Missing table/keys must never break the page — just no hold.
                error_log('[PerformanceHold] config read failed: ' . $e->getMessage());
                $rows = [];
            }

            $refs = json_decode((string) ($rows['performance.hold_exempt_refs'] ?? '[]'), true);
            $refs = is_array($refs) ? $refs : [];
            $refs = array_values(array_filter(array_map(
                fn($r) => strtoupper(trim((string) $r)),
                $refs
            )));

            self::$cfg = [
                'enabled' => (string) ($rows['performance.hold_enabled'] ?? '0') === '1',
                'from'    => $rows['performance.hold_from']   ?? null,
                'until'   => $rows['performance.hold_until']  ?? null,
                'notice'  => $rows['performance.hold_notice'] ?? null,
                'refs'    => $refs,
            ];
        }
        return self::$cfg;
    }

    /**
     * Whether the hold applies to this viewer. Admins always see the real
     * numbers, so finance and management retain a true picture.
     */
    public static function appliesTo(?string $role = null): bool
    {
        $role = $role ?? ($_SESSION['role'] ?? '');
        $c = self::config();

        if (!$c['enabled'] || empty($c['from']) || empty($c['until'])) {
            return false;
        }
        return $role !== User::ROLE_ADMIN;
    }

    /**
     * Exclude the held window from a query, for non-admin viewers.
     *
     * @param  mixed  $query   Eloquent builder
     * @param  string $column  Date column to filter on (created_at, approved_at, ...)
     * @return mixed  The same builder, so this can be chained inline.
     */
    public static function apply($query, string $column, ?string $role = null)
    {
        if (!self::appliesTo($role)) {
            return $query;
        }
        $c    = self::config();
        $refs = $c['refs'] ?? [];

        // A row survives the hold if ANY of these hold true:
        //   1. its date is NULL — a bare NOT BETWEEN evaluates to NULL and would
        //      silently drop rows the scoreboard used to count;
        //   2. it falls outside the held window;
        //   3. its booking reference is on the exempt list — bookings the client
        //      confirmed were charged through a different merchant and so are not
        //      affected by the hold.
        //
        // Exemptions are matched on pnr OR order_id, uppercased and trimmed on
        // both sides, because the client's list may quote either. Only applied to
        // Transaction queries (the sole caller), which carry both columns.
        return $query->where(function ($q) use ($column, $c, $refs) {
            $q->whereNull($column)
              ->orWhereNotBetween($column, [$c['from'], $c['until']]);

            foreach ($refs as $ref) {
                $q->orWhereRaw('FIND_IN_SET(?, ' . self::normalisedRefExpr('pnr') . ') > 0', [$ref])
                  ->orWhereRaw('FIND_IN_SET(?, ' . self::normalisedRefExpr('order_id') . ') > 0', [$ref]);
            }
        });
    }

    /**
     * SQL expression turning a reference column into a comma-separated token list
     * so FIND_IN_SET can match a whole reference rather than a loose substring.
     *
     * Agents sometimes record two PNRs in the single `pnr` field, and not
     * consistently: production holds both "ZE5FSW | ZELHY3" and "YEBGZH/YDT5LZ".
     * Exact equality misses both. A bare LIKE '%REF%' would match them, but would
     * also match a reference that merely appears inside a longer code — which on
     * a scoreboard means silently crediting the wrong booking.
     *
     * So: strip spaces, convert | / ; and backslash to commas, then match tokens.
     *
     * @param string $col Column name — caller-supplied constant, never user input.
     */
    private static function normalisedRefExpr(string $col): string
    {
        $u = "UPPER(TRIM(COALESCE(`{$col}`, '')))";
        $u = "REPLACE($u, ' ', '')";
        foreach (['|', '/', ';', '\\\\'] as $sep) {
            $u = "REPLACE($u, '{$sep}', ',')";
        }
        return $u;
    }

    /** Banner text for non-admin viewers, or null when no hold is in force. */
    public static function notice(?string $role = null): ?string
    {
        if (!self::appliesTo($role)) {
            return null;
        }
        $n = trim((string) (self::config()['notice'] ?? ''));
        return $n !== '' ? $n : null;
    }

    /** "10 Aug 2026" — the date scoring resumes, for use in copy. */
    public static function resumesOn(): ?string
    {
        $c = self::config();
        if (empty($c['until'])) {
            return null;
        }
        $ts = strtotime($c['until']);
        return $ts ? date('j M Y', $ts + 1) : null;
    }

    /** Clear the cache — only needed if config changes mid-request. */
    public static function flush(): void
    {
        self::$cfg = null;
    }
}
