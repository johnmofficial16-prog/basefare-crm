<?php

namespace App\Services;

/**
 * EncryptionService — AES-256-GCM with split-key architecture.
 *
 * Key derivation:
 *   - KEY_A: loaded from .env (ENCRYPTION_KEY_A)
 *   - KEY_B: loaded from a file path defined in .env (ENCRYPTION_KEY_FILE)
 *   - Final key: HKDF-SHA256(KEY_A . KEY_B) → 32 bytes
 *
 * Ciphertext format (base64-encoded):
 *   [12-byte IV][16-byte GCM tag][variable ciphertext]
 *
 * Usage:
 *   $enc = new EncryptionService();
 *   $cipher = $enc->encrypt('4111111111111111');
 *   $plain  = $enc->decrypt($cipher);
 *
 * Admin card reveal pattern:
 *   // Never auto-decrypt — always call explicitly
 *   $pan = $enc->decrypt($card->card_number_enc);
 */
class EncryptionService
{
    private const CIPHER    = 'aes-256-gcm';
    private const IV_LENGTH = 12;   // 96-bit IV — recommended for GCM
    private const TAG_LENGTH = 16;  // 128-bit authentication tag

    private string $key;

    /**
     * Previous key, derived from ENCRYPTION_KEY_A_OLD / ENCRYPTION_KEY_B_OLD
     * when present. Used ONLY as a decryption fallback during key rotation so
     * rows not yet re-encrypted stay readable — encryption always uses the
     * current key. Null when no rotation is in progress.
     */
    private ?string $oldKey = null;

    /**
     * @throws \RuntimeException if encryption keys are not configured
     */
    public function __construct()
    {
        $this->key    = $this->deriveKey();
        $this->oldKey = $this->deriveOldKey();
    }

    /**
     * The active encryption key (raw 32 bytes). Exposed for the rotation script
     * so it can verify a round-trip without re-reading the environment.
     */
    public function activeKey(): string
    {
        return $this->key;
    }

    /** Whether a previous key is configured (i.e. a rotation is in progress). */
    public function hasOldKey(): bool
    {
        return $this->oldKey !== null;
    }

    // =========================================================================
    // PUBLIC API
    // =========================================================================

    /**
     * Encrypt a plaintext string.
     *
     * @param  string $plaintext
     * @return string  base64-encoded ciphertext (IV + tag + ciphertext)
     * @throws \RuntimeException on OpenSSL failure
     */
    public function encrypt(string $plaintext): string
    {
        $iv  = random_bytes(self::IV_LENGTH);
        $tag = '';

        $ciphertext = openssl_encrypt(
            $plaintext,
            self::CIPHER,
            $this->key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            '',          // additional authenticated data (none)
            self::TAG_LENGTH
        );

        if ($ciphertext === false) {
            throw new \RuntimeException('Encryption failed: ' . openssl_error_string());
        }

        // Pack: IV (12) + Tag (16) + Ciphertext (variable)
        return base64_encode($iv . $tag . $ciphertext);
    }

    /**
     * Decrypt a ciphertext produced by encrypt().
     *
     * @param  string $encoded  base64-encoded ciphertext
     * @return string  plaintext
     * @throws \RuntimeException on decryption failure or tampered ciphertext
     */
    public function decrypt(string $encoded): string
    {
        $raw = base64_decode($encoded, strict: true);

        if ($raw === false || strlen($raw) < self::IV_LENGTH + self::TAG_LENGTH + 1) {
            throw new \RuntimeException('Decryption failed: invalid ciphertext format.');
        }

        $iv         = substr($raw, 0, self::IV_LENGTH);
        $tag        = substr($raw, self::IV_LENGTH, self::TAG_LENGTH);
        $ciphertext = substr($raw, self::IV_LENGTH + self::TAG_LENGTH);

        $plaintext = openssl_decrypt(
            $ciphertext,
            self::CIPHER,
            $this->key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag
        );

        // Rotation fallback: a row that hasn't been re-encrypted yet still
        // carries ciphertext under the previous key. GCM authenticates, so a
        // wrong key fails cleanly rather than returning garbage — that makes
        // "try current, then previous" safe and unambiguous.
        if ($plaintext === false && $this->oldKey !== null) {
            $plaintext = openssl_decrypt(
                $ciphertext,
                self::CIPHER,
                $this->oldKey,
                OPENSSL_RAW_DATA,
                $iv,
                $tag
            );
        }

        if ($plaintext === false) {
            throw new \RuntimeException('Decryption failed: authentication tag mismatch or corrupted data.');
        }

        return $plaintext;
    }

