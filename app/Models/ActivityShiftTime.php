<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ActivityShiftTime extends Model
{
    protected $fillable = [
        'activity_id',
        'shift_id',
        'start_time',
        'end_time',
    ];

    public function activity()
    {
        return $this->belongsTo(Activity::class);
    }

    public function shift()
    {
        return $this->belongsTo(Shift::class);
    }
}

