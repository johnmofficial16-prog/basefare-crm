<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Database\Capsule\Manager as DB;

/**
 * Two-step sign-in for admin accounts (security, 26 Oct 2026): a 6-digit code
 * from an authenticator app (RFC 6238 TOTP — Google Authenticator, Microsoft
 * Authenticator, etc.) after the password, or a one-time backup code.
 *
 * The secret is stored encrypted (EncryptionService); each 30-second code is
 * accepted once; a computer can be remembered for 30 days with a signed cookie
 * that a reset (scripts/reset_two_step.php) cancels.
 */
final class TwoStep
{
    public const ISSUER = 'Base Fare CRM';
    private const PERIOD = 30;
    private const DIGITS = 6;
    public const REMEMBER_DAYS = 30;
    private const COOKIE = 'bf_2step';

    /** Admins and developer accounts sign in with two steps. */
    public static function requiredFor(User $user): bool
    {
        return $user->role === User::ROLE_ADMIN || !empty($user->is_dev);
    }

    public static function isSetUp(User $user): bool
    {
        return !empty($user->totp_enabled_at) && !empty($user->totp_secret_enc);
    }

    // ---- secrets & codes ----------------------------------------------------

    public static function newSecret(): string
    {
        return self::base32Encode(random_bytes(20));
    }

    /** otpauth:// link the authenticator app reads from the QR code. */
    public static function setupUri(User $user, string $secret): string
    {
        $label = rawurlencode(self::ISSUER . ':' . $user->email);
        return 'otpauth://totp/' . $label . '?secret=' . $secret . '&issuer=' . rawurlencode(self::ISSUER)
            . '&algorithm=SHA1&digits=' . self::DIGITS . '&period=' . self::PERIOD;
    }

    /**
     * The 30-second step a code belongs to (now ±1 step for clock drift), or null.
     * Steps at or before $lastStep are refused, so a code can't be used twice.
     */
    public static function matchStep(string $secret, string $code, ?int $lastStep = null): ?int
    {
        $code = preg_replace('/\s+/', '', $code);
        if (!preg_match('/^\d{6}$/', $code)) {
            return null;
        }
        $key = self::base32Decode($secret);
        $now = intdiv(time(), self::PERIOD);
        foreach ([$now - 1, $now, $now + 1] as $step) {
            if ($lastStep !== null && $step <= $lastStep) {
                continue;
            }
            if (hash_equals(self::codeAt($key, $step), $code)) {
                return $step;
            }
        }
        return null;
    }

    public static function codeAt(string $key, int $step): string
    {
        $h = hash_hmac('sha1', pack('N2', $step >> 32, $step & 0xFFFFFFFF), $key, true);
        $o = ord($h[19]) & 0x0F;
        $n = ((ord($h[$o]) & 0x7F) << 24) | (ord($h[$o + 1]) << 16) | (ord($h[$o + 2]) << 8) | ord($h[$o + 3]);
        return str_pad((string) ($n % 10 ** self::DIGITS), self::DIGITS, '0', STR_PAD_LEFT);
    }

    /** Check a code for a set-up user; on success remember its step so it can't be replayed. */
    public static function verifyFor(User $user, string $code): bool
    {
        try {
            $secret = (new EncryptionService())->decrypt((string) $user->totp_secret_enc);
        } catch (\Throwable $e) {
            error_log('[TwoStep] secret could not be decrypted for user ' . $user->id . ': ' . $e->getMessage());
            return false;
        }
        $step = self::matchStep($secret, $code, $user->totp_last_step !== null ? (int) $user->totp_last_step : null);
        if ($step === null) {
            return self::useBackupCode($user, $code);
        }
        DB::table('users')->where('id', $user->id)->update(['totp_last_step' => $step]);
        return true;
    }

    /** Turn two-step on with a secret the person has just proved with a code. Returns the backup codes (shown once). */
    public static function enable(User $user, string $secret, int $step): array
    {
        $codes = [];
        $hashes = [];
        for ($i = 0; $i < 8; $i++) {
            $c = strtolower(substr(bin2hex(random_bytes(4)), 0, 4) . '-' . substr(bin2hex(random_bytes(4)), 0, 4));
            $codes[] = $c;
            $hashes[] = password_hash($c, PASSWORD_DEFAULT);
        }
        DB::table('users')->where('id', $user->id)->update([
            'totp_secret_enc'   => (new EncryptionService())->encrypt($secret),
            'totp_enabled_at'   => date('Y-m-d H:i:s'),
            'totp_last_step'    => $step,
            'totp_backup_codes' => json_encode($hashes),
        ]);
        self::log($user->id, 'two_step_enabled');
        return $codes;
    }

