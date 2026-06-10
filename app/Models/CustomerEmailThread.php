<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * CustomerEmailThread — one customer email conversation.
 *
 * Holds the participant + status; the actual emails live in
 * CustomerEmailMessage (one-to-many, ordered by created_at).
 *
 * @property int         $id
 * @property int         $agent_id
 * @property int|null    $transaction_id
 * @property string      $customer_name
 * @property string      $customer_email
 * @property string|null $subject
 * @property string      $status           open|awaiting_customer|awaiting_agent|closed
 * @property string|null $last_message_at
 * @property string      $created_at
 * @property string      $updated_at
 */
class CustomerEmailThread extends Model
{
    protected $table = 'customer_email_threads';

    const STATUS_OPEN              = 'open';
    const STATUS_AWAITING_CUSTOMER = 'awaiting_customer';
    const STATUS_AWAITING_AGENT    = 'awaiting_agent';
    const STATUS_CLOSED            = 'closed';

    protected $fillable = [
        'agent_id',
        'transaction_id',
        'customer_name',
        'customer_email',
        'subject',
        'status',
        'last_message_at',
    ];

    protected $casts = [
        'last_message_at' => 'datetime',
        'created_at'      => 'datetime',
        'updated_at'      => 'datetime',
    ];

    // ── Relationships ────────────────────────────────────────────────────────

    public function messages()
    {
        return $this->hasMany(CustomerEmailMessage::class, 'thread_id')->orderBy('created_at', 'asc');
    }

    public function agent()
    {
        return $this->belongsTo(User::class, 'agent_id');
    }

    public function transaction()
    {
        return $this->belongsTo(Transaction::class, 'transaction_id');
    }

    // ── Scopes ───────────────────────────────────────────────────────────────

    public function scopeForAgent($query, int $agentId)
    {
        return $query->where('agent_id', $agentId);
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    public function statusBadge(): array
    {
        return match ($this->status) {
            self::STATUS_AWAITING_AGENT    => ['label' => 'Needs reply', 'color' => 'amber'],
            self::STATUS_AWAITING_CUSTOMER => ['label' => 'Sent',        'color' => 'blue'],
            self::STATUS_CLOSED            => ['label' => 'Closed',      'color' => 'slate'],
            default                        => ['label' => 'Open',        'color' => 'emerald'],
        };
    }
}
