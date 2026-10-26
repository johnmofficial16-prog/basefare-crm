<?php

namespace App\Hr\Auth;

use App\Hr\Http\ApiError;
use App\Hr\Http\Ctx;
use App\Hr\Kernel;
use App\Hr\Support\Audit;
use App\Hr\Support\Fmt;
use App\Hr\Support\Notify;
use App\Hr\Support\Outbox;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Trio HR's own sign-in. It never reads or writes CRM users or CRM sessions:
 * a CRM login moves users.active_session_id and the CRM then clocks the agent
 * out, so HR must stay completely separate.
 *
 *  - Passwords: Argon2id, lockout after 5 wrong tries, per-IP throttle.
 *  - Sessions: random 256-bit token in an HttpOnly, SameSite=Strict cookie;
 *    only its SHA-256 is stored. Admins time out after 30 idle minutes, staff
 *    (phones) after 14 idle days. Background polling doesn't count as activity.
 *  - Every state-changing request must carry the session's CSRF token.
 *  - Invites and password resets are single-use links whose token rides in
 *    the URL fragment, so it never reaches server logs or Referer headers.
 */
final class Auth
{
    private const ADMIN_IDLE = 30 * 60;
    private const ADMIN_MAX = 12 * 3600;
    private const STAFF_IDLE = 14 * 86400;
    private const STAFF_MAX = 30 * 86400;
    // Opened from the CRM (often a shared office PC): ends with the browser, after
    // 30 idle minutes, or after 12 hours — and only while the CRM login is still
    // active (security audit 26 Oct 2026).
    private const CRM_IDLE = 30 * 60;
    private const CRM_MAX = 12 * 3600;
    private const LOCK_AFTER = 5;
    private const LOCK_SECONDS = 15 * 60;
    private const IP_WINDOW = 15 * 60;
    // A whole office signs in from one public IP, so this is generous; each account still locks after 5 tries.
    private const IP_MAX_FAILS = 50;
    // Reset requests are counted separately, so asking for links can never block anyone from signing in.
    private const RESET_IP_MAX = 10;
    private const REAUTH_SECONDS = 5 * 60;
    public const MIN_PASSWORD = 10;

    // ---- cookie -------------------------------------------------------------

    public static function cookieName(): string
    {
        return Kernel::isLocal() ? 'trio_hr' : '__Host-trio_hr';
    }

    public static function cookie(string $value, int $maxAge): string
    {
        // A negative age = a browser-session cookie (gone when the browser closes)
        $parts = [self::cookieName() . '=' . $value, 'Path=/', 'HttpOnly', 'SameSite=Strict'];
        if ($maxAge >= 0) {
            $parts[] = 'Max-Age=' . $maxAge;
        }
        if (!Kernel::isLocal()) {
            $parts[] = 'Secure';
        }
        return implode('; ', $parts);
    }

    private static function isAdminRole(string $role): bool
    {
        return $role === 'owner' || $role === 'admin';
    }

    // ---- leavers ------------------------------------------------------------

    /**
     * Someone who has left keeps a read-only sign-in for 12 months after their
     * last working day, to see their letters, documents and payslips (HR_PLAN.md).
     *
     * @return array{last_day:string, until:string, ended:bool}|null  null when they haven't left
     */
    public static function leaver(mixed $employeeId): ?array
    {
        if (!$employeeId) {
            return null;
        }
        $e = Kernel::hr()->table('hr_employees')->where('id', (int) $employeeId)->first(['status', 'last_working_day']);
        if (!$e || $e->status !== 'left') {
            return null;
        }
        $last = (string) ($e->last_working_day ?: date('Y-m-d'));
        $until = date('Y-m-d', strtotime($last . ' +12 months'));
        return ['last_day' => $last, 'until' => $until, 'ended' => date('Y-m-d') > $until];
    }

    // ---- session ------------------------------------------------------------

