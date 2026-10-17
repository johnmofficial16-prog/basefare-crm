<?php

namespace App\Hr\Domain;

use App\Hr\Kernel;
use App\Hr\Support\Audit;

/**
 * Company settings kept as data (hr_settings), with safe defaults so a fresh
 * install works before anyone has filled them in.
 */
final class Settings
{
    private static array $cache = [];

    public const RULES = [
        'min_floor'           => ['Fewest people a night should have on the floor', 'Used by the coverage check on leave requests: everyone who clocks in on the CRM, minus approved leave.', 1, 500],
        'fix_window_days'     => ['Days staff have to fix a missed punch-out', 'After this, a team leader raises it with HR.', 1, 31],
        'days_off_per_month'  => ['Weekly offs a month', 'Paid days off allowed each month. At month end you mark no-punch days as weekly offs up to this number.', 0, 10],
        'no_punch_alert_days' => ['Flag no punch after this many days in a row', 'Shown on Today when someone has no punch and no leave asked. Nothing is sent to them automatically.', 1, 14],
        'bank_cooling_days'   => ['Days before a new bank account gets salary', 'A safety gap after a bank change is approved.', 0, 30],
        'stale_request_days'  => ['Days before a request turns amber', 'Twice this turns it red.', 1, 14],
        'roster_warning_days' => ["Warn when the CRM's roster ends within", "Days ahead of the last published night. The CRM's clock-in gate stops working without a roster.", 1, 30],
    ];

    private const DEFAULTS = [
        'company' => [
            'name'            => 'Trio Tours and Travels Pvt Ltd',
            'legal_name'      => 'TRIO TOURS & TRAVELS PRIVATE LIMITED',
            'cin'             => 'U63030PB2022PTC055278',
            'address'         => 'Office No. 51, Plot No. D-185, Prosperity Square, Phase 8, Industrial Area, Mohali, Punjab 160062',
            'tan'             => null,
            'pan'             => null,
            'signatory_name'  => null,
            'signatory_title' => null,
            'hr_email'        => 'hr@triotoursandtravels.in',
            'phone'           => null,
        ],
        // City/state for DMR and JSR still to be confirmed by the client (HR_PLAN.md §10).
        'centres' => [
            'MOH' => ['name' => 'Mohali', 'city' => 'Mohali', 'state' => 'Punjab', 'address' => null, 'shop_reg' => null],
            'DMR' => ['name' => 'DMR', 'city' => null, 'state' => null, 'address' => null, 'shop_reg' => null],
            'JSR' => ['name' => 'Jamshedpur', 'city' => 'Jamshedpur', 'state' => 'Jharkhand', 'address' => null, 'shop_reg' => null],
        ],
        'security' => [
            'staff_email_code'   => false, // optional: staff type an emailed code on a new phone (owner switch)
            'letters_need_check' => true,  // a second admin checks every letter before it is issued
        ],
        'min_floor'           => 20,
        'fix_window_days'     => 7,
        'days_off_per_month'  => 4,
        'no_punch_alert_days' => 3,
        'bank_cooling_days'   => 3,
        'stale_request_days'  => 2,
        'roster_warning_days' => 7,
    ];

    public static function get(string $key): mixed
    {
        if (!array_key_exists($key, self::$cache)) {
            $raw = Kernel::hr()->table('hr_settings')->where('key', $key)->value('value');
            $val = $raw !== null ? json_decode($raw, true) : null;
            $def = self::DEFAULTS[$key] ?? null;
            // Merge stored maps over defaults, so new keys appear without a migration.
            self::$cache[$key] = is_array($def) && is_array($val) && !array_is_list($def) ? array_replace_recursive($def, $val) : ($val ?? $def);
        }
        return self::$cache[$key];
    }

    public static function set(string $key, mixed $value, ?int $by): void
    {
        Kernel::hr()->table('hr_settings')->updateOrInsert(['key' => $key], [
            'value' => json_encode($value, JSON_UNESCAPED_UNICODE), 'updated_by' => $by, 'updated_at' => Kernel::now(),
        ]);
        unset(self::$cache[$key]);
        Audit::log('settings.update', ['entity' => 'hr_settings', 'detail' => ['key' => $key]]);
    }

    public static function security(string $flag): bool
    {
        return (bool) (self::get('security')[$flag] ?? false);
    }

    public static function centreName(?string $code): ?string
    {
        if ($code === null) {
            return null;
        }
        return self::get('centres')[$code]['name'] ?? $code;
    }
}
