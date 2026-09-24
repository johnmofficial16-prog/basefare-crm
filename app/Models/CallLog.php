<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * CallLog — one customer call, logged by the agent who took it.
 *
 * The client's required fields are the customer's number, reason for the call
 * and airline. Outcome is added so the log is useful to the agent (follow-up
 * reminders, conversion) and to managers (what the calls turned into).
 *
 * @property int         $id
 * @property int         $agent_id
 * @property string      $shift_date
 * @property string      $direction
 * @property string      $phone
 * @property string      $phone_digits
 * @property string|null $customer_name
 * @property string      $reason
 * @property string      $airline
 * @property string      $outcome
 * @property int|null    $transaction_id
 * @property \Carbon\Carbon|null $follow_up_at
 * @property bool        $follow_up_done
 * @property string|null $notes
 */
class CallLog extends Model
{
    protected $table = 'call_logs';

    protected $fillable = [
        'agent_id',
        'shift_date',
        'direction',
        'phone',
        'phone_digits',
        'customer_name',
        'reason',
        'airline',
        'outcome',
        'transaction_id',
        'follow_up_at',
        'follow_up_notified_at',
        'follow_up_done',
        'notes',
    ];

    protected $casts = [
        'agent_id'              => 'integer',
        'transaction_id'        => 'integer',
        'follow_up_at'          => 'datetime',
        'follow_up_notified_at' => 'datetime',
        'follow_up_done'        => 'boolean',
    ];

    /** key => [label, material icon]. Order is the order agents see the chips. */
    const REASONS = [
        'new_booking'     => ['New booking',        'flight_takeoff'],
        'price_enquiry'   => ['Price enquiry',      'sell'],
        'exchange'        => ['Change / date swap', 'swap_horiz'],
        'cancellation'    => ['Cancellation',       'cancel'],
        'refund_status'   => ['Refund status',      'currency_exchange'],
        'booking_status'  => ['Booking / PNR check','confirmation_number'],
        'baggage'         => ['Baggage',            'luggage'],
        'seat'            => ['Seats',              'airline_seat_recline_extra'],
        'name_correction' => ['Name correction',    'badge'],
        'check_in'        => ['Check-in / boarding','how_to_reg'],
        'complaint'       => ['Complaint',          'report'],
        'other'           => ['Other',              'more_horiz'],
    ];

    /** key => [label, material icon, tailwind colour stem]. */
    const OUTCOMES = [
        'booked'         => ['Booked / sold',  'check_circle',  'emerald'],
        'follow_up'      => ['Call back later','schedule',      'amber'],
        'resolved'       => ['Helped / info',  'support_agent', 'blue'],
        'not_interested' => ['Not interested', 'thumb_down',    'slate'],
        'dropped'        => ['Call dropped',   'call_end',      'rose'],
    ];

    /** Most-called US carriers — shown as quick chips until the agent builds their own history. */
    const COMMON_AIRLINES = [
        'American', 'Delta', 'United', 'Southwest', 'Alaska', 'JetBlue',
        'Spirit', 'Frontier', 'Air Canada', 'Hawaiian',
    ];

    /** Longer list for the type-ahead. Free text is still accepted. */
    const AIRLINE_SUGGESTIONS = [
        'American', 'Delta', 'United', 'Southwest', 'Alaska', 'JetBlue', 'Spirit',
        'Frontier', 'Hawaiian', 'Allegiant', 'Sun Country', 'Breeze', 'Avelo',
        'Air Canada', 'WestJet', 'Porter', 'Aeromexico', 'Volaris', 'Viva Aerobus',
        'Copa', 'Avianca', 'LATAM', 'British Airways', 'Virgin Atlantic', 'Lufthansa',
        'Air France', 'KLM', 'Iberia', 'Aer Lingus', 'Turkish', 'Emirates', 'Qatar',
        'Etihad', 'Air India', 'Singapore', 'Cathay Pacific', 'Japan Airlines', 'ANA',
        'Korean Air', 'Qantas', 'Ethiopian', 'Caribbean', 'Multiple airlines', 'Not decided',
    ];

    const DIRECTION_IN  = 'inbound';
    const DIRECTION_OUT = 'outbound';

    // ─── Relationships ────────────────────────────────────────────────────────

    public function agent()
    {
        return $this->belongsTo(User::class, 'agent_id');
    }

    public function transaction()
    {
        return $this->belongsTo(Transaction::class, 'transaction_id');
    }

    // ─── Helpers ──────────────────────────────────────────────────────────────

    /** Digits only, last 10 — so "+1 (212) 555-0100" and "2125550100" match. */
    public static function normalisePhone(string $raw): string
    {
        $digits = preg_replace('/\D+/', '', $raw) ?? '';
        return strlen($digits) > 10 ? substr($digits, -10) : $digits;
    }

    public function reasonLabel(): string
    {
        return self::REASONS[$this->reason][0] ?? ucfirst(str_replace('_', ' ', $this->reason));
    }

    public function outcomeLabel(): string
    {
        return self::OUTCOMES[$this->outcome][0] ?? ucfirst(str_replace('_', ' ', $this->outcome));
    }

    public function outcomeColour(): string
    {
        return self::OUTCOMES[$this->outcome][2] ?? 'slate';
    }
}
