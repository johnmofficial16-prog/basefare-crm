<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AttendanceOverride extends Model
{
    public $timestamps = false;

    protected $table = 'attendance_overrides';

    protected $fillable = [
        'agent_id',
        'shift_date',
        'override_type',
        'override_by',
        'reason',
        'original_value',
        'new_value',
    ];

    // Override type constants
    const TYPE_LATE_LOGIN      = 'late_login';
    const TYPE_EARLY_LOGOUT    = 'early_logout';
    const TYPE_MISSED_CLOCKOUT = 'missed_clockout';
    const TYPE_MANUAL_ENTRY    = 'manual_entry';
    const TYPE_TIME_CORRECTION = 'time_correction';

    /**
     * The agent this override is for
     */
    public function agent()
    {
        return $this->belongsTo(User::class, 'agent_id');
    }

    /**
     * The admin who approved this override
     */
    public function approvedBy()
    {
        return $this->belongsTo(User::class, 'override_by');
    }
}