    /** The signed-in session for this request, or null. */
    public static function resolve(Request $req): ?array
    {
        $token = $req->getCookieParams()[self::cookieName()] ?? null;
        if (!is_string($token) || !preg_match('/^[A-Za-z0-9_-]{43}$/', $token)) {
            return null;
        }
        $row = Kernel::hr()->table('hr_sessions as s')
            ->join('hr_accounts as a', 'a.id', '=', 's.account_id')
            ->where('s.token_hash', hash('sha256', $token))
            ->whereNull('s.revoked_at')
            ->first([
                's.id as session_id', 's.account_id', 's.csrf_token', 's.last_seen_at', 's.expires_at', 's.reauth_at', 's.via',
                'a.role', 'a.status', 'a.employee_id', 'a.display_name', 'a.email',
            ]);
        if (!$row || $row->status !== 'active') {
            return null;
        }
        $now = time();
        $idle = self::idleFor($row->role, (string) $row->via);
        if (strtotime($row->expires_at) <= $now || strtotime($row->last_seen_at) + $idle <= $now
            || ($row->via === 'crm' && !self::crmLoginActive($row->employee_id, (string) $row->email))) {
            Kernel::hr()->table('hr_sessions')->where('id', $row->session_id)->update(['revoked_at' => Kernel::now()]);
            return null;
        }
        // Live-board polling sends X-Trio-Background so it can't keep an idle session alive.
        $background = $req->getHeaderLine('X-Trio-Background') === '1';
        if (!$background && $now - strtotime($row->last_seen_at) >= 30) {
            Kernel::hr()->table('hr_sessions')->where('id', $row->session_id)->update(['last_seen_at' => Kernel::now()]);
            $row->last_seen_at = Kernel::now();
        }
        $a = (array) $row;
        $a['idle_seconds'] = $idle;
        $a['leaver'] = $row->role === 'employee' ? self::leaver($row->employee_id) : null;
        if ($a['leaver']['ended'] ?? false) {
            Kernel::hr()->table('hr_sessions')->where('id', $row->session_id)->update(['revoked_at' => Kernel::now()]);
            return null;
        }
        return $a;
    }

    /** Public shape of a session for the front end. */
    public static function describe(array $auth): array
    {
        $employee = null;
        if ($auth['employee_id']) {
            $employee = Kernel::hr()->table('hr_employees')->where('id', $auth['employee_id'])
                ->first(['id', 'full_name', 'preferred_name', 'designation', 'centre', 'crm_user_id']);
        }
        return [
            'account' => [
                'id'      => (int) $auth['account_id'],
                'name'    => $auth['display_name'],
                'first'   => Fmt::first($employee->preferred_name ?? $auth['display_name']),
                'email'   => $auth['email'],
                'role'    => $auth['role'],
                'initials' => Fmt::initials($auth['display_name']),
            ],
            'employee'     => $employee ? ['id' => (int) $employee->id, 'designation' => $employee->designation, 'centre' => $employee->centre] : null,
            'csrf'         => $auth['csrf_token'],
            'idle_seconds' => (int) $auth['idle_seconds'],
            'via'          => $auth['via'] ?? 'password',
            'has_password' => Kernel::hr()->table('hr_accounts')->where('id', $auth['account_id'])->whereNotNull('password_hash')->exists(),
            'local'        => Kernel::isLocal(),
            'crm_url'      => Kernel::env('HR_CRM_URL', 'https://crm.base-fare.com'),
            'email_on'     => Outbox::enabled(),
        ];
    }

    private static function idleFor(string $role, string $via): int
    {
        return $via === 'crm' ? self::CRM_IDLE : (self::isAdminRole($role) ? self::ADMIN_IDLE : self::STAFF_IDLE);
    }

    /** For a session opened from the CRM: is that person's CRM login still active? */
    private static function crmLoginActive($employeeId, string $email = ''): bool
    {
        if (!$employeeId) {
            // The CRM developer account has no staff record: matched by email, and only while still a developer.
            return $email !== '' && Kernel::crm()->table('users')->where('email', mb_strtolower($email))
                ->where('is_dev', 1)->whereNull('deleted_at')->where('status', 'active')->exists();
        }
        $crmId = Kernel::hr()->table('hr_employees')->where('id', $employeeId)->value('crm_user_id');
        if (!$crmId) {
            return false;
        }
        return Kernel::crm()->table('users')->where('id', $crmId)->whereNull('deleted_at')->where('status', 'active')->exists();
    }

