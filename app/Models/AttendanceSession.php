<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AttendanceSession extends Model
{
    protected $table = 'attendance_sessions';

    protected $fillable = [
        'user_id',
        'clock_in',
        'clock_out',
        'scheduled_start',
        'scheduled_end',
        'late_minutes',
        'total_work_mins',
        'total_break_mins',
        'status',
        'resolution_required',
        'override_by',
        'override_reason',
        'ip_address',
        'user_agent',
        'date',
    ];

    // Status constants
    const STATUS_ACTIVE         = 'active';
    const STATUS_COMPLETED      = 'completed';
    const STATUS_ADMIN_OVERRIDE = 'admin_override';
    const STATUS_AUTO_CLOSED    = 'auto_closed';

    /**
     * The agent who owns this session
     */
    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * All breaks in this session
     */
    public function breaks()
    {
        return $this->hasMany(AttendanceBreak::class, 'session_id');
    }

    /**
     * The admin who approved an override (if any)
     */
    public function overrideAdmin()
    {
        return $this->belongsTo(User::class, 'override_by');
    }

    // ---- Scopes ----

    /**
     * Scope: Only active sessions
     */
    public function scopeActive($query)
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }

    /**
     * Scope: Sessions for a specific date
     */
    public function scopeForDate($query, string $date)
    {
        return $query->where('date', $date);
    }

    /**
     * Scope: Sessions for a specific user
     */
    public function scopeForUser($query, int $userId)
    {
        return $query->where('user_id', $userId);
    }

    /**
     * Scope: Sessions requiring admin resolution
     */
    public function scopeUnresolved($query)
    {
        return $query->where('resolution_required', 1);
    }

    /**
     * Check if the agent is currently on a break
     */
    public function hasActiveBreak(): bool
    {
        return $this->breaks()
                    ->whereNull('break_end')
                    ->exists();
    }

    /**
     * Get the currently active break (if any)
     */
    public function getActiveBreak(): ?AttendanceBreak
    {
        return $this->breaks()
                    ->whereNull('break_end')
                    ->first();
    }
}
