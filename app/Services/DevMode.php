<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Database\Capsule\Manager as DB;

/**
 * The developer account's view switcher (John's brief, 26 Oct 2026).
 *
 * A user with users.is_dev = 1 (role admin underneath) can view the CRM as any
 * real agent, team leader or manager, as admin, or in dev view. Viewing as
 * someone swaps the session identity (user_id, role, name…) so every page and
 * scope behaves exactly as it does for them; $_SESSION['dev'] keeps who is
 * really signed in. Actions are allowed and every one is logged as
 * "dev acting as …" (DevAuditMiddleware).
 *
 * The person being viewed is never touched: their own session, clock-in,
 * theme, survey and "one session at a time" rule are left alone
 * (AuthMiddleware / AttendanceGateMiddleware skip those for a dev session).
 */
final class DevMode
{
    /** view key => [label, CRM role of the person viewed] */
    public const VIEWS = [
        'dev'        => ['Dev', null],
        'admin'      => ['Admin', null],
        'manager'    => ['Manager', User::ROLE_MANAGER],
        'supervisor' => ['Team leader', User::ROLE_SUPERVISOR],
        'agent'      => ['Agent', User::ROLE_AGENT],
    ];

    /** Session keys that belong to whoever the CRM thinks is signed in. */
    private const IDENTITY_KEYS = [
        'user_id', 'role', 'user_name', 'name', 'user_email', 'theme', 'simple_mode', 'simple_available',
        'buddy_greet_due', 'staff_survey_due', 'staff_survey_more', 'staff_survey_done',
        'must_change_password', 'active_session_id', '_acct_checked_at',
    ];

    public static function active(): bool
    {
        return !empty($_SESSION['dev']['id']);
    }

    public static function devId(): ?int
    {
        return self::active() ? (int) $_SESSION['dev']['id'] : null;
    }

    /** The person being viewed, or null in dev/admin view. */
    public static function actingAs(): ?int
    {
        return self::active() && !empty($_SESSION['dev']['as']) ? (int) $_SESSION['dev']['as'] : null;
    }

    public static function view(): string
    {
        return (string) ($_SESSION['dev']['view'] ?? 'dev');
    }

    /** Called when a dev account finishes signing in. */
    public static function begin(User $dev): void
    {
        $_SESSION['dev'] = ['id' => (int) $dev->id, 'name' => (string) $dev->name, 'view' => 'dev', 'as' => null,
                            'checked' => time(), 'theme' => $dev->theme ?? null];
        // Dev sessions don't get Aisha's arrival greeting or the staff survey.
        $_SESSION['buddy_greet_due'] = false;
        $_SESSION['staff_survey_due'] = false;
    }

    /** Re-check (at most once a minute) that the real signed-in person is still an active developer. */
    public static function stillValid(): bool
    {
        if (!self::active()) {
            return true;
        }
        if (time() - (int) ($_SESSION['dev']['checked'] ?? 0) < 60) {
            return true;
        }
        $ok = DB::table('users')->where('id', self::devId())->whereNull('deleted_at')
            ->where('status', User::STATUS_ACTIVE)->where('is_dev', 1)->exists();
        $_SESSION['dev']['checked'] = time();
        return $ok;
    }

    /** Is this signed-in user a developer (straight from the database)? */
    public static function isDevUser(int $userId): bool
    {
        return DB::table('users')->where('id', $userId)->whereNull('deleted_at')
            ->where('status', User::STATUS_ACTIVE)->where('is_dev', 1)->exists();
    }

    /** People who can be viewed as, by view. Developers are never in the lists. */
    public static function people(string $view): array
    {
        $role = self::VIEWS[$view][1] ?? null;
        if ($role === null) {
            return [];
        }
        return DB::table('users as u')->leftJoin('users as m', 'm.id', '=', 'u.reports_to_id')
            ->where('u.role', $role)->where('u.status', User::STATUS_ACTIVE)->whereNull('u.deleted_at')->where('u.is_dev', 0)
            ->orderBy('u.name')->get(['u.id', 'u.name', 'u.email', 'm.name as reports_to'])->all();
    }