    /** @return array{0:string,1:array} [cookie token, session] */
    private static function openSession(object $account, string $ip, string $ua, string $via = 'password'): array
    {
        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $now = Kernel::now();
        $max = $via === 'crm' ? self::CRM_MAX : (self::isAdminRole($account->role) ? self::ADMIN_MAX : self::STAFF_MAX);
        $csrf = bin2hex(random_bytes(32));
        $id = Kernel::hr()->table('hr_sessions')->insertGetId([
            'account_id'   => $account->id,
            'token_hash'   => hash('sha256', $token),
            'csrf_token'   => $csrf,
            'ip'           => substr($ip, 0, 45),
            'user_agent'   => mb_substr($ua, 0, 255),
            'created_at'   => $now,
            'last_seen_at' => $now,
            'expires_at'   => date('Y-m-d H:i:s', time() + $max),
            'via'          => $via === 'crm' ? 'crm' : 'password',
        ]);
        Kernel::hr()->table('hr_accounts')->where('id', $account->id)
            ->update(['last_login_at' => $now, 'failed_logins' => 0, 'locked_until' => null]);
        return [$token, [
            'session_id'   => $id,
            'account_id'   => (int) $account->id,
            'csrf_token'   => $csrf,
            'role'         => $account->role,
            'employee_id'  => $account->employee_id,
            'display_name' => $account->display_name,
            'email'        => $account->email,
            'idle_seconds' => self::idleFor($account->role, $via),
            'max_seconds'  => $max,
            'via'          => $via === 'crm' ? 'crm' : 'password',
        ]];
    }

    // ---- sign in / out ------------------------------------------------------

    /**
     * Check email + password (lockout, throttle, audit). Returns the account;
     * the caller then asks SecondStep whether a code is needed.
     */
    public static function checkPassword(string $email, string $password, string $ip): object
    {
        $db = Kernel::hr();
        $since = date('Y-m-d H:i:s', time() - self::IP_WINDOW);
        $ipFails = $db->table('hr_login_attempts')->where('ip', $ip)->where('kind', 'signin')->where('ok', 0)->where('at', '>=', $since)->count();
        if ($ipFails >= self::IP_MAX_FAILS) {
            throw new ApiError(429, 'throttled', 'Too many sign-in attempts from this network. Please wait 15 minutes and try again.');
        }

        $account = $db->table('hr_accounts')->where('email', mb_strtolower($email))->first();
        $hash = $account->password_hash ?? null;
        // Verify against a dummy hash when there's no account, so timing doesn't reveal which emails exist.
        // (The dummy is a real Argon2id hash of a random string nobody knows.)
        $ok = password_verify($password, $hash ?? '$argon2id$v=19$m=65536,t=4,p=1$V082TDBhSEhpalMxV1hxOQ$Q9gaw8ECBzvWlDHQRfsOo+8c21I4pu5GLE0+4R3rUxI');

        // "Paused" and "switched off" are told only to someone who typed the RIGHT
        // password (the owner). Anyone else gets the same reply as a wrong password,
        // so these messages can't be used to find out which emails have accounts.
        if ($account && $account->locked_until && strtotime($account->locked_until) > time()) {
            self::recordAttempt($ip, $email, false);
            if (!$ok) {
                throw new ApiError(401, 'bad_credentials', "That email and password don't match. Check them and try again.");
            }
            $mins = (int) ceil((strtotime($account->locked_until) - time()) / 60);
            throw new ApiError(423, 'locked', "This account is paused for {$mins} more " . ($mins === 1 ? 'minute' : 'minutes') . ' after too many wrong passwords. You can also reset your password.');
        }

        if (!$account || !$hash || !$ok || $account->status !== 'active') {
            self::recordAttempt($ip, $email, false);
            if ($account && $hash) {
                $fails = $account->failed_logins + 1;
                $update = ['failed_logins' => $fails >= self::LOCK_AFTER ? 0 : $fails];
                if ($fails >= self::LOCK_AFTER) {
                    $update['locked_until'] = date('Y-m-d H:i:s', time() + self::LOCK_SECONDS);
                }
                $db->table('hr_accounts')->where('id', $account->id)->update($update);
                Audit::log('signin.fail', ['account_id' => $account->id, 'detail' => ['locked' => $fails >= self::LOCK_AFTER]]);
            }
            if ($ok && $account && $account->status === 'disabled') {
                throw new ApiError(401, 'disabled', 'This account has been switched off. Please speak to the HR admin.');
            }
            throw new ApiError(401, 'bad_credentials', "That email and password don't match. Check them and try again.");
        }

        if ($account->role === 'employee' && (self::leaver($account->employee_id)['ended'] ?? false)) {
            throw new ApiError(401, 'access_ended', 'Your Trio HR access ended 12 months after you left Trio. If you need a letter or a document, please write to HR.');
        }
        if (password_needs_rehash($hash, PASSWORD_ARGON2ID)) {
            $db->table('hr_accounts')->where('id', $account->id)->update(['password_hash' => password_hash($password, PASSWORD_ARGON2ID)]);
        }
        self::recordAttempt($ip, $email, true);
        $db->table('hr_accounts')->where('id', $account->id)->update(['failed_logins' => 0, 'locked_until' => null]);
        return $account;
    }

