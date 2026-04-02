<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Carbon\Carbon;

/**
 * AcceptanceRequest
 *
 * Represents a customer payment authorization & e-signature request.
 * Each record maps to one tokenized public link sent to a customer.
 *
 * Token expiry: 12 hours from created_at
 * Public URL:   https://base-fare.com/auth?token={token}
 */
class AcceptanceRequest extends Model
{
    protected $table = 'acceptance_requests';

    // =========================================================================
    // Company display constants
    // =========================================================================
    const COMPANY_NAME       = 'Lets Fly Travel DBA Base Fare';
    const COMPANY_SHORT      = 'Lets Fly Travel';
    const COMPANY_DBA        = 'Base Fare';
    const COMPANY_EMAIL      = 'support@base-fare.com';   // TODO: update from .env
    const COMPANY_PHONE      = '+1 (888) XXX-XXXX';       // TODO: update from .env

    // =========================================================================
    // Transaction type constants
    // =========================================================================
    const TYPE_NEW_BOOKING      = 'new_booking';
    const TYPE_EXCHANGE         = 'exchange';
    const TYPE_CANCEL_REFUND    = 'cancel_refund';
    const TYPE_CANCEL_CREDIT    = 'cancel_credit';
    const TYPE_SEAT_PURCHASE    = 'seat_purchase';
    const TYPE_CABIN_UPGRADE    = 'cabin_upgrade';
    const TYPE_NAME_CORRECTION  = 'name_correction';
    const TYPE_OTHER            = 'other';

    // =========================================================================
    // Status constants
    // =========================================================================
    const STATUS_PENDING    = 'PENDING';
    const STATUS_APPROVED   = 'APPROVED';
    const STATUS_EXPIRED    = 'EXPIRED';
    const STATUS_CANCELLED  = 'CANCELLED';

    // =========================================================================
    // Email status constants
    // =========================================================================
    const EMAIL_PENDING = 'PENDING';
    const EMAIL_SENT    = 'SENT';
    const EMAIL_FAILED  = 'FAILED';
    const EMAIL_RESENT  = 'RESENT';

    // =========================================================================
    // Fillable fields
    // =========================================================================
    protected $fillable = [
        'token',
        'transaction_id',
        'type',
        'status',
        'customer_name',
        'customer_email',
        'customer_phone',
        'pnr',
        'airline',
        'order_id',
        'passengers',
        'flight_data',
        'fare_breakdown',
        'total_amount',
        'currency',
        'split_charge_note',
        'extra_data',
        'statement_descriptor',
        'card_type',
        'cardholder_name',
        'card_last_four',
        'billing_address',
        'additional_cards',
        'endorsements',
        'baggage_info',
        'fare_rules',
        'policy_text',
        'req_passport',
        'req_cc_front',
        'agent_id',
        'agent_notes',
        'expires_at',
        'ip_address',
        'device_fingerprint',
        'user_agent',
        'viewed_at',
        'approved_at',
        'digital_signature',
        'passport_image',
        'card_image_front',
        'email_status',
        'email_attempts',
        'last_emailed_at',
    ];

    // =========================================================================
    // Type casts
    // =========================================================================
    protected $casts = [
        'passengers'        => 'array',
        'flight_data'       => 'array',
        'fare_breakdown'    => 'array',
        'extra_data'        => 'array',
        'additional_cards'  => 'array',
        'total_amount'      => 'float',
        'req_passport'      => 'boolean',
        'req_cc_front'      => 'boolean',
        'expires_at'        => 'datetime',
        'viewed_at'         => 'datetime',
        'approved_at'       => 'datetime',
        'last_emailed_at'   => 'datetime',
    ];

    // =========================================================================
    // Relationships
    // =========================================================================

    /**
     * The agent who created this acceptance request
     */
    public function agent()
    {
        return $this->belongsTo(User::class, 'agent_id');
    }

