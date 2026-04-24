<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Carbon\Carbon;

/**
 * ETicket — Electronic Ticket record.
 *
 * Lifecycle: draft → sent → acknowledged
 *
 * Approval gate (enforced in ETicketService::canIssue):
 *   Transaction.status = 'approved' AND gateway_status = 'charge_successful'
 *
 * Public URL: /eticket?token={token}
 * No expiry — link is valid indefinitely.
 *
 * @property int    $id
 * @property string $token
 * @property int    $transaction_id
 * @property int|null $acceptance_id
 * @property int    $agent_id
 * @property string $customer_name
 * @property string $customer_email
 * @property string $customer_phone
 * @property string $pnr
 * @property string $airline
 * @property string $order_id
 * @property array  $ticket_data        [{pax_name, pax_type, ticket_number, seat, dob}]
 * @property array  $flight_data
 * @property array  $fare_breakdown
 * @property float  $total_amount
 * @property string $currency
 * @property string $endorsements
 * @property string $baggage_info
 * @property string $fare_rules
 * @property string $policy_text
 * @property array  $extra_data
 * @property string $agent_notes
 * @property string $status             draft|sent|acknowledged
 * @property string $email_status       PENDING|SENT|FAILED|RESENT
 * @property int    $email_attempts
 * @property string $last_emailed_at
 * @property string $sent_to_email
 * @property string $acknowledged_at
 * @property string $acknowledged_ip
 * @property string $acknowledged_ua
 * @property string $created_at
 * @property string $updated_at
 */
class ETicket extends Model
{
    protected $table = 'etickets';

    // =========================================================================
    // Status constants
    // =========================================================================
    const STATUS_DRAFT        = 'draft';
    const STATUS_SENT         = 'sent';
    const STATUS_ACKNOWLEDGED = 'acknowledged';

    // =========================================================================
    // Email status constants
    // =========================================================================
    const EMAIL_PENDING = 'PENDING';
    const EMAIL_SENT    = 'SENT';
    const EMAIL_FAILED  = 'FAILED';
    const EMAIL_RESENT  = 'RESENT';

    // =========================================================================
    // Company contact (mirrors AcceptanceRequest)
    // =========================================================================
    const COMPANY_EMAIL = 'reservation@base-fare.com';

    // =========================================================================
    // Fillable
    // =========================================================================
    protected $fillable = [
        'token',
        'transaction_id',
        'acceptance_id',
        'agent_id',
        'customer_name',
        'customer_email',
        'customer_phone',
        'pnr',
        'airline',
        'order_id',
        'ticket_data',
        'flight_data',
        'fare_breakdown',
        'total_amount',
        'currency',
        'endorsements',
        'baggage_info',
        'fare_rules',
        'policy_text',
        'extra_data',
        'agent_notes',
        'status',
        'email_status',
        'email_attempts',
        'last_emailed_at',
        'sent_to_email',
        'acknowledged_at',
        'acknowledged_ip',
        'acknowledged_ua',
    ];

    // =========================================================================
    // Casts
    // =========================================================================
    protected $casts = [
        'ticket_data'    => 'array',
        'flight_data'    => 'array',
        'fare_breakdown' => 'array',
        'extra_data'     => 'array',
        'total_amount'   => 'float',
        'transaction_id' => 'integer',
        'acceptance_id'  => 'integer',
        'agent_id'       => 'integer',
        'acknowledged_at'=> 'datetime',
        'last_emailed_at'=> 'datetime',
    ];

    // =========================================================================
    // Relationships
    // =========================================================================

    public function transaction()
    {
        return $this->belongsTo(Transaction::class, 'transaction_id');
    }

    public function acceptance()
    {
        return $this->belongsTo(AcceptanceRequest::class, 'acceptance_id');
    }

    public function agent()
    {
        return $this->belongsTo(User::class, 'agent_id');
    }

    // =========================================================================
    // Scopes
    // =========================================================================

    public function scopeForAgent($query, int $agentId)
    {
        return $query->where('agent_id', $agentId);
    }

    public function scopeByStatus($query, string $status)
    {
        return $query->where('status', $status);
    }

    public function scopeByPnr($query, string $pnr)
    {
        return $query->where('pnr', 'LIKE', '%' . $pnr . '%');
    }

    // =========================================================================
    // Status helpers
    // =========================================================================

    public function isDraft(): bool
    {
        return $this->status === self::STATUS_DRAFT;
    }

    public function isSent(): bool
    {
        return $this->status === self::STATUS_SENT;
    }

    public function isAcknowledged(): bool
    {
        return $this->status === self::STATUS_ACKNOWLEDGED;
    }

    // =========================================================================
    // Public URL
    // =========================================================================