    /**
     * Finish a sign-in once every step has passed: open the session, remember
     * the device and send the new-device alert where needed.
     *
     * @return array{token:string, session:array, cookies:list<string>}
     */
    public static function complete(object $account, string $ip, string $ua, ?object $trusted, bool $passedEmailCode = false, bool $remember = false, string $via = 'password'): array
    {
        [$token, $session] = self::openSession($account, $ip, $ua, $via);
        Audit::log('signin.ok', ['account_id' => $account->id]);
        $cookies = [self::cookie($token, $via === 'crm' ? -1 : (int) $session['max_seconds'])];
        if (!$trusted) {
            if ($passedEmailCode) {
                // The emailed code already told them about this device.
                if ($remember) {
                    $cookies[] = SecondStep::rememberDevice((int) $account->id, $ua);
                }
            } else {
                SecondStep::newDeviceAlert($account, $ua, $ip);
                $cookies[] = SecondStep::rememberDevice((int) $account->id, $ua);
            }
        }
        return ['token' => $token, 'session' => $session, 'cookies' => $cookies];
    }

    /** Back-compat helper for the invite/reset flows: open a session for an account that just proved itself. */
    public static function completeFresh(object $account, string $ip, string $ua): array
    {
        return self::complete($account, $ip, $ua, null, true, false);
    }

    private static function recordAttempt(string $ip, string $email, bool $ok, string $kind = 'signin'): void
    {
        Kernel::hr()->table('hr_login_attempts')->insert([
            'ip' => substr($ip, 0, 45), 'email' => mb_substr(mb_strtolower($email), 0, 190), 'ok' => $ok ? 1 : 0, 'kind' => $kind, 'at' => Kernel::now(),
        ]);
    }

    /**
     * Switch an account off: no sign-in, every session ended, remembered
     * devices forgotten and any unused set-up or reset link cancelled.
     */
    public static function switchOff(int $accountId): void
    {
        $db = Kernel::hr();
        $now = Kernel::now();
        $db->table('hr_accounts')->where('id', $accountId)->update(['status' => 'disabled', 'updated_at' => $now]);
        $db->table('hr_sessions')->where('account_id', $accountId)->whereNull('revoked_at')->update(['revoked_at' => $now]);
        $db->table('hr_trusted_devices')->where('account_id', $accountId)->delete();
        $db->table('hr_tokens')->where('account_id', $accountId)->whereNull('used_at')->update(['used_at' => $now]);
    }

    public static function signOut(array $auth): void
    {
        Kernel::hr()->table('hr_sessions')->where('id', $auth['session_id'])->update(['revoked_at' => Kernel::now()]);
        Audit::log('signout', ['account_id' => $auth['account_id']]);
    }

    /** Password re-check for sensitive views (bank, PAN). Valid for 5 minutes. */
    public static function reauth(array $auth, ?string $password): void
    {
        $recent = $auth['reauth_at'] && strtotime($auth['reauth_at']) + self::REAUTH_SECONDS > time();
        if ($recent && $password === null) {
            return;
        }
        if ($password === null) {
            throw new ApiError(428, 'reauth_required', 'Please enter your password to see this.');
        }
        $hash = Kernel::hr()->table('hr_accounts')->where('id', $auth['account_id'])->value('password_hash');
        if (!$hash || !password_verify($password, $hash)) {
            Audit::log('reauth.fail', ['account_id' => $auth['account_id']]);
            throw new ApiError(401, 'reauth_failed', "That password isn't right.");
        }
        Kernel::hr()->table('hr_sessions')->where('id', $auth['session_id'])->update(['reauth_at' => Kernel::now()]);
    }

    // ---- passwords, invites, resets ----------------------------------------

