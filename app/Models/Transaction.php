<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\RecordNote;

/**
 * Transaction — Core transaction record.
 *
 * Immutability rules:
 *  - 'pending_review' → editable
 *  - 'approved'       → IMMUTABLE (void + new record for corrections)
 *  - 'voided'         → IMMUTABLE
 *
 * The JSON `data` column holds type-specific fields.
 * The `profit_mco` column is a MySQL generated column (total_amount - cost_amount).
 *
 * @property int    $id
 * @property int    $agent_id
 * @property int|null $acceptance_id
 * @property string $type
 * @property string $customer_name
 * @property string $customer_email
 * @property string $customer_phone
 * @property string $pnr
 * @property string $airline
 * @property string $order_id
 * @property string $travel_date
 * @property string $departure_time
 * @property string $return_date
 * @property float  $total_amount
 * @property float  $cost_amount
 * @property float  $profit_mco        (generated: total_amount - cost_amount)
 * @property string $currency
 * @property string $payment_method
 * @property string $payment_status
 * @property array  $data
 * @property string $status
 * @property string $void_reason
 * @property string $voided_at
 * @property int    $voided_by
 * @property int    $void_of_transaction_id
 * @property string $agent_notes
 * @property string $created_at
 * @property string $updated_at
 */
class Transaction extends Model
{
    protected $table = 'transactions';

    // =========================================================================
    // Transaction type constants
    // =========================================================================
    const TYPE_NEW_BOOKING      = 'new_booking';
    const TYPE_EXCHANGE         = 'exchange';
    const TYPE_SEAT_PURCHASE    = 'seat_purchase';
    const TYPE_CABIN_UPGRADE    = 'cabin_upgrade';
    const TYPE_CANCEL_REFUND    = 'cancel_refund';
    const TYPE_CANCEL_CREDIT    = 'cancel_credit';
    const TYPE_NAME_CORRECTION  = 'name_correction';
    const TYPE_OTHER            = 'other';

    // =========================================================================
    // Status constants
    // =========================================================================
    const STATUS_PENDING  = 'pending_review';
    const STATUS_APPROVED = 'approved';
    const STATUS_VOIDED   = 'voided';

    // =========================================================================
    // Payment method constants
    // =========================================================================
    const PAY_CREDIT_CARD   = 'credit_card';
    const PAY_DEBIT_CARD    = 'debit_card';
    const PAY_BANK_TRANSFER = 'bank_transfer';
    const PAY_CASH          = 'cash';
    const PAY_CREDIT_SHELL  = 'credit_shell';
    const PAY_CHEQUE        = 'cheque';
    const PAY_OTHER         = 'other';

    // =========================================================================
    // Payment status constants
    // =========================================================================
    const PAYMENT_PENDING  = 'pending';
    const PAYMENT_PAID     = 'paid';
    const PAYMENT_PARTIAL  = 'partial';
    const PAYMENT_REFUNDED = 'refunded';
    const PAYMENT_CREDITED = 'credited';

    // =========================================================================
    // Fillable fields
    // =========================================================================
    protected $fillable = [
        'agent_id',
        'acceptance_id',
        'type',
        'customer_name',
        'customer_email',
        'customer_phone',
        'pnr',
        'airline',
        'order_id',
        'travel_date',
        'departure_time',
        'return_date',
        'total_amount',
        'cost_amount',
        'profit_mco',
        'currency',
        'payment_method',
        'payment_status',
        'data',
        'status',
        'void_reason',
        'voided_at',
        'voided_by',
        'void_of_transaction_id',
        'checkin_notified',
        'checkin_completed',
        'proof_of_sale_path',
        'agent_notes',
    ];

    // =========================================================================
    // Casts
    // =========================================================================
    protected $casts = [
        'data'          => 'array',
        'total_amount'  => 'float',
        'cost_amount'   => 'float',
        'profit_mco'    => 'float',
        'acceptance_id' => 'integer',
        'agent_id'      => 'integer',
    ];

    // =========================================================================
    // Relationships
    // =========================================================================

