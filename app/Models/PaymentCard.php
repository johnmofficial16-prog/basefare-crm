<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * PaymentCard — AES-256-GCM encrypted card storage.
 *
 * SECURITY:
 *  - card_number_enc and cvv_enc are NEVER auto-decrypted
 *  - Agents see only: card_type, card_last_4, holder_name, expiry, billing_address
 *  - Admin decryption requires explicit EncryptionService call + password re-entry
 *  - Every reveal is logged in activity_log AND tracked on this model (reveal_count)
 *
 * @property int    $id
 * @property int    $transaction_id
 * @property string $card_type
 * @property string $card_last_4
 * @property string $holder_name
 * @property string $expiry             MM/YYYY
 * @property string $billing_address
 * @property string $card_number_enc    AES-256-GCM encrypted full PAN
 * @property string $cvv_enc            AES-256-GCM encrypted CVV
 * @property float  $amount             Amount charged to this card
 * @property bool   $is_primary
 * @property int    $last_revealed_by
 * @property string $last_revealed_at
 * @property int    $reveal_count
 */
class PaymentCard extends Model
{
    protected $table = 'payment_cards';
    public $timestamps = false;

    const CREATED_AT = 'created_at';
    const UPDATED_AT = null;

    protected $fillable = [
        'transaction_id',
        'card_type',
        'card_last_4',
        'holder_name',
        'expiry',
        'billing_address',
        'card_number_enc',
        'cvv_enc',
        'amount',
        'is_primary',
    ];

    protected $casts = [
        'amount'     => 'float',
        'is_primary' => 'boolean',
    ];

    /**
     * Fields that should NEVER appear in JSON / toArray() output.
     * Defense in depth — even if someone serializes the model.
     */
    protected $hidden = [
        'card_number_enc',
        'cvv_enc',
    ];

    // =========================================================================
    // Relationships
    // =========================================================================

    public function transaction()
    {
        return $this->belongsTo(Transaction::class, 'transaction_id');
    }

    // =========================================================================
    // Display helpers (safe — no decryption)
    // =========================================================================

    /**
     * Masked card number: ****1234
     */
    public function maskedNumber(): string
    {
        return '****' . $this->card_last_4;
    }

    /**
     * Display string: "Visa ****1234"
     */
    public function displayLabel(): string
    {
        return $this->card_type . ' ' . $this->maskedNumber();
    }

    /**
     * Formatted expiry: 12/2027 → Dec 2027
     */
    public function expiryFormatted(): string
    {
        $parts = explode('/', $this->expiry);
        if (count($parts) !== 2) {
            return $this->expiry;
        }
        $month = (int)$parts[0];
        $year  = $parts[1];
        $monthName = date('M', mktime(0, 0, 0, $month, 1));
        return $monthName . ' ' . $year;
    }

    // =========================================================================
    // Decryption (admin-only — explicit call required)
    // =========================================================================

    /**
     * Decrypt the full card number.
     * ONLY call this after admin password re-validation.
     *
     * @param \App\Services\EncryptionService $enc
     * @return string|null Full PAN or null if not stored
     */
    public function decryptNumber(\App\Services\EncryptionService $enc): ?string
    {
        if (empty($this->card_number_enc)) {
            return null;
        }
        return $enc->decrypt($this->card_number_enc);
    }

    /**
     * Decrypt the CVV.
     * ONLY call this after admin password re-validation.
     *
     * @param \App\Services\EncryptionService $enc
     * @return string|null CVV or null if not stored
     */
    public function decryptCvv(\App\Services\EncryptionService $enc): ?string
    {
        if (empty($this->cvv_enc)) {
            return null;
        }
        return $enc->decrypt($this->cvv_enc);
    }

    /**
     * Record that an admin revealed this card's data.
     * Called by TransactionService after successful reveal.
     */
    public function recordReveal(int $adminId): void
    {
        $this->last_revealed_by = $adminId;
        $this->last_revealed_at = date('Y-m-d H:i:s');
        $this->reveal_count     = ($this->reveal_count ?? 0) + 1;
        $this->save();
    }
}
