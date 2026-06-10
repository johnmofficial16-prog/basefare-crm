<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * CustomerEmailMessage — a single email within a thread.
 *
 * Outbound lifecycle: draft → pending_approval → approved → sent
 *                     (or → rejected / → failed). Inbound rows are 'received'.
 *
 * The AI step is fully audited: intent_prompt (what the agent asked),
 * ai_subject/ai_body (raw model output), and final_subject/final_body
 * (what was actually sent, after edits + approval) are stored separately.
 *
 * @property int         $id
 * @property int         $thread_id
 * @property string      $direction        outbound|inbound
 * @property string      $status
 * @property string|null $category
 * @property string|null $intent_prompt
 * @property string|null $ai_model
 * @property string|null $ai_subject
 * @property string|null $ai_body
 * @property string|null $final_subject
 * @property string      $final_body
 * @property int|null    $created_by
 * @property int|null    $approved_by
 * @property string|null $approved_at
 * @property string|null $rejected_reason
 * @property string|null $message_id
 * @property string|null $in_reply_to
 * @property string|null $sender_email
 * @property string|null $raw_headers
 * @property string|null $sent_at
 * @property string|null $sent_to
 * @property string|null $error
 * @property string      $created_at
 */
class CustomerEmailMessage extends Model
{
    protected $table      = 'customer_email_messages';
    public    $timestamps = false; // created_at via DB default; no updated_at column

    const DIR_OUTBOUND = 'outbound';
    const DIR_INBOUND  = 'inbound';

    const STATUS_DRAFT            = 'draft';
    const STATUS_PENDING_APPROVAL = 'pending_approval';
    const STATUS_APPROVED         = 'approved';
    const STATUS_SENT             = 'sent';
    const STATUS_FAILED           = 'failed';
    const STATUS_REJECTED         = 'rejected';
    const STATUS_RECEIVED         = 'received';

    protected $fillable = [
        'thread_id',
        'direction',
        'status',
        'category',
        'intent_prompt',
        'ai_model',
        'ai_subject',
        'ai_body',
        'final_subject',
        'final_body',
        'created_by',
        'approved_by',
        'approved_at',
        'rejected_reason',
        'message_id',
        'in_reply_to',
        'sender_email',
        'raw_headers',
        'sent_at',
        'sent_to',
        'error',
        'created_at',
    ];

    protected $casts = [
        'approved_at' => 'datetime',
        'sent_at'     => 'datetime',
        'created_at'  => 'datetime',
    ];

    // ── Relationships ────────────────────────────────────────────────────────

    public function thread()
    {
        return $this->belongsTo(CustomerEmailThread::class, 'thread_id');
    }

    public function author()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function approver()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    // ── Scopes ───────────────────────────────────────────────────────────────

    public function scopePendingApproval($query)
    {
        return $query->where('direction', self::DIR_OUTBOUND)
                     ->where('status', self::STATUS_PENDING_APPROVAL);
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    public function isInbound(): bool
    {
        return $this->direction === self::DIR_INBOUND;
    }

    /** True if the body still contains unfilled [[PLACEHOLDER: …]] markers. */
    public function hasPlaceholders(): bool
    {
        return (bool) preg_match('/\[\[\s*PLACEHOLDER/i', (string) $this->final_body);
    }
}
