<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TravelVoucher extends Model
{
    protected $table = 'travel_vouchers';

    protected $fillable = [
        'voucher_no',
        'customer_name',
        'pnr',
        'ticket_number',
        'amount',
        'currency',
        'issue_date',
        'expiry_date',
        'reason',
        'terms',
        'status',
        'created_by'
    ];

    protected $casts = [
        'issue_date' => 'date',
        'expiry_date' => 'date',
        'amount' => 'float',
    ];

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