    public function agent()
    {
        return $this->belongsTo(User::class, 'agent_id');
    }

    public function acceptance()
    {
        return $this->belongsTo(AcceptanceRequest::class, 'acceptance_id');
    }

    public function passengers()
    {
        return $this->hasMany(TransactionPassenger::class, 'transaction_id');
    }

    public function cards()
    {
        return $this->hasMany(PaymentCard::class, 'transaction_id');
    }

    public function primaryCard()
    {
        return $this->hasOne(PaymentCard::class, 'transaction_id')->where('is_primary', 1);
    }

    /**
     * If this transaction was voided, get the original it was voiding.
     */
    public function voidOf()
    {
        return $this->belongsTo(self::class, 'void_of_transaction_id');
    }

    /**
     * Get the reversal transaction that voided this one (if any).
     */
    public function reversal()
    {
        return $this->hasOne(self::class, 'void_of_transaction_id');
    }

    public function voidedByUser()
    {
        return $this->belongsTo(User::class, 'voided_by');
    }

    /**
     * All notes / activity log entries for this transaction.
     */
    public function notes()
    {
        return $this->hasMany(RecordNote::class, 'entity_id')
                    ->where('entity_type', 'transaction')
                    ->orderBy('created_at', 'asc');
    }

    // =========================================================================
    // Status helpers
    // =========================================================================

    public function isEditable(bool $isAdmin = false): bool
    {
        if ($this->status === self::STATUS_VOIDED) {
            return false;
        }
        return $isAdmin === true;
    }

