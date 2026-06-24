<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * MarketingSpend — admin-entered marketing cost per centre over a date range.
 * The analytics dashboard prorates overlapping spend into the selected window.
 *
 * @property int    $id
 * @property string $centre        DMR | MOH | JSR
 * @property string $period_start  Y-m-d
 * @property string $period_end    Y-m-d
 * @property float  $amount
 * @property string $currency
 * @property string|null $channel
 * @property string|null $notes
 * @property int    $created_by
 */
class MarketingSpend extends Model
{
    protected $table = 'marketing_spend';

    protected $fillable = [
        'centre', 'period_start', 'period_end', 'amount',
        'currency', 'channel', 'notes', 'created_by',
    ];

    protected $casts = [
        'period_start' => 'date',
        'period_end'   => 'date',
        'amount'       => 'float',
    ];

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
