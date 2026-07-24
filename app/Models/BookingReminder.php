<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Carbon\Carbon;

/**
 * BookingReminder — a reminder an admin/manager attaches to a booking.
 *
 * Fires at `remind_at` (either an offset before the confirmed `departure_at`,
 * or an absolute time). The dispatcher (cron/booking_reminders_dispatch.php)
 * materialises one Notification per recipient when a scheduled reminder is due.
 *
 * @property int    $id
 * @property int    $transaction_id
 * @property int    $created_by
 * @property string $title
 * @property string|null $message
 * @property string $departure_at
 * @property string $timing_mode      'preset' | 'absolute'
 * @property int|null $offset_hours
 * @property string $remind_at
 * @property string $status           'scheduled' | 'fired' | 'dismissed' | 'cancelled'
 * @property string|null $fired_at
 */
class BookingReminder extends Model
{
    protected $table = 'booking_reminders';

    // Timing modes
    const MODE_PRESET   = 'preset';
    const MODE_ABSOLUTE = 'absolute';

    // Status constants
    const STATUS_SCHEDULED = 'scheduled';
    const STATUS_FIRED     = 'fired';
    const STATUS_DISMISSED = 'dismissed';
    const STATUS_CANCELLED = 'cancelled';

    /** Preset offsets (hours before departure) offered in the UI. */
    const PRESET_OFFSETS = [72, 48, 24];

    protected $fillable = [
        'transaction_id',
        'created_by',
        'title',
        'message',
        'departure_at',
        'timing_mode',
        'offset_hours',
        'remind_at',
        'status',
        'fired_at',
    ];

    protected $casts = [
        'transaction_id' => 'integer',
        'created_by'     => 'integer',
        'offset_hours'   => 'integer',
        'departure_at'   => 'datetime',
        'remind_at'      => 'datetime',
        'fired_at'       => 'datetime',
    ];

    // ─── Relationships ────────────────────────────────────────────────────────

    public function transaction()
    {
        return $this->belongsTo(Transaction::class, 'transaction_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function notifications()
    {
        return $this->hasMany(Notification::class, 'reminder_id');
    }

    // ─── Helpers ──────────────────────────────────────────────────────────────

    public function isScheduled(): bool
    {
        return $this->status === self::STATUS_SCHEDULED;
    }

    public function isFired(): bool
    {
        return $this->status === self::STATUS_FIRED;
    }

    /** True once the fire time has passed but it hasn't been dispatched yet. */
    public function isOverdue(): bool
    {
        return $this->isScheduled() && $this->remind_at && $this->remind_at->isPast();
    }

    /** Status badge [label, tailwind classes]. */
    public function statusBadge(): array
    {
        return match ($this->status) {
            self::STATUS_SCHEDULED => $this->isOverdue()
                ? ['Firing…',   'bg-amber-100 text-amber-800']
                : ['Scheduled', 'bg-blue-100 text-blue-700'],
            self::STATUS_FIRED     => ['Sent',      'bg-emerald-100 text-emerald-700'],
            self::STATUS_DISMISSED => ['Dismissed', 'bg-slate-100 text-slate-600'],
            self::STATUS_CANCELLED => ['Cancelled', 'bg-slate-100 text-slate-500'],
            default                => ['Unknown',   'bg-slate-100 text-slate-500'],
        };
    }

    /** Human-readable timing summary, e.g. "72h before departure" or "Absolute". */
    public function timingLabel(): string
    {
        if ($this->timing_mode === self::MODE_PRESET && $this->offset_hours) {
            $h = (int) $this->offset_hours;
            if ($h % 24 === 0) {
                $d = $h / 24;
                return $d . ' day' . ($d === 1 ? '' : 's') . ' before departure';
            }
            return $h . 'h before departure';
        }
        return 'Specific time';
    }
}
