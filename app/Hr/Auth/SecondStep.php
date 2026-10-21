<?php

namespace App\Hr\Auth;

use App\Hr\Domain\Settings;
use App\Hr\Http\ApiError;
use App\Hr\Kernel;
use App\Hr\Support\Audit;
use App\Hr\Support\Fmt;
use App\Hr\Support\Outbox;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Remembered devices and the optional emailed code.
 *
 * Trio HR signs in with email and password only — no authenticator app
 * (John, 16 Oct 2026). Two light safeguards remain:
 *
 *  - Any full sign-in from a device that isn't remembered sends a "new sign-in"
 *    email, so a stolen password can't be used quietly. No extra step.
 *  - Optional, OFF by default (owner switch "staff_email_code"): staff on a
 *    new phone type a 6-digit code sent to their email; the phone can then be
 *    remembered for a year.
 */
final class SecondStep
{
    private const CHALLENGE_TTL = 600;
    private const MAX_ATTEMPTS = 5;
    private const DEVICE_DAYS = 365;

    // ---- remembered devices -----------------------------------------------------

    public static function deviceCookieName(): string
    {
        return Kernel::isLocal() ? 'trio_hr_dev' : '__Host-trio_hr_dev';
    }

    public static function deviceCookie(string $value, int $maxAge): string
    {
        $parts = [self::deviceCookieName() . '=' . $value, 'Path=/', 'HttpOnly', 'SameSite=Strict', 'Max-Age=' . max(0, $maxAge)];
        if (!Kernel::isLocal()) {
            $parts[] = 'Secure';
        }
        return implode('; ', $parts);
    }

    /** The remembered-device row for this browser and account, if any. */
    public static function trustedDevice(Request $req, int $accountId): ?object
    {
        $token = $req->getCookieParams()[self::deviceCookieName()] ?? null;
        if (!is_string($token) || !preg_match('/^[A-Za-z0-9_-]{43}$/', $token)) {
            return null;
        }
        $row = Kernel::hr()->table('hr_trusted_devices')->where('token_hash', hash('sha256', $token))
            ->where('account_id', $accountId)->whereNull('revoked_at')->first();
        if (!$row || strtotime($row->created_at) < time() - self::DEVICE_DAYS * 86400) {
            return null;
        }
        Kernel::hr()->table('hr_trusted_devices')->where('id', $row->id)->update(['last_used_at' => Kernel::now()]);
        return $row;
    }

    /** Remember this browser. Returns the Set-Cookie header value. */
    public static function rememberDevice(int $accountId, string $ua): string
    {
        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        Kernel::hr()->table('hr_trusted_devices')->insert([
            'account_id' => $accountId, 'token_hash' => hash('sha256', $token), 'user_agent' => mb_substr($ua, 0, 255),
            'created_at' => Kernel::now(), 'last_used_at' => Kernel::now(),
        ]);
        return self::deviceCookie($token, self::DEVICE_DAYS * 86400);
    }

    // ---- the emailed code --------------------------------------------------------------

    /** Does this account need an emailed code from this browser? */
    public static function needed(object $account, ?object $trusted): ?string
    {
        // With HR email off there is nowhere to send the code, so it never applies (it would lock staff out).
        return $account->role === 'employee' && !$trusted && Settings::security('staff_email_code') && Outbox::enabled() ? 'email' : null;
    }

    /** @return array{0:string,1:array} [challenge token, what the screen should ask for] */
    public static function start(object $account, string $ip, string $ua): array
    {
        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        Kernel::hr()->table('hr_login_challenges')->insert([
            'account_id' => $account->id, 'kind' => 'email', 'token_hash' => hash('sha256', $token),
            'code_hash' => hash('sha256', $token . '|' . $code),
            'ip' => substr($ip, 0, 45), 'user_agent' => mb_substr($ua, 0, 255),
            'expires_at' => date('Y-m-d H:i:s', time() + self::CHALLENGE_TTL), 'created_at' => Kernel::now(),
        ]);
        $lines = [
            'Hi ' . Fmt::first($account->display_name) . ',',
            "Your Trio HR sign-in code is {$code}. It works for 10 minutes.",
            "If you didn't try to sign in, someone may know your password. Change it, and tell your HR admin.",
        ];
        Outbox::queue('code:' . substr(hash('sha256', $token), 0, 24), $account->email, 'Your Trio HR code: ' . $code,
            implode("\n\n", $lines), Outbox::html('Your sign-in code', $lines));
        Outbox::flushAfterResponse(2);
        return [$token, ['step' => 'email', 'to' => self::mask($account->email)]];
    }