    /** @return string|null a plain-English problem, or null when fine */
    public static function passwordProblem(string $password, ?string $email = null): ?string
    {
        if (mb_strlen($password) < self::MIN_PASSWORD) {
            return 'Use at least ' . self::MIN_PASSWORD . ' characters.';
        }
        if (mb_strlen($password) > 200) {
            return 'That password is too long.';
        }
        $lower = mb_strtolower($password);
        foreach (['password', '12345678', 'qwerty', 'trio', 'basefare', 'base fare', 'welcome', 'letmein', 'admin'] as $weak) {
            if (str_contains($lower, $weak) && mb_strlen($password) < 16) {
                return "That's too easy to guess. Try a short sentence you'll remember.";
            }
        }
        if ($email && str_contains($lower, explode('@', mb_strtolower($email))[0])) {
            return "Don't use your email name in the password.";
        }
        return null;
    }

    private static function newToken(int $accountId, string $purpose, int $ttl): string
    {
        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        Kernel::hr()->table('hr_tokens')->where('account_id', $accountId)->where('purpose', $purpose)->whereNull('used_at')
            ->update(['used_at' => Kernel::now()]); // older links stop working
        Kernel::hr()->table('hr_tokens')->insert([
            'account_id' => $accountId, 'purpose' => $purpose, 'token_hash' => hash('sha256', $token),
            'expires_at' => date('Y-m-d H:i:s', time() + $ttl), 'created_at' => Kernel::now(),
        ]);
        return $token;
    }

    private static function findToken(string $token, string $purpose): object
    {
        if (!preg_match('/^[A-Za-z0-9_-]{43}$/', $token)) {
            throw new ApiError(410, 'link_invalid', "This link isn't valid. Ask for a new one.");
        }
        $row = Kernel::hr()->table('hr_tokens')->where('token_hash', hash('sha256', $token))->where('purpose', $purpose)->first();
        if (!$row || $row->used_at) {
            throw new ApiError(410, 'link_used', 'This link has already been used or replaced by a newer one.');
        }
        if (strtotime($row->expires_at) <= time()) {
            throw new ApiError(410, 'link_expired', 'This link has expired. Ask for a new one.');
        }
        return $row;
    }

    private static function appUrl(): string
    {
        return rtrim((string) Kernel::env('HR_APP_URL', 'https://hr.triotoursandtravels.in'), '/');
    }

    /** Create (or refresh) an invite for an employee and queue the email. */
    public static function invite(int $employeeId, int $byAccount): array
    {
        $db = Kernel::hr();
        $emp = $db->table('hr_employees')->where('id', $employeeId)->first();
        if (!$emp) {
            throw ApiError::notFound('That person');
        }
        $email = $emp->work_email ?: $emp->personal_email;
        if (!$email) {
            throw ApiError::invalid('Add an email address for ' . Fmt::first($emp->full_name) . ' first, so the invite has somewhere to go.');
        }
        if ($emp->status === 'left') {
            throw ApiError::conflict(Fmt::first($emp->full_name) . ' has left Trio. If they are coming back, change their status first.');
        }
        $account = $db->table('hr_accounts')->where('employee_id', $employeeId)->first();
        if ($account && $account->status === 'active') {
            throw ApiError::conflict(Fmt::first($emp->full_name) . ' has already joined Trio HR.');
        }
        if ($account && $account->role !== 'employee') {
            throw ApiError::conflict(Fmt::first($emp->full_name) . ' has admin access, which only an owner can turn back on (Settings → Admins).');
        }
        $clash = $db->table('hr_accounts')->where('email', mb_strtolower($email))
            ->where(fn($q) => $q->whereNull('employee_id')->orWhere('employee_id', '!=', $employeeId))->exists();
        if ($clash) {
            throw ApiError::conflict('Another Trio HR account already uses ' . $email . '.');
        }
        $now = Kernel::now();
        if (!$account) {
            $id = $db->table('hr_accounts')->insertGetId([
                'employee_id' => $employeeId, 'display_name' => $emp->full_name, 'email' => mb_strtolower($email),
                'role' => 'employee', 'status' => 'invited', 'invited_at' => $now, 'created_at' => $now, 'updated_at' => $now,
            ]);
        } else {
            $id = $account->id;
            // A switched-off account (someone who left and came back) starts again as invited.
            $db->table('hr_accounts')->where('id', $id)->update(['email' => mb_strtolower($email), 'invited_at' => $now, 'display_name' => $emp->full_name, 'status' => 'invited']);
        }
        $token = self::newToken($id, 'invite', 7 * 86400);
        $first = Fmt::first($emp->preferred_name ?: $emp->full_name);
        $link = self::appUrl() . '/#/welcome/' . $token;
        $lines = [
            "Hi {$first},",
            'Trio Tours and Travels now keeps its people records in Trio HR. Please set up your account and check your details. It takes about 5 minutes on your phone.',
            'This link works once and expires in 7 days.',
        ];
        Outbox::queue('invite:' . $id . ':' . substr(hash('sha256', $token), 0, 16), $email, 'Set up your Trio HR account',
            implode("\n\n", $lines) . "\n\n" . $link, Outbox::html('Welcome to Trio HR', $lines, ['Set up my account', $link]));
        $db->table('hr_employee_history')->insert([
            'employee_id' => $employeeId, 'kind' => 'invited', 'effective_on' => date('Y-m-d'),
            'summary' => ($account ? 'Invite sent again' : 'Invited to Trio HR') . ' · ' . $email, 'by_account_id' => $byAccount, 'at' => $now,
        ]);
        Audit::log($account ? 'invite.resend' : 'invite.send', [
            'employee_id' => $employeeId, 'entity' => 'hr_accounts', 'entity_id' => $id,
            'summary' => Fmt::first($emp->full_name) . ($account ? "'s invite was sent again" : ' was invited to Trio HR'),
        ]);
        return ['email' => $email, 'resent' => (bool) $account, 'link' => Outbox::enabled() ? null : $link];
    }