    /**
     * Whether a ciphertext decrypts under the CURRENT key (no fallback).
     * The rotation script uses this to tell migrated rows from pending ones.
     */
    public function isUnderCurrentKey(string $encoded): bool
    {
        $raw = base64_decode($encoded, strict: true);
        if ($raw === false || strlen($raw) < self::IV_LENGTH + self::TAG_LENGTH + 1) {
            return false;
        }
        return openssl_decrypt(
            substr($raw, self::IV_LENGTH + self::TAG_LENGTH),
            self::CIPHER,
            $this->key,
            OPENSSL_RAW_DATA,
            substr($raw, 0, self::IV_LENGTH),
            substr($raw, self::IV_LENGTH, self::TAG_LENGTH)
        ) !== false;
    }

    /**
     * Check if a string looks like a valid ciphertext produced by this service.
     * Useful for validating before attempting decrypt.
     */
    public function isEncrypted(string $value): bool
    {
        if (empty($value)) {
            return false;
        }
        $raw = base64_decode($value, strict: true);
        return $raw !== false && strlen($raw) >= self::IV_LENGTH + self::TAG_LENGTH + 1;
    }

    // =========================================================================
    // PRIVATE — KEY DERIVATION
    // =========================================================================

    /**
     * Derive the final 32-byte AES key from two halves.
     *
     * Half A: from .env → ENCRYPTION_KEY_A
     * Half B: from .env → ENCRYPTION_KEY_FILE (path to a file containing key B)
     *         OR from .env → ENCRYPTION_KEY_B (fallback for local dev only)
     *
     * @throws \RuntimeException if keys are missing or unreadable
     */
    private function deriveKey(): string
    {
        // --- Half A ---
        $keyA = $_ENV['ENCRYPTION_KEY_A'] ?? getenv('ENCRYPTION_KEY_A') ?? '';
        if (empty($keyA)) {
            throw new \RuntimeException(
                'Encryption not configured: ENCRYPTION_KEY_A missing from .env'
            );
        }

        // --- Half B (file path preferred, .env fallback for local dev) ---
        $keyFilePath = $_ENV['ENCRYPTION_KEY_FILE'] ?? getenv('ENCRYPTION_KEY_FILE') ?? '';
        if (!empty($keyFilePath)) {
            if (!is_readable($keyFilePath)) {
                throw new \RuntimeException(
                    "Encryption key file not readable: {$keyFilePath}"
                );
            }
            $keyB = trim(file_get_contents($keyFilePath));
        } else {
            // Fallback: read from .env directly (local dev only)
            $keyB = $_ENV['ENCRYPTION_KEY_B'] ?? getenv('ENCRYPTION_KEY_B') ?? '';
        }

        if (empty($keyB)) {
            throw new \RuntimeException(
                'Encryption not configured: ENCRYPTION_KEY_B or ENCRYPTION_KEY_FILE missing.'
            );
        }

        // --- HKDF-SHA256 → 32-byte key ---
        $combined = $keyA . $keyB;
        $derived  = hash_hkdf('sha256', $combined, 32, 'basefare-crm-card-encryption');

        if ($derived === false || strlen($derived) !== 32) {
            throw new \RuntimeException('Key derivation failed.');
        }

        return $derived;
    }

    /**
     * Derive the PREVIOUS key, if a rotation is in progress.
     *
     * Set ENCRYPTION_KEY_A_OLD and ENCRYPTION_KEY_B_OLD (or
     * ENCRYPTION_KEY_FILE_OLD) alongside the new keys, run the rotation script,
     * then delete the _OLD entries. Returns null when they are absent, which is
     * the normal steady state.
     */
    private function deriveOldKey(): ?string
    {
        $keyA = $_ENV['ENCRYPTION_KEY_A_OLD'] ?? getenv('ENCRYPTION_KEY_A_OLD') ?: '';
        if (empty($keyA)) {
            return null;
        }

        $keyFilePath = $_ENV['ENCRYPTION_KEY_FILE_OLD'] ?? getenv('ENCRYPTION_KEY_FILE_OLD') ?: '';
        if (!empty($keyFilePath) && is_readable($keyFilePath)) {
            $keyB = trim(file_get_contents($keyFilePath));
        } else {
            $keyB = $_ENV['ENCRYPTION_KEY_B_OLD'] ?? getenv('ENCRYPTION_KEY_B_OLD') ?: '';
        }

        if (empty($keyB)) {
            return null;
        }

        $derived = hash_hkdf('sha256', $keyA . $keyB, 32, 'basefare-crm-card-encryption');
        return ($derived === false || strlen($derived) !== 32) ? null : $derived;
    }
}
