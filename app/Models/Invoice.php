<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Invoice — customer-facing charge document (itinerary + charge breakdown).
 *
 * Lifecycle (enforced in InvoiceService, not SQL):
 *   pending_approval → approved → sent      (or → rejected)
 *   Agents/supervisors create in 'pending_approval'.
 *   Managers/admins create as 'approved' and may send immediately.
 *
 * Card safety: only the cardholder name + billing address are stored here.
 * The full card number / CVV are NEVER copied onto an invoice.
 *
 * @property int         $id
 * @property string      $invoice_no
 * @property int         $agent_id
 * @property int|null    $transaction_id
 * @property string      $purpose
 * @property string|null $purpose_other
 * @property string      $customer_name
 * @property string|null $customer_email
 * @property string|null $customer_phone
 * @property string|null $pnr
 * @property string|null $airline
 * @property string|null $cardholder_name
 * @property string|null $card_billing
 * @property array       $itinerary
 * @property float|null  $airline_charge
 * @property float       $base_fare_charge
 * @property float       $total_amount
 * @property string      $currency
 * @property string      $issue_date
 * @property string|null $notes
 * @property string      $status
 * @property string|null $rejected_reason
 * @property int|null    $approved_by
 * @property string|null $approved_at
 * @property string|null $sent_at
 * @property string|null $sent_to
 * @property string|null $send_error
 * @property int         $created_by
 */
class Invoice extends Model
{
    protected $table = 'invoices';

    // =========================================================================
    // Status constants
    // =========================================================================
    const STATUS_PENDING  = 'pending_approval';
    const STATUS_APPROVED = 'approved';
    const STATUS_SENT     = 'sent';
    const STATUS_REJECTED = 'rejected';

    // =========================================================================
    // Purpose constants
    // =========================================================================
    const PURPOSE_NEW_BOOKING = 'new_booking';
    const PURPOSE_CHANGES     = 'changes';
    const PURPOSE_SEAT_TYPE   = 'seat_type';
    const PURPOSE_OTHER       = 'other';

    protected $fillable = [
        'invoice_no',
        'agent_id',
        'transaction_id',
        'purpose',
        'purpose_other',
        'customer_name',
        'customer_email',
        'customer_phone',
        'pnr',
        'airline',
        'cardholder_name',
        'card_billing',
        'itinerary',
        'airline_charge',
        'base_fare_charge',
        'total_amount',
        'currency',
        'issue_date',
        'notes',
        'status',
        'rejected_reason',
        'approved_by',
        'approved_at',
        'sent_at',
        'sent_to',
        'send_error',
        'created_by',
    ];

    protected $casts = [
        'itinerary'        => 'array',
        'airline_charge'   => 'float',
        'base_fare_charge' => 'float',
        'total_amount'     => 'float',
        'issue_date'       => 'date',
        'approved_at'      => 'datetime',
        'sent_at'          => 'datetime',
        'transaction_id'   => 'integer',
        'agent_id'         => 'integer',
    ];

    // =========================================================================
    // Relationships
    // =========================================================================

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function agent()
    {
        return $this->belongsTo(User::class, 'agent_id');
    }

    public function approver()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function transaction()
    {
        return $this->belongsTo(Transaction::class, 'transaction_id');
    }

    public function notes()
    {
        return $this->hasMany(RecordNote::class, 'entity_id')
            ->where('entity_type', 'invoice')
            ->orderBy('created_at', 'asc');
    }

    // =========================================================================
    // Status helpers
    // =========================================================================

    public function isPendingApproval(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function isApproved(): bool
    {
        return $this->status === self::STATUS_APPROVED;
    }

    public function isSent(): bool
    {
        return $this->status === self::STATUS_SENT;
    }

    public function isRejected(): bool
    {
        return $this->status === self::STATUS_REJECTED;
    }

    /**
     * Editable only while pending approval (managers/admins may also edit their
     * own approved-but-not-yet-sent invoices).
     */
    public function isEditable(bool $isApprover = false): bool
    {
        if ($this->status === self::STATUS_PENDING) {
            return true;
        }
        return $isApprover && $this->status === self::STATUS_APPROVED;
    }

    // =========================================================================
    // Display helpers
    // =========================================================================

    public function purposeLabel(): string
    {
        return match ($this->purpose) {
            self::PURPOSE_NEW_BOOKING => 'New Booking',
            self::PURPOSE_CHANGES     => 'Changes',
            self::PURPOSE_SEAT_TYPE   => 'Seat Type',
            self::PURPOSE_OTHER       => $this->purpose_other ?: 'Other',
            default                   => ucfirst(str_replace('_', ' ', (string) $this->purpose)),
        };
    }

    /**
     * Status badge [label, tailwind classes].
     */
    public function statusBadge(): array
    {
        return match ($this->status) {
            self::STATUS_PENDING  => ['Pending Approval', 'bg-amber-100 text-amber-800'],
            self::STATUS_APPROVED => ['Approved',         'bg-blue-100 text-blue-800'],
            self::STATUS_SENT     => ['Sent',             'bg-emerald-100 text-emerald-800'],
            self::STATUS_REJECTED => ['Rejected',         'bg-rose-100 text-rose-800'],
            default               => ['Unknown',          'bg-gray-100 text-gray-700'],
        };
    }

    public function formattedTotal(): string
    {
        return $this->currency . ' ' . number_format((float) $this->total_amount, 2);
    }

    // =========================================================================
    // Option lists
    // =========================================================================

    public static function purposeOptions(): array
    {
        return [
            self::PURPOSE_NEW_BOOKING => 'New Booking',
            self::PURPOSE_CHANGES     => 'Changes',
            self::PURPOSE_SEAT_TYPE   => 'Seat Type',
            self::PURPOSE_OTHER       => 'Other',
        ];
    }

    // =========================================================================
    // Scopes
    // =========================================================================

    public function scopePendingApproval($query)
    {
        return $query->where('status', self::STATUS_PENDING);
    }
}