    /**
     * A one-time link an admin hands over in person or on WhatsApp (used when
     * HR email is off). Someone still setting up gets their set-up link (7
     * days); someone already signed up gets a new-password link (24 hours).
     * Making a new link cancels the previous one.
     */
    public static function handoverLink(int $accountId, int $byAccount): array
    {
        $acc = Kernel::hr()->table('hr_accounts')->where('id', $accountId)->first();
        if (!$acc || $acc->status === 'disabled') {
            throw ApiError::conflict('That sign-in is switched off. Switch it back on first.');
        }
        $setup = $acc->status === 'invited';
        $link = $setup
            ? self::appUrl() . '/#/welcome/' . self::newToken($accountId, 'invite', 7 * 86400)
            : self::appUrl() . '/#/reset/' . self::newToken($accountId, 'reset', 86400);
        Audit::log('account.link', ['account_id' => $byAccount, 'entity' => 'hr_accounts', 'entity_id' => $accountId,
            'summary' => 'A ' . ($setup ? 'set-up' : 'new-password') . ' link was made for ' . $acc->display_name]);
        return ['link' => $link, 'setup' => $setup, 'name' => Fmt::first($acc->display_name),
            'valid' => $setup ? '7 days' : '24 hours'];
    }

    /** Send (or resend) the set-up link for an invited account that has no staff record, e.g. a new admin. */
    /**
     * The very first owner, created on the server (scripts/hr_create_owner.php).
     * Returns the one-time set-up link instead of emailing it, so going live
     * doesn't depend on mail working yet. Same 7-day invite token as any admin.
     */
    public static function firstOwnerLink(int $accountId): string
    {
        $acc = Kernel::hr()->table('hr_accounts')->where('id', $accountId)->first();
        if (!$acc || $acc->status !== 'invited' || $acc->role !== 'owner') {
            throw new \RuntimeException('That account is not an owner waiting to be set up.');
        }
        return self::appUrl() . '/#/welcome/' . self::newToken($accountId, 'invite', 7 * 86400);
    }

    /** Returns the link itself when HR email is off (the owner hands it over), else null. */
    public static function sendAccountInvite(int $accountId, int $byAccount): ?string
    {
        $acc = Kernel::hr()->table('hr_accounts')->where('id', $accountId)->first();
        if (!$acc || $acc->status !== 'invited') {
            throw ApiError::conflict('That account has already been set up.');
        }
        $token = self::newToken($accountId, 'invite', 7 * 86400);
        $link = self::appUrl() . '/#/welcome/' . $token;
        $lines = [
            'Hi ' . Fmt::first($acc->display_name) . ',',
            'You have been given ' . ($acc->role === 'owner' ? 'owner' : 'admin') . ' access to Trio HR, where Trio Tours and Travels keeps its people records.',
            'Set your password with the button below. The link works once and expires in 7 days.',
        ];
        Outbox::queue('invite:' . $accountId . ':' . substr(hash('sha256', $token), 0, 16), $acc->email, 'You have access to Trio HR',
            implode("\n\n", $lines) . "\n\n" . $link, Outbox::html('Welcome to Trio HR', $lines, ['Set up my account', $link]));
        Kernel::hr()->table('hr_accounts')->where('id', $accountId)->update(['invited_at' => Kernel::now()]);
        return Outbox::enabled() ? null : $link;
    }