    public function isImmutable(): bool
    {
        return in_array($this->status, [self::STATUS_APPROVED, self::STATUS_VOIDED]);
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function isApproved(): bool
    {
        return $this->status === self::STATUS_APPROVED;
    }

    public function isVoided(): bool
    {
        return $this->status === self::STATUS_VOIDED;
    }

    // =========================================================================
    // Display helpers
    // =========================================================================

    /**
     * Human-readable type label.
     */
    public function typeLabel(): string
    {
        return match ($this->type) {
            self::TYPE_NEW_BOOKING     => 'New Booking',
            self::TYPE_EXCHANGE        => 'Exchange / Date Change',
            self::TYPE_SEAT_PURCHASE   => 'Seat Purchase',
            self::TYPE_CABIN_UPGRADE   => 'Cabin Upgrade',
            self::TYPE_CANCEL_REFUND   => 'Cancellation & Refund',
            self::TYPE_CANCEL_CREDIT   => 'Cancellation & Future Credit',
            self::TYPE_NAME_CORRECTION => 'Name Correction',
            self::TYPE_OTHER           => 'Other',
            default                    => ucfirst(str_replace('_', ' ', $this->type)),
        };
    }

    /**
     * Short type badge text.
     */
    public function typeBadge(): string
    {
        return match ($this->type) {
            self::TYPE_NEW_BOOKING     => 'Booking',
            self::TYPE_EXCHANGE        => 'Exchange',
            self::TYPE_SEAT_PURCHASE   => 'Seat',
            self::TYPE_CABIN_UPGRADE   => 'Upgrade',
            self::TYPE_CANCEL_REFUND   => 'Refund',
            self::TYPE_CANCEL_CREDIT   => 'Credit',
            self::TYPE_NAME_CORRECTION => 'Name',
            self::TYPE_OTHER           => 'Other',
            default                    => 'Other',
        };
    }

    /**
     * Status badge configuration [label, color classes].
     */
    public function statusBadge(): array
    {
        return match ($this->status) {
            self::STATUS_PENDING  => ['Pending Review', 'bg-amber-100 text-amber-800'],
            self::STATUS_APPROVED => ['Approved', 'bg-emerald-100 text-emerald-800'],
            self::STATUS_VOIDED   => ['Voided', 'bg-red-100 text-red-800'],
            default               => ['Unknown', 'bg-gray-100 text-gray-800'],
        };
    }

    /**
     * Payment status badge configuration.
     */
    public function paymentBadge(): array
    {
        return match ($this->payment_status) {
            self::PAYMENT_PENDING  => ['Pending', 'bg-amber-100 text-amber-700'],
            self::PAYMENT_PAID     => ['Paid', 'bg-emerald-100 text-emerald-700'],
            self::PAYMENT_PARTIAL  => ['Partial', 'bg-blue-100 text-blue-700'],
            self::PAYMENT_REFUNDED => ['Refunded', 'bg-orange-100 text-orange-700'],
            self::PAYMENT_CREDITED => ['Credited', 'bg-violet-100 text-violet-700'],
            default                => ['Unknown', 'bg-gray-100 text-gray-700'],
        };
    }

    /**
     * Payment method label.
     */
    public function paymentMethodLabel(): string
    {
        return match ($this->payment_method) {
            self::PAY_CREDIT_CARD   => 'Credit Card',
            self::PAY_DEBIT_CARD    => 'Debit Card',
            self::PAY_BANK_TRANSFER => 'Bank Transfer',
            self::PAY_CASH          => 'Cash',
            self::PAY_CREDIT_SHELL  => 'Credit Shell',
            self::PAY_CHEQUE        => 'Cheque',
            self::PAY_OTHER         => 'Other',
            default                 => ucfirst(str_replace('_', ' ', $this->payment_method)),
        };
    }

    /**
     * Formatted profit / MCO (always computed).
     */
    public function formattedMco(): string
    {
        $prefix = $this->profit_mco >= 0 ? '+' : '-';
        return $prefix . $this->currency . ' ' . number_format(abs($this->profit_mco), 2);
    }

    // =========================================================================
    // Scopes for list filtering
    // =========================================================================

    public function scopeForAgent($query, int $agentId)
    {
        return $query->where('agent_id', $agentId);
    }

    public function scopeByType($query, string $type)
    {
        return $query->where('type', $type);
    }

    public function scopeByStatus($query, string $status)
    {
        return $query->where('status', $status);
    }

    public function scopeByPnr($query, string $pnr)
    {
        return $query->where('pnr', 'LIKE', '%' . strtoupper(trim($pnr)) . '%');
    }

    public function scopeByDateRange($query, ?string $from, ?string $to)
    {
        if ($from) {
            $query->where('created_at', '>=', $from . ' 00:00:00');
        }
        if ($to) {
            $query->where('created_at', '<=', $to . ' 23:59:59');
        }
        return $query;
    }

    // =========================================================================
    // Type-specific data helpers
    // =========================================================================

    /**
     * Get all available types as [value => label] for dropdowns.
     */
    public static function typeOptions(): array
    {
        return [
            self::TYPE_NEW_BOOKING     => 'New Booking',
            self::TYPE_EXCHANGE        => 'Exchange / Date Change',
            self::TYPE_SEAT_PURCHASE   => 'Seat Purchase',
            self::TYPE_CABIN_UPGRADE   => 'Cabin Upgrade',
            self::TYPE_CANCEL_REFUND   => 'Cancellation & Refund',
            self::TYPE_CANCEL_CREDIT   => 'Cancellation & Future Credit',
            self::TYPE_NAME_CORRECTION => 'Name Correction',
            self::TYPE_OTHER           => 'Other',
        ];
    }

    public static function paymentMethodOptions(): array
    {
        return [
            self::PAY_CREDIT_CARD   => 'Credit Card',
            self::PAY_DEBIT_CARD    => 'Debit Card',
            self::PAY_BANK_TRANSFER => 'Bank Transfer',
            self::PAY_CASH          => 'Cash',
            self::PAY_CREDIT_SHELL  => 'Credit Shell',
            self::PAY_CHEQUE        => 'Cheque',
            self::PAY_OTHER         => 'Other',
        ];
    }

    public static function paymentStatusOptions(): array
    {
        return [
            self::PAYMENT_PENDING  => 'Pending',
            self::PAYMENT_PAID     => 'Paid',
            self::PAYMENT_PARTIAL  => 'Partial',
            self::PAYMENT_REFUNDED => 'Refunded',
            self::PAYMENT_CREDITED => 'Credited',
        ];
    }
}
