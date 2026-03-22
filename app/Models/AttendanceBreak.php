<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AttendanceBreak extends Model
{
    public $timestamps = false;

    protected $table = 'attendance_breaks';

    protected $fillable = [
        'session_id',
        'break_type',
        'break_start',
        'break_end',
        'duration_mins',
        'flagged',
    ];

    // Break type constants
    const TYPE_LUNCH    = 'lunch';
    const TYPE_SHORT    = 'short';
    const TYPE_WASHROOM = 'washroom';

    // Break limits per shift
    const MAX_LUNCH_BREAKS = 1;
    const MAX_SHORT_BREAKS = 2;
    // Washroom breaks are unlimited but monitored

    /**
     * The attendance session this break belongs to
     */
    public function session()
    {
        return $this->belongsTo(AttendanceSession::class, 'session_id');
    }

    /**
     * Calculate duration in minutes between break_start and break_end
     */
    public function calculateDuration(): int
    {
        if (!$this->break_end) {
            return 0;
        }
        $start = strtotime($this->break_start);
        $end   = strtotime($this->break_end);
        return max(0, (int)round(($end - $start) / 60));
    }
}
