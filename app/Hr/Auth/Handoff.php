<?php

namespace App\Hr\Auth;

use App\Hr\Http\ApiError;
use App\Hr\Kernel;
use App\Hr\Support\Audit;
use App\Hr\Support\Fmt;

/**
 * One click from the CRM into Trio HR, without a second password for staff.
 *
 * The CRM (already signed in, already IP-restricted) signs a one-time link:
 *   base64url(json{v,u,n,e}) . "." . base64url(HMAC-SHA256(payload, HR_HANDOFF_KEY))
 * with u = CRM user id, n = random id, e = expiry (60 seconds). The token
 * travels in the URL fragment, so it never reaches a server log; the HR page
 * posts it here.
 *
 * Trio HR checks the signature and expiry, refuses a link used before (the
 * random id is stored), and then opens its OWN session. The CRM session is
 * never read or touched (doing so would clock agents out).
 *
 * Owners and admins are never signed in this way: pay and ID data sit behind
 * their Trio HR password. Staff who were invited but never set a password are
 * let in (their CRM login vouches for them) and can set one later for their
 * phone. Leavers and switched-off accounts are refused.
 */
final class Handoff
{
    public const TTL = 60;

    private static function key(): ?string
    {
        $k = (string) Kernel::env('HR_HANDOFF_KEY', '');
        return strlen($k) >= 32 ? $k : null;
    }

    public static function enabled(): bool
    {
        return self::key() !== null;
    }

    private static function b64(string $s): string
    {
        return rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
    }

    private static function unb64(string $s): string|false
    {
        return base64_decode(strtr($s, '-_', '+/') . str_repeat('=', (4 - strlen($s) % 4) % 4), true);
    }

    /** Make a link token (the CRM side does the same; kept here for tests and the shared format). */
    public static function make(int $crmUserId, string $key, ?int $now = null): string
    {
        $payload = self::b64(json_encode(['v' => 1, 'u' => $crmUserId, 'n' => bin2hex(random_bytes(16)), 'e' => ($now ?? time()) + self::TTL]));
        return $payload . '.' . self::b64(hash_hmac('sha256', $payload, $key, true));
    }

    private const MAX_FAILS = 30;      // bad links per network per 15 minutes
    private const FAIL_WINDOW = 900;