    public function publicUrl(): string
    {
        $base = $_ENV['APP_URL'] ?? getenv('APP_URL');
        if (empty($base)) {
            $base = 'https://crm.base-fare.com';
        }
        if (!preg_match('~^(?:f|ht)tps?://~i', $base)) {
            $base = 'https://' . ltrim($base, '/');
        }
        $base = rtrim($base, '/');
        return $base . '/eticket?token=' . $this->token;
    }

    // =========================================================================
    // Display helpers
    // =========================================================================

    /**
     * Human-readable status label.
     */
    public function statusLabel(): string
    {
        return match($this->status) {
            self::STATUS_DRAFT        => 'Draft',
            self::STATUS_SENT         => 'Sent',
            self::STATUS_ACKNOWLEDGED => 'Acknowledged',
            default                   => ucfirst($this->status),
        };
    }

    /**
     * CSS color class for status badge (Tailwind-style).
     */
    public function statusColor(): string
    {
        return match($this->status) {
            self::STATUS_DRAFT        => 'gray',
            self::STATUS_SENT         => 'blue',
            self::STATUS_ACKNOWLEDGED => 'green',
            default                   => 'gray',
        };
    }

    /**
     * Airline IATA logo URL (mirroring AcceptanceRequest::airlineLogoUrl).
     */
    public static function airlineLogoUrl(string $iataCode, int $size = 70): string
    {
        $code = strtoupper(trim($iataCode));
        if (!$code || !preg_match('/^[A-Z0-9]{2,3}$/', $code)) {
            return '';
        }
        return "https://www.gstatic.com/flights/airline_logos/{$size}px/{$code}.png";
    }

    /**
     * Resolve airline IATA code from stored airline name or code.
     */
    public function resolvedIataCode(): string
    {
        $raw = strtoupper(trim($this->airline ?? ''));
        if (preg_match('/^[A-Z0-9]{2,3}$/', $raw)) {
            return $raw;
        }
        $map = [
            'Air Canada'=>'AC','WestJet'=>'WS','Air Transat'=>'TS',
            'American Airlines'=>'AA','Delta Air Lines'=>'DL','Delta'=>'DL',
            'United Airlines'=>'UA','Southwest Airlines'=>'WN','JetBlue'=>'B6',
            'Alaska Airlines'=>'AS','Frontier Airlines'=>'F9','Spirit Airlines'=>'NK',
            'British Airways'=>'BA','Lufthansa'=>'LH','Air France'=>'AF',
            'KLM'=>'KL','Swiss International'=>'LX','Austrian Airlines'=>'OS',
            'Brussels Airlines'=>'SN','Iberia'=>'IB','Vueling'=>'VY',
            'TAP Portugal'=>'TP','Ryanair'=>'FR','easyJet'=>'U2','Norwegian'=>'DY',
            'Turkish Airlines'=>'TK','LOT Polish Airlines'=>'LO',
            'Emirates'=>'EK','Qatar Airways'=>'QR','Etihad Airways'=>'EY',
            'flydubai'=>'FZ','Air Arabia'=>'G9','Oman Air'=>'WY',
            'Singapore Airlines'=>'SQ','Cathay Pacific'=>'CX',
            'Japan Airlines'=>'JL','ANA'=>'NH','Korean Air'=>'KE',
            'Asiana Airlines'=>'OZ','Thai Airways'=>'TG','Malaysia Airlines'=>'MH',
            'IndiGo'=>'6E','SpiceJet'=>'SG','Air India'=>'AI','Vistara'=>'UK',
            'Aeromexico'=>'AM','LATAM Airlines'=>'LA','Avianca'=>'AV','Copa Airlines'=>'CM',
            'Qantas'=>'QF','Air New Zealand'=>'NZ',
            'China Eastern'=>'MU','Air China'=>'CA','China Southern'=>'CZ',
            'Ethiopian Airlines'=>'ET','Kenya Airways'=>'KQ','Royal Air Maroc'=>'AT',
        ];
        foreach ($map as $name => $code) {
            if (stripos($this->airline ?? '', $name) !== false) {
                return $code;
            }
        }
        return '';
    }

    /**
     * Auto-generate a ticket number for a passenger if none was provided.
     * Format: BF-{transaction_id}-{index+1}
     */
    public function autoTicketNumber(int $paxIndex): string
    {
        return 'BF-' . str_pad($this->transaction_id, 6, '0', STR_PAD_LEFT) . '-' . ($paxIndex + 1);
    }

    /**
     * Get ticket_data with any missing ticket_numbers auto-filled.
     */
    public function ticketDataWithAutoNumbers(): array
    {
        $data = $this->ticket_data ?? [];
        foreach ($data as $i => &$pax) {
            if (empty($pax['ticket_number'])) {
                $pax['ticket_number'] = $this->autoTicketNumber($i);
            }
        }
        return $data;
    }
}
