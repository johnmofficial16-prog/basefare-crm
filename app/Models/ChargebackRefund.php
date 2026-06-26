<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * ChargebackRefund — an admin-entered chargeback or refund event, tagged to a
 * centre. Manually recorded (this data is not yet captured automatically) and
 * bifurcated per centre on the Chargebacks & Refunds dashboard.
 *
 * @property int         $id
 * @property string      $centre        DMR | MOH | JSR
 * @property string      $kind          chargeback | refund
 * @property string      $event_date    Y-m-d
 * @property float       $amount
 * @property string      $currency
 * @property string|null $pnr
 * @property string|null $customer_name
 * @property string|null $reason
 * @property string|null $outcome       pending | won | lost | processed
 * @property string|null $notes
 * @property int         $created_by
 */
class ChargebackRefund extends Model
{
    protected $table = 'chargeback_refunds';

    const KIND_CHARGEBACK = 'chargeback';
    const KIND_REFUND     = 'refund';
    const KINDS = [self::KIND_CHARGEBACK, self::KIND_REFUND];

    const OUTCOMES = ['pending', 'won', 'lost', 'processed'];

    protected $fillable = [
        'centre', 'kind', 'event_date', 'amount', 'currency',
        'pnr', 'customer_name', 'reason', 'outcome', 'notes', 'created_by',
    ];

    protected $casts = [
        'event_date' => 'date',
        'amount'     => 'float',
    ];

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isChargeback(): bool
    {
        return $this->kind === self::KIND_CHARGEBACK;
    }
}