    /**
     * The linked transaction record (if created from Transaction Recorder)
     * Will be wired up when Transaction Recorder module is built.
     */
    // public function transaction()
    // {
    //     return $this->belongsTo(Transaction::class, 'transaction_id');
    // }

    // =========================================================================
    // Scopes
    // =========================================================================

    public function scopePending($q)
    {
        return $q->where('status', self::STATUS_PENDING);
    }

    public function scopeApproved($q)
    {
        return $q->where('status', self::STATUS_APPROVED);
    }

    public function scopeExpired($q)
    {
        return $q->where('status', self::STATUS_EXPIRED);
    }

    public function scopeCancelled($q)
    {
        return $q->where('status', self::STATUS_CANCELLED);
    }

    public function scopeForAgent($q, int $agentId)
    {
        return $q->where('agent_id', $agentId);
    }

    public function scopeByPnr($q, string $pnr)
    {
        return $q->where('pnr', strtoupper(trim($pnr)));
    }

    public function scopeByEmail($q, string $email)
    {
        return $q->where('customer_email', strtolower(trim($email)));
    }

    /**
     * Returns acceptance requests that are pending but have expired their 12h window.
     * Run via a scheduled job to mark them EXPIRED.
     */
    public function scopeExpiredAndPending($q)
    {
        return $q->where('status', self::STATUS_PENDING)
                 ->where('expires_at', '<', Carbon::now());
    }

    // =========================================================================
    // Status Helpers
    // =========================================================================

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function isApproved(): bool
    {
        return $this->status === self::STATUS_APPROVED;
    }

    public function isExpired(): bool
    {
        return $this->status === self::STATUS_EXPIRED
            || ($this->isPending() && Carbon::now()->gt($this->expires_at));
    }

    public function isCancelled(): bool
    {
        return $this->status === self::STATUS_CANCELLED;
    }

    public function isActionable(): bool
    {
        return $this->isPending() && !$this->isExpired();
    }

    // =========================================================================
    // URL & Token Helpers
    // =========================================================================

    /**
     * The public customer-facing URL for this acceptance request
     */
    public function publicUrl(): string
    {
        return 'https://base-fare.com/auth?token=' . $this->token;
    }

    /**
     * Human-readable expiry — e.g. "Expires in 4h 22m" or "Expired"
     */
    public function expiryLabel(): string
    {
        if (!$this->isPending()) {
            return '';
        }

        $now    = Carbon::now();
        $expiry = $this->expires_at;

        if ($now->gt($expiry)) {
            return 'Expired';
        }

        $diff    = $now->diff($expiry);
        $hours   = $diff->h + ($diff->days * 24);
        $minutes = $diff->i;

        if ($hours > 0) {
            return "Expires in {$hours}h {$minutes}m";
        }

        return "Expires in {$minutes}m";
    }

    // =========================================================================
    // Type Label Helpers
    // =========================================================================

    /**
     * Human-readable type label for display in UI and emails
     */
    public function typeLabel(): string
    {
        return match($this->type) {
            self::TYPE_NEW_BOOKING      => 'New Booking',
            self::TYPE_EXCHANGE         => 'Flight Exchange / Date Change',
            self::TYPE_CANCEL_REFUND    => 'Cancellation & Refund',
            self::TYPE_CANCEL_CREDIT    => 'Cancellation & Future Credit',
            self::TYPE_SEAT_PURCHASE    => 'Seat Purchase',
            self::TYPE_CABIN_UPGRADE    => 'Cabin Upgrade',
            self::TYPE_NAME_CORRECTION  => 'Name Correction',
            self::TYPE_OTHER            => 'Authorization Request',
            default                     => 'Authorization Request',
        };
    }

