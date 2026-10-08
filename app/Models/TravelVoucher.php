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
        'created_by',
        // Reissuance vouchers (2026_10_04_exchange_vouchers.sql)
        'source',
        'eticket_id',
        'transaction_id',
        'acceptance_id',
        'pdf_path',
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