    /** Who an invite is for (shown on the welcome screen). */
    public static function inviteInfo(string $token): array
    {
        $row = self::findToken($token, 'invite');
        $acc = Kernel::hr()->table('hr_accounts')->where('id', $row->account_id)->first(['display_name', 'email']);
        return ['name' => Fmt::first($acc->display_name ?? ''), 'email' => $acc->email ?? ''];
    }

    public static function acceptInvite(string $token, string $password, string $ip, string $ua): array
    {
        $row = self::findToken($token, 'invite');
        $account = Kernel::hr()->table('hr_accounts')->where('id', $row->account_id)->first();
        if (!$account || $account->status === 'disabled') {
            throw new ApiError(410, 'link_invalid', 'This account has been switched off. Please speak to the HR admin.');
        }
        if ($p = self::passwordProblem($password, $account->email)) {
            throw ApiError::invalid($p, ['password' => $p]);
        }
        Kernel::hr()->transaction(function () use ($row, $account, $password) {
            Kernel::hr()->table('hr_tokens')->where('id', $row->id)->update(['used_at' => Kernel::now()]);
            Kernel::hr()->table('hr_accounts')->where('id', $account->id)->update([
                'password_hash' => password_hash($password, PASSWORD_ARGON2ID), 'status' => 'active', 'password_changed_at' => Kernel::now(),
            ]);
        });
        $account->status = 'active';
        if ($account->employee_id) {
            Kernel::hr()->table('hr_employee_history')->insert([
                'employee_id' => $account->employee_id, 'kind' => 'joined_hr', 'effective_on' => date('Y-m-d'),
                'summary' => 'Set up their Trio HR account', 'by_account_id' => $account->id, 'at' => Kernel::now(),
            ]);
        }
        Audit::log('invite.accept', [
            'account_id' => $account->id, 'employee_id' => $account->employee_id,
            'summary' => Fmt::first($account->display_name) . ' set up their Trio HR account',
        ]);
        Notify::admins('invite.accepted', Fmt::first($account->display_name) . ' set up their Trio HR account', null,
            $account->employee_id ? '#/people/' . $account->employee_id : '#/people');
        return self::completeFresh($account, $ip, $ua);
    }

    /** Always succeeds from the caller's point of view (doesn't reveal accounts). */
    public static function forgot(string $email, string $ip): void
    {
        $db = Kernel::hr();
        $since = date('Y-m-d H:i:s', time() - self::IP_WINDOW);
        if ($db->table('hr_login_attempts')->where('ip', $ip)->where('kind', 'reset')->where('at', '>=', $since)->count() >= self::RESET_IP_MAX) {
            return; // same reply as always; nothing is sent
        }
        self::recordAttempt($ip, $email, false, 'reset');
        $account = $db->table('hr_accounts')->where('email', mb_strtolower($email))->where('status', 'active')->first();
        if (!$account) {
            return;
        }
        $recent = $db->table('hr_tokens')->where('account_id', $account->id)->where('purpose', 'reset')
            ->where('created_at', '>=', date('Y-m-d H:i:s', time() - 3600))->count();
        if ($recent >= 3) {
            return;
        }
        $token = self::newToken($account->id, 'reset', 3600);
        $link = self::appUrl() . '/#/reset/' . $token;
        $lines = [
            'Hi ' . Fmt::first($account->display_name) . ',',
            'Someone asked to reset your Trio HR password. If it was you, use the button below. The link works once and expires in 1 hour.',
            "If it wasn't you, ignore this email. Your password hasn't changed.",
        ];
        Outbox::queue('reset:' . $account->id . ':' . substr(hash('sha256', $token), 0, 16), $account->email, 'Reset your Trio HR password',
            implode("\n\n", $lines) . "\n\n" . $link, Outbox::html('Reset your password', $lines, ['Choose a new password', $link]));
        Audit::log('password.reset_requested', ['account_id' => $account->id]);
    }

    /** Is this reset link still good? (Checked when the page opens, before any typing.) */
    public static function resetCheck(string $token): array
    {
        $row = self::findToken($token, 'reset');
        $ok = Kernel::hr()->table('hr_accounts')->where('id', $row->account_id)->where('status', 'active')->exists();
        if (!$ok) {
            throw new ApiError(410, 'link_invalid', "This link isn't valid any more.");
        }
        return ['ok' => true];
    }

