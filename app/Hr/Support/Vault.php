<?php

namespace App\Hr\Support;

use App\Hr\Http\ApiError;
use App\Hr\Kernel;

/**
 * Encryption for PAN, Aadhaar and bank details (libsodium secretbox).
 *
 * The key (HR_VAULT_KEY, base64 of 32 random bytes) lives outside the
 * database and is used only by Trio HR, so a database leak alone doesn't
 * expose these fields. Stored format: 24-byte nonce followed by the box.
 */
final class Vault
{
    private static function key(): string
    {
        $b64 = Kernel::env('HR_VAULT_KEY');
        $key = $b64 !== null ? base64_decode($b64, true) : false;
        if ($key === false || strlen($key) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
            throw new ApiError(503, 'vault_unavailable', "Private details can't be opened right now: the HR vault key isn't set up on this server.");
        }
        return $key;
    }

    public static function available(): bool
    {
        try {
            self::key();
            return true;
        } catch (ApiError) {
            return false;
        }
    }

    public static function encrypt(string $plain): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        return $nonce . sodium_crypto_secretbox($plain, $nonce, self::key());
    }

    public static function decrypt(string $blob): string
    {
        $nonce = substr($blob, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $box = substr($blob, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $plain = sodium_crypto_secretbox_open($box, $nonce, self::key());
        if ($plain === false) {
            throw new ApiError(500, 'vault_mismatch', "This detail can't be opened with the current vault key.");
        }
        return $plain;
    }

    /** The few characters shown when masked: last 4 (PAN keeps its last 4 too). */
    public static function hint(string $field, string $plain): ?string
    {
        $clean = preg_replace('/\s+/', '', $plain) ?? '';
        return match ($field) {
            'pan', 'aadhaar', 'bank_account' => substr($clean, -4),
            'bank_ifsc' => substr($clean, 0, 4),
            default => null,
        };
    }

    /** Save one encrypted field for an employee. */
    public static function put(int $employeeId, string $field, string $plain, ?int $byAccount): void
    {
        Kernel::hr()->table('hr_employee_secrets')->updateOrInsert(
            ['employee_id' => $employeeId, 'field' => $field],
            [
                'ciphertext' => self::encrypt($plain),
                'hint'       => self::hint($field, $plain),
                'updated_by' => $byAccount,
                'updated_at' => Kernel::now(),
            ]
        );
    }
}
