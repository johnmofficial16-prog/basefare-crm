<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Notification — a generic in-app notification for the bell.
 *
 * Kept module-agnostic (a `type` + free-form title/body/link) so any feature
 * can drop a row here and have it surface in the shared bell / unread badge.
 * Booking reminders are the first producer.
 *
 * @property int    $id
 * @property int    $user_id
 * @property string $type
 * @property int|null $reminder_id
 * @property int|null $transaction_id
 * @property string $title
 * @property string|null $body
 * @property string|null $link
 * @property string|null $read_at
 * @property string $created_at
 */
class Notification extends Model
{
    protected $table = 'notifications';

    // Only created_at is maintained (no updated_at column).
    const UPDATED_AT = null;

    const TYPE_BOOKING_REMINDER = 'booking_reminder';

    protected $fillable = [
        'user_id',
        'type',
        'reminder_id',
        'transaction_id',
        'title',
        'body',
        'link',
        'read_at',
    ];

    protected $casts = [
        'user_id'        => 'integer',
        'reminder_id'    => 'integer',
        'transaction_id' => 'integer',
        'read_at'        => 'datetime',
    ];

    // ─── Relationships ────────────────────────────────────────────────────────

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function reminder()
    {
        return $this->belongsTo(BookingReminder::class, 'reminder_id');
    }

    // ─── Scopes ───────────────────────────────────────────────────────────────

    public function scopeForUser($query, int $userId)
    {
        return $query->where('user_id', $userId);
    }

    public function scopeUnread($query)
    {
        return $query->whereNull('read_at');
    }

    public function isRead(): bool
    {
        return $this->read_at !== null;
    }
}