    private static function useBackupCode(User $user, string $code): bool
    {
        $code = strtolower(trim($code));
        if (!preg_match('/^[0-9a-f]{4}-?[0-9a-f]{4}$/', $code)) {
            return false;
        }
        if (strlen($code) === 8) {
            $code = substr($code, 0, 4) . '-' . substr($code, 4);
        }
        $hashes = json_decode((string) $user->totp_backup_codes, true) ?: [];
        foreach ($hashes as $i => $h) {
            if (password_verify($code, $h)) {
                unset($hashes[$i]);
                DB::table('users')->where('id', $user->id)->update(['totp_backup_codes' => json_encode(array_values($hashes))]);
                self::log($user->id, 'two_step_backup_code_used', ['left' => count($hashes)]);
                return true;
            }
        }
        return false;
    }

    // ---- "remember this computer" -------------------------------------------

    private static function deviceKey(): ?string
    {
        $k = (string) ($_ENV['ENCRYPTION_KEY_A'] ?? getenv('ENCRYPTION_KEY_A') ?: '');
        return $k === '' ? null : hash('sha256', 'bf-two-step-device|' . $k, true);
    }

    public static function rememberCookie(User $user): ?string
    {
        $key = self::deviceKey();
        if ($key === null) {
            return null;
        }
        $exp = time() + self::REMEMBER_DAYS * 86400;
        $payload = $user->id . '.' . $exp;
        $sig = hash_hmac('sha256', $payload . '.' . (int) $user->totp_device_epoch . '.' . $user->totp_secret_enc, $key);
        $parts = [self::COOKIE . '=' . $payload . '.' . $sig, 'Path=/', 'HttpOnly', 'SameSite=Lax', 'Max-Age=' . (self::REMEMBER_DAYS * 86400)];
        if (($_SERVER['HTTPS'] ?? '') === 'on' || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https' || ($_ENV['APP_ENV'] ?? '') !== 'local') {
            $parts[] = 'Secure';
        }
        return implode('; ', $parts);
    }

    public static function isRemembered(User $user): bool
    {
        $key = self::deviceKey();
        $raw = (string) ($_COOKIE[self::COOKIE] ?? '');
        if ($key === null || !preg_match('/^(\d+)\.(\d+)\.([a-f0-9]{64})$/', $raw, $m)) {
            return false;
        }
        if ((int) $m[1] !== (int) $user->id || (int) $m[2] < time()) {
            return false;
        }
        $want = hash_hmac('sha256', $m[1] . '.' . $m[2] . '.' . (int) $user->totp_device_epoch . '.' . $user->totp_secret_enc, $key);
        return hash_equals($want, $m[3]);
    }

    /** Emergency reset from the server (scripts/reset_two_step.php): set up again at next sign-in. */
    public static function reset(int $userId): void
    {
        DB::table('users')->where('id', $userId)->update([
            'totp_secret_enc' => null, 'totp_enabled_at' => null, 'totp_last_step' => null, 'totp_backup_codes' => null,
            'totp_device_epoch' => DB::raw('totp_device_epoch + 1'),
        ]);
        self::log($userId, 'two_step_reset');
    }

    // ---- helpers --------------------------------------------------------------

    public static function base32Encode(string $bin): string
    {
        $alpha = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $bits = '';
        foreach (str_split($bin) as $c) {
            $bits .= str_pad(decbin(ord($c)), 8, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 5) as $chunk) {
            $out .= $alpha[bindec(str_pad($chunk, 5, '0'))];
        }
        return $out;
    }

    public static function base32Decode(string $b32): string
    {
        $alpha = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $bits = '';
        foreach (str_split(strtoupper(preg_replace('/[^A-Za-z2-7]/', '', $b32))) as $c) {
            $bits .= str_pad(decbin(strpos($alpha, $c)), 5, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 8) as $byte) {
            if (strlen($byte) === 8) {
                $out .= chr(bindec($byte));
            }
        }
        return $out;
    }

    private static function log(int $userId, string $action, array $details = []): void
    {
        try {
            DB::table('activity_log')->insert([
                'user_id' => $userId, 'action' => $action, 'entity_type' => 'users', 'entity_id' => $userId,
                'details' => json_encode($details), 'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
                'created_at' => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $e) {
            error_log('[TwoStep] activity_log insert failed: ' . $e->getMessage());
        }
    }
}
