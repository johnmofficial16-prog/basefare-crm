<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * TransactionPassenger — One row per passenger per transaction.
 *
 * @property int    $id
 * @property int    $transaction_id
 * @property string $first_name
 * @property string $last_name
 * @property string $dob
 * @property string $pax_type       adult|child|infant
 * @property string $ticket_number
 * @property string $frequent_flyer
 */
class TransactionPassenger extends Model
{
    protected $table = 'transaction_passengers';
    public $timestamps = false;  // only created_at, no updated_at

    const CREATED_AT = 'created_at';
    const UPDATED_AT = null;

    const PAX_ADULT  = 'adult';
    const PAX_CHILD  = 'child';
    const PAX_INFANT = 'infant';

    protected $fillable = [
        'transaction_id',
        'first_name',
        'last_name',
        'dob',
        'pax_type',
        'ticket_number',
        'frequent_flyer',
    ];

    // =========================================================================
    // Relationships
    // =========================================================================

    public function transaction()
    {
        return $this->belongsTo(Transaction::class, 'transaction_id');
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    public function fullName(): string
    {
        return trim($this->first_name . ' ' . $this->last_name);
    }

    public function paxLabel(): string
    {
        return match ($this->pax_type) {
            self::PAX_ADULT  => 'ADT',
            self::PAX_CHILD  => 'CHD',
            self::PAX_INFANT => 'INF',
            default          => 'ADT',
        };
    }

    public static function paxTypeOptions(): array
    {
        return [
            self::PAX_ADULT  => 'Adult',
            self::PAX_CHILD  => 'Child',
            self::PAX_INFANT => 'Infant',
        ];
    }
}
