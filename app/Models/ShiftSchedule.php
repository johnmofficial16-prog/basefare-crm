<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ShiftSchedule extends Model
{
    public $timestamps = true;

    const CREATED_AT = 'created_at';
    const UPDATED_AT = 'updated_at';

    protected $table = 'shift_schedules';

    // Publish status constants
    const PUBLISH_DRAFT            = 'draft';
    const PUBLISH_PENDING_APPROVAL = 'pending_approval';
    const PUBLISH_PUBLISHED        = 'published';

    protected $fillable = [
        'agent_id',
        'shift_date',
        'shift_start',
        'shift_end',
        'template_id',
        'schedule_week',
        'created_by',
        'publish_status',
        'approved_by',
        'approved_at',
    ];

    protected $casts = [
        'shift_date'    => 'date:Y-m-d',
        'schedule_week' => 'date:Y-m-d',
    ];

    /**
     * The agent assigned to this shift
     */
    public function agent()
    {
        return $this->belongsTo(User::class, 'agent_id');
    }

    /**
     * The optional template this shift was created from
     */
    public function template()
    {
        return $this->belongsTo(ShiftTemplate::class, 'template_id');
    }

    /**
     * The admin who created/last updated this schedule entry
     */
    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * The manager/admin who approved this shift (for supervisor-submitted shifts)
     */
    public function approver()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /**
     * Get the Monday of the ISO week for a given date string (YYYY-MM-DD)
     */
    public static function getMondayOfWeek(string $date): string
    {
        $d = new \DateTime($date);
        // ISO weekday: 1=Mon, 7=Sun
        $dayOfWeek = (int)$d->format('N');
        $daysToMonday = $dayOfWeek - 1;
        $d->modify("-{$daysToMonday} days");
        return $d->format('Y-m-d');
    }
}