    /**
     * Short verb label for email subjects
     */
    public function typeActionLabel(): string
    {
        return match($this->type) {
            self::TYPE_NEW_BOOKING      => 'review and authorize your new flight booking',
            self::TYPE_EXCHANGE         => 'authorize your flight change',
            self::TYPE_CANCEL_REFUND    => 'authorize your cancellation and refund',
            self::TYPE_CANCEL_CREDIT    => 'authorize your cancellation and future travel credit',
            self::TYPE_SEAT_PURCHASE    => 'authorize your seat selection',
            self::TYPE_CABIN_UPGRADE    => 'authorize your cabin upgrade',
            self::TYPE_NAME_CORRECTION  => 'authorize the name correction on your booking',
            self::TYPE_OTHER            => 'review and authorize this request',
            default                     => 'review and authorize this request',
        };
    }

    /**
     * Whether this type requires showing old flights section
     */
    public function hasOldFlights(): bool
    {
        return in_array($this->type, [
            self::TYPE_EXCHANGE,
            self::TYPE_CANCEL_REFUND,
            self::TYPE_CANCEL_CREDIT,
        ]);
    }

    /**
     * Whether this type requires showing new flights section
     */
    public function hasNewFlights(): bool
    {
        return $this->type === self::TYPE_EXCHANGE;
    }

    /**
     * Whether this type shows a standard itinerary (single flight list)
     */
    public function hasItinerary(): bool
    {
        return in_array($this->type, [
            self::TYPE_NEW_BOOKING,
            self::TYPE_SEAT_PURCHASE,
            self::TYPE_CABIN_UPGRADE,
            self::TYPE_NAME_CORRECTION,
        ]);
    }

    // =========================================================================
    // Computed Getters
    // =========================================================================

    /**
     * Calculate total from fare_breakdown line items (for display consistency)
     */
    public function calculatedTotal(): float
    {
        if (empty($this->fare_breakdown) || !is_array($this->fare_breakdown)) {
            return (float) $this->total_amount;
        }

        return array_sum(array_column($this->fare_breakdown, 'amount'));
    }

    /**
     * Masked card display — e.g. "**** **** **** 7979"
     */
    public function maskedCard(): string
    {
        if (empty($this->card_last_four)) {
            return '****';
        }
        return '**** **** **** ' . $this->card_last_four;
    }

    // =========================================================================
    // Airline Logo Helper
    // =========================================================================

    /**
     * Get a publicly accessible airline logo URL by IATA code.
     *
     * Uses Google's gstatic flight logo CDN — no API key required, highly reliable.
     * Falls back to a generic airplane emoji if the IATA code is empty.
     *
     * Usage:
     *   AcceptanceRequest::airlineLogoUrl('LH')  // Lufthansa
     *   AcceptanceRequest::airlineLogoUrl('AA')  // American Airlines
     *   AcceptanceRequest::airlineLogoUrl('EK')  // Emirates
     *
     * @param string $iataCode  2-letter airline IATA code (e.g. 'LH', 'AA', 'EK')
     * @param int    $size      Logo size in px: 35, 70, or 140
     */
    public static function airlineLogoUrl(string $iataCode, int $size = 70): string
    {
        $code = strtoupper(trim($iataCode));
        if (empty($code)) {
            return '';
        }
        // Google's gstatic airline logos — same source used by Google Flights
        // Available sizes: 35px, 70px, 140px
        $validSizes = [35, 70, 140];
        $sz = in_array($size, $validSizes) ? $size : 70;
        return "https://www.gstatic.com/flights/airline_logos/{$sz}px/{$code}.png";
    }

    /**
     * Extract the airline IATA code from a flight number string.
     * e.g. 'LH419' → 'LH',  'AA 100' → 'AA',  'EK204' → 'EK'
     */
    public static function iataFromFlightNumber(string $flightNumber): string
    {
        $clean = strtoupper(preg_replace('/\s+/', '', $flightNumber));
        // Most flight numbers start with 2-letter IATA code
        if (preg_match('/^([A-Z]{2})\d/', $clean, $m)) {
            return $m[1];
        }
        // Some airlines use 3-letter ICAO codes
        if (preg_match('/^([A-Z]{3})\d/', $clean, $m)) {
            return $m[1];
        }
        return '';
    }
}
