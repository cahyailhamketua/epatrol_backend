<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ActivityShiftTime extends Model
{
    protected $fillable = [
        'activity_id',
        'assignment_id',
        'start_time',
        'end_time',
    ];

    public function activity()
    {
        return $this->belongsTo(Activity::class);
    }

    public function assignment()
    {
        return $this->belongsTo(Assignment::class);
    }
}