    public static function reset(string $token, string $password, string $ip, string $ua): array
    {
        $row = self::findToken($token, 'reset');
        $account = Kernel::hr()->table('hr_accounts')->where('id', $row->account_id)->first();
        if (!$account || $account->status !== 'active') {
            throw new ApiError(410, 'link_invalid', "This link isn't valid any more.");
        }
        if ($p = self::passwordProblem($password, $account->email)) {
            throw ApiError::invalid($p, ['password' => $p]);
        }
        Kernel::hr()->transaction(function () use ($row, $account, $password) {
            Kernel::hr()->table('hr_tokens')->where('id', $row->id)->update(['used_at' => Kernel::now()]);
            Kernel::hr()->table('hr_accounts')->where('id', $account->id)->update([
                'password_hash' => password_hash($password, PASSWORD_ARGON2ID), 'password_changed_at' => Kernel::now(),
                'failed_logins' => 0, 'locked_until' => null,
            ]);
            // A reset signs every other device out.
            Kernel::hr()->table('hr_sessions')->where('account_id', $account->id)->whereNull('revoked_at')->update(['revoked_at' => Kernel::now()]);
        });
        Audit::log('password.reset', ['account_id' => $account->id]);
        return self::completeFresh($account, $ip, $ua);
    }

    public static function changePassword(array $auth, string $current, string $new): void
    {
        $hash = Kernel::hr()->table('hr_accounts')->where('id', $auth['account_id'])->value('password_hash');
        if (!$hash || !password_verify($current, $hash)) {
            throw ApiError::invalid("Your current password isn't right.", ['current' => "That isn't your current password."]);
        }
        if ($p = self::passwordProblem($new, $auth['email'])) {
            throw ApiError::invalid($p, ['password' => $p]);
        }
        Kernel::hr()->table('hr_accounts')->where('id', $auth['account_id'])
            ->update(['password_hash' => password_hash($new, PASSWORD_ARGON2ID), 'password_changed_at' => Kernel::now()]);
        Kernel::hr()->table('hr_sessions')->where('account_id', $auth['account_id'])->where('id', '!=', $auth['session_id'])
            ->whereNull('revoked_at')->update(['revoked_at' => Kernel::now()]);
        Audit::log('password.change', ['account_id' => $auth['account_id']]);
    }

    // ---- devices ------------------------------------------------------------

    public static function devices(array $auth): array
    {
        $rows = Kernel::hr()->table('hr_sessions')->where('account_id', $auth['account_id'])->whereNull('revoked_at')
            ->where('expires_at', '>', Kernel::now())->orderByDesc('last_seen_at')->limit(20)->get();
        return $rows->map(fn($s) => [
            'id'      => (int) $s->id,
            'device'  => self::deviceName((string) $s->user_agent),
            'ip'      => $s->ip,
            'since'   => $s->created_at,
            'seen'    => $s->last_seen_at,
            'current' => (int) $s->id === (int) $auth['session_id'],
        ])->all();
    }

    public static function revokeDevice(array $auth, int $sessionId): void
    {
        $n = Kernel::hr()->table('hr_sessions')->where('id', $sessionId)->where('account_id', $auth['account_id'])
            ->whereNull('revoked_at')->update(['revoked_at' => Kernel::now()]);
        if (!$n) {
            throw ApiError::notFound('That device');
        }
        Audit::log('device.signout', ['account_id' => $auth['account_id'], 'entity' => 'hr_sessions', 'entity_id' => $sessionId]);
    }

    private static function deviceName(string $ua): string
    {
        $os = match (true) {
            str_contains($ua, 'iPhone') => 'iPhone',
            str_contains($ua, 'iPad') => 'iPad',
            str_contains($ua, 'Android') => 'Android phone',
            str_contains($ua, 'Windows') => 'Windows computer',
            str_contains($ua, 'Mac OS') => 'Mac',
            default => 'Device',
        };
        $browser = match (true) {
            str_contains($ua, 'Edg/') => 'Edge',
            str_contains($ua, 'Chrome/') => 'Chrome',
            str_contains($ua, 'Firefox/') => 'Firefox',
            str_contains($ua, 'Safari/') => 'Safari',
            default => 'browser',
        };
        return $os . ' · ' . $browser;
    }

    public static function clientUa(Request $req): string
    {
        return $req->getHeaderLine('User-Agent');
    }

    public static function ip(): string
    {
        return Ctx::clientIp();
    }
}