    /**
     * Switch view. For agent / team leader / manager a real person must be
     * chosen; admin and dev are the developer's own account.
     *
     * @return string|null an error message, or null when switched
     */
    public static function switchTo(string $view, ?int $personId = null): ?string
    {
        if (!self::active() || !isset(self::VIEWS[$view])) {
            return 'That view isn\'t available.';
        }
        $role = self::VIEWS[$view][1];
        if ($role === null) {
            self::restoreSelf($view);
            self::log('dev_view', ['view' => $view]);
            return null;
        }
        $person = User::whereNull('deleted_at')->where('status', User::STATUS_ACTIVE)->where('is_dev', 0)
            ->where('role', $role)->find((int) $personId);
        if (!$person) {
            return 'Pick someone from the list.';
        }
        foreach (self::IDENTITY_KEYS as $k) {
            unset($_SESSION[$k]);
        }
        $_SESSION['user_id']   = (int) $person->id;
        $_SESSION['role']      = $person->role;
        $_SESSION['user_name'] = $person->name;
        $_SESSION['theme']     = Theme::normalise($_SESSION['dev']['theme'] ?? null);
        $_SESSION['simple_mode'] = false;
        $_SESSION['buddy_greet_due'] = false;
        $_SESSION['staff_survey_due'] = false;
        $_SESSION['last_activity'] = time();
        $_SESSION['_acct_checked_at'] = time();
        $_SESSION['dev']['view'] = $view;
        $_SESSION['dev']['as'] = (int) $person->id;
        $_SESSION['dev']['as_name'] = (string) $person->name;
        self::log('dev_view_as', ['view' => $view, 'as_user_id' => (int) $person->id, 'as_name' => $person->name]);
        return null;
    }

    /** Back to the developer's own account (dev or admin view). */
    public static function restoreSelf(string $view = 'dev'): void
    {
        $dev = User::find(self::devId());
        if (!$dev) {
            return;
        }
        foreach (self::IDENTITY_KEYS as $k) {
            unset($_SESSION[$k]);
        }
        $_SESSION['user_id']   = (int) $dev->id;
        $_SESSION['role']      = $dev->role;
        $_SESSION['user_name'] = $dev->name;
        $_SESSION['theme']     = Theme::normalise($dev->theme ?? null);
        $_SESSION['simple_mode'] = false;
        $_SESSION['simple_available'] = false;
        $_SESSION['buddy_greet_due'] = false;
        $_SESSION['staff_survey_due'] = false;
        $_SESSION['active_session_id'] = session_id();
        $_SESSION['last_activity'] = time();
        $_SESSION['_acct_checked_at'] = time();
        $_SESSION['dev']['view'] = $view === 'admin' ? 'admin' : 'dev';
        $_SESSION['dev']['as'] = null;
        unset($_SESSION['dev']['as_name']);
    }

    public static function log(string $action, array $details = []): void
    {
        try {
            DB::table('activity_log')->insert([
                'user_id'     => self::devId(),
                'action'      => $action,
                'entity_type' => 'dev',
                'entity_id'   => self::actingAs(),
                'details'     => json_encode($details),
                'ip_address'  => $_SERVER['REMOTE_ADDR'] ?? null,
                'created_at'  => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $e) {
            error_log('[DevMode] activity_log insert failed: ' . $e->getMessage());
        }
    }

    /**
     * The floating dev bar shown on every CRM page while a dev is signed in:
     * which view, who is being viewed, and one click back.
     */
    public static function bar(): string
    {
        if (!self::active()) {
            return '';
        }
        $e = fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
        $view = self::view();
        $label = self::VIEWS[$view][0] ?? 'Dev';
        $as = self::actingAs() ? ' · viewing as <b>' . $e($_SESSION['dev']['as_name'] ?? '') . '</b>' : '';
        $csrf = $e($_SESSION['csrf_token'] ?? '');
        $back = self::actingAs() || $view !== 'dev'
            ? '<form method="POST" action="/dev/view" style="margin:0"><input type="hidden" name="csrf_token" value="' . $csrf . '">'
              . '<input type="hidden" name="view" value="dev"><button type="submit" class="bf-devbar-btn">Back to dev</button></form>'
            : '';
        return '<div class="bf-devbar" role="status" aria-live="polite">'
            . '<span class="bf-devbar-tag">DEV</span><span>' . $e($label) . ' view' . $as . '</span>'
            . '<a class="bf-devbar-btn" href="/dev">Switch</a>' . $back . '</div>'
            . '<style>.bf-devbar{position:fixed;left:50%;bottom:14px;transform:translateX(-50%);z-index:2147483000;display:flex;align-items:center;gap:10px;'
            . 'padding:8px 10px 8px 12px;border-radius:999px;background:#1F1E1B;color:#F6F4EF;font:500 14px/1.2 Inter,system-ui,sans-serif;'
            . 'box-shadow:0 8px 28px -8px rgba(0,0,0,.45);border:1px solid rgba(255,255,255,.12)}'
            . '.bf-devbar b{font-weight:600;color:#fff}.bf-devbar-tag{font:700 11px/1 Inter,system-ui;letter-spacing:.08em;padding:5px 7px;border-radius:6px;background:#C2410C;color:#fff}'
            . '.bf-devbar-btn{all:unset;cursor:pointer;padding:6px 12px;border-radius:999px;background:rgba(255,255,255,.12);color:#fff;font:600 13px/1 Inter,system-ui}'
            . '.bf-devbar-btn:hover{background:rgba(255,255,255,.22)}.bf-devbar-btn:focus-visible{outline:2px solid #9CC3FF;outline-offset:2px}'
            . '@media print{.bf-devbar{display:none}}</style>';
    }
}
