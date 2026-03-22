<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ShiftTemplate extends Model
{
    public $timestamps = false;

    protected $table = 'shift_templates';

    protected $fillable = [
        'name',
        'start_time',
        'end_time',
        'created_by',
    ];

    /**
     * The admin who created this template
     */
    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