    public static function resend(string $token): array
    {
        $c = self::load($token);
        if (strtotime($c->created_at) > time() - 30) {
            throw new ApiError(429, 'throttled', 'Please wait half a minute before asking for another code.');
        }
        $account = Kernel::hr()->table('hr_accounts')->where('id', $c->account_id)->first();
        Kernel::hr()->table('hr_login_challenges')->where('id', $c->id)->update(['used_at' => Kernel::now()]);
        return self::start($account, (string) $c->ip, (string) $c->user_agent);
    }

    private static function load(string $token): object
    {
        if (!preg_match('/^[A-Za-z0-9_-]{43}$/', $token)) {
            throw new ApiError(410, 'challenge_gone', 'That sign-in has expired. Please start again.');
        }
        $c = Kernel::hr()->table('hr_login_challenges')->where('token_hash', hash('sha256', $token))->first();
        if (!$c || $c->used_at || strtotime($c->expires_at) <= time() || $c->attempts >= self::MAX_ATTEMPTS) {
            throw new ApiError(410, 'challenge_gone', 'That sign-in has expired. Please start again.');
        }
        return $c;
    }

    /** Check an emailed code. Returns the account on success. */
    public static function verify(string $token, string $code, string $ip): object
    {
        $c = self::load($token);
        $db = Kernel::hr();
        $account = $db->table('hr_accounts')->where('id', $c->account_id)->first();
        if (!$account || $account->status !== 'active') {
            throw new ApiError(410, 'challenge_gone', 'That sign-in has expired. Please start again.');
        }
        $digits = preg_replace('/\D/', '', $code) ?? '';
        if (strlen($digits) !== 6 || !hash_equals((string) $c->code_hash, hash('sha256', $token . '|' . $digits))) {
            $db->table('hr_login_challenges')->where('id', $c->id)->increment('attempts');
            $db->table('hr_login_attempts')->insert(['ip' => substr($ip, 0, 45), 'email' => $account->email, 'ok' => 0, 'at' => Kernel::now()]);
            Audit::log('signin.code_fail', ['account_id' => $account->id]);
            $left = self::MAX_ATTEMPTS - $c->attempts - 1;
            throw ApiError::invalid($left > 0
                ? "That code isn't right. " . Fmt::plural($left, 'try', 'tries') . ' left.'
                : "That code isn't right. Please sign in again.", ['code' => "That code isn't right."]);
        }
        $db->table('hr_login_challenges')->where('id', $c->id)->update(['used_at' => Kernel::now()]);
        return $account;
    }

    // ---- alerts ------------------------------------------------------------------------------

    public static function newDeviceAlert(object $account, string $ua, string $ip): void
    {
        $lines = [
            'Hi ' . Fmt::first($account->display_name) . ',',
            'Your Trio HR account was just signed in to from a new device: ' . self::deviceName($ua) . ', at ' . date('g:i a') . ' on ' . date('D j M') . ' (India time).',
            "If this was you, there's nothing to do. If it wasn't, change your password now and tell your HR admin.",
        ];
        Outbox::queue('newdev:' . $account->id . ':' . substr(hash('sha256', $ua . $ip . date('YmdHi')), 0, 16), $account->email,
            'New sign-in to your Trio HR account', implode("\n\n", $lines), Outbox::html('New sign-in to your account', $lines));
        Outbox::flushAfterResponse(2);
    }

    public static function deviceName(string $ua): string
    {
        $os = match (true) {
            str_contains($ua, 'iPhone') => 'iPhone', str_contains($ua, 'iPad') => 'iPad', str_contains($ua, 'Android') => 'Android phone',
            str_contains($ua, 'Windows') => 'Windows computer', str_contains($ua, 'Mac OS') => 'Mac', str_contains($ua, 'CrOS') => 'Chromebook',
            str_contains($ua, 'Linux') => 'Linux computer', default => 'a device',
        };
        $browser = match (true) {
            str_contains($ua, 'Edg/') => 'Edge', str_contains($ua, 'Chrome/') => 'Chrome', str_contains($ua, 'Firefox/') => 'Firefox',
            str_contains($ua, 'Safari/') => 'Safari', default => 'a browser',
        };
        return $os . ' · ' . $browser;
    }

    public static function mask(string $email): string
    {
        [$user, $domain] = array_pad(explode('@', $email, 2), 2, '');
        return mb_substr($user, 0, 1) . str_repeat('•', max(3, min(8, mb_strlen($user) - 1))) . '@' . $domain;
    }
}