    /** Check a token and return the HR account to sign in. */
    public static function accept(string $token, string $ip): object
    {
        $key = self::key();
        $db = Kernel::hr();
        $fail = function () use ($db, $ip) {
            // Counted per network (not audited one by one, so nobody can flood the audit log).
            $db->table('hr_login_attempts')->insert(['ip' => substr($ip, 0, 45), 'email' => null, 'ok' => 0, 'kind' => 'handoff', 'at' => Kernel::now()]);
            return new ApiError(401, 'handoff', 'That link from the CRM has expired or was already used. Open Trio HR from the CRM again, or sign in below.');
        };
        if ($key === null) {
            throw new ApiError(404, 'handoff_off', 'Opening Trio HR from the CRM is not switched on. Please sign in.');
        }
        $recent = $db->table('hr_login_attempts')->where('ip', $ip)->where('kind', 'handoff')->where('ok', 0)
            ->where('at', '>=', date('Y-m-d H:i:s', time() - self::FAIL_WINDOW))->count();
        if ($recent >= self::MAX_FAILS) {
            throw new ApiError(429, 'throttled', 'Too many broken links from this network. Please wait 15 minutes, or sign in with your password.');
        }
        $parts = explode('.', $token);
        if (count($parts) !== 2 || strlen($token) > 400) {
            throw $fail();
        }
        [$payload, $sig] = $parts;
        $mac = self::unb64($sig);
        if ($mac === false || !hash_equals(hash_hmac('sha256', $payload, $key, true), $mac)) {
            throw $fail();
        }
        $data = json_decode((string) self::unb64($payload), true);
        if (!is_array($data) || ($data['v'] ?? 0) !== 1 || !is_int($data['u'] ?? null) || !is_string($data['n'] ?? null)
            || !preg_match('/^[a-f0-9]{32}$/', $data['n']) || !is_int($data['e'] ?? null)) {
            throw $fail();
        }
        if ($data['e'] < time() || $data['e'] > time() + self::TTL + 5) {
            throw $fail();
        }
        // One use only: the random id goes into a table with it as the primary key.
        $db->table('hr_handoffs')->where('used_at', '<', date('Y-m-d H:i:s', time() - 86400))->delete();
        try {
            $db->table('hr_handoffs')->insert(['nonce' => $data['n'], 'crm_user_id' => $data['u'], 'used_at' => Kernel::now()]);
        } catch (\Illuminate\Database\QueryException) {
            throw $fail();
        }

        $crm = Kernel::crm()->table('users')->where('id', $data['u'])->first(['id', 'status', 'deleted_at', 'email', 'name', 'is_dev']);
        if (!$crm || $crm->status !== 'active' || $crm->deleted_at) {
            throw new ApiError(403, 'handoff_user', 'Your CRM login is not active, so Trio HR can\'t open from it. Please speak to the HR admin.');
        }

        // The CRM developer account (John's brief, 26 Oct 2026): opens Trio HR as an
        // owner, with no staff record and no separate password. Safe because the CRM
        // already required its password AND app code. Not on the staff list.
        if (!empty($crm->is_dev)) {
            $email = mb_strtolower((string) $crm->email);
            $account = $db->table('hr_accounts')->where('email', $email)->first();
            if (!$account) {
                $id = $db->table('hr_accounts')->insertGetId([
                    'employee_id' => null, 'display_name' => trim((string) $crm->name) ?: 'Developer', 'email' => $email,
                    'role' => 'owner', 'status' => 'active', 'created_at' => Kernel::now(), 'updated_at' => Kernel::now(),
                ]);
                $account = $db->table('hr_accounts')->where('id', $id)->first();
                Audit::log('account.create', ['account_id' => (int) $id, 'summary' => 'Developer account opened Trio HR from the CRM for the first time']);
            }
            if ($account->employee_id !== null || $account->status !== 'active') {
                throw new ApiError(403, 'handoff_dev', 'This developer sign-in is switched off in Trio HR.');
            }
            Audit::log('signin.crm', ['account_id' => (int) $account->id, 'summary' => 'Developer opened Trio HR from the CRM']);
            $account->dev = true;
            return $account;
        }
        $e = $db->table('hr_employees')->where('crm_user_id', $crm->id)->first(['id', 'full_name', 'work_email', 'personal_email', 'status']);
        if (!$e) {
            throw new ApiError(403, 'handoff_unlinked', "You're not on Trio HR yet. Please ask the HR admin to add you.");
        }
        $account = $db->table('hr_accounts')->where('employee_id', $e->id)->first();
        if ($account && in_array($account->role, ['owner', 'admin'], true)) {
            throw new ApiError(403, 'handoff_admin', 'Admins sign in to Trio HR with their own password, to keep pay and ID details safe.');
        }
        if ($account && $account->status === 'disabled') {
            throw new ApiError(403, 'disabled', 'Your Trio HR sign-in has been switched off. Please speak to the HR admin.');
        }
        if ($e->status === 'left' || ($account && (Auth::leaver($account->employee_id)['ended'] ?? false))) {
            throw new ApiError(403, 'handoff_left', 'Trio HR only opens from the CRM for current staff. Please sign in with your email and password.');
        }
        if (!$account) {
            // No Trio HR account yet: make one, on the work email HR holds for them (never an
            // address only the CRM knows: whoever controls that could later reset the password).
            $email = mb_strtolower((string) $e->work_email);
            if ($email === '' || $db->table('hr_accounts')->where('email', $email)->exists()) {
                throw new ApiError(409, 'handoff_email', 'Trio HR needs an email address for you first. Please ask the HR admin.');
            }
            $id = $db->table('hr_accounts')->insertGetId([
                'employee_id' => $e->id, 'display_name' => $e->full_name, 'email' => $email, 'role' => 'employee',
                'status' => 'active', 'created_at' => Kernel::now(), 'updated_at' => Kernel::now(),
            ]);
            $account = $db->table('hr_accounts')->where('id', $id)->first();
            Audit::log('account.create', ['account_id' => (int) $id, 'employee_id' => (int) $e->id, 'summary' => Fmt::first($e->full_name) . ' opened Trio HR from the CRM for the first time']);
        } elseif ($account->status === 'invited') {
            $db->table('hr_accounts')->where('id', $account->id)->update(['status' => 'active']);
            $account->status = 'active';
        }
        Audit::log('signin.crm', ['account_id' => (int) $account->id, 'employee_id' => (int) $e->id, 'summary' => 'Opened from the CRM']);
        return $account;
    }
}
