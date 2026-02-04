<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Assignment extends Model
{
    use HasFactory;

    protected $fillable = [
        'project_id',
        'name',
        'code',
        'start_time',
        'end_time',
        'grace_period',
    ];

    public function project()
    {
        return $this->belongsTo(Project::class);
    }

    public function schedules()
    {
        return $this->hasMany(Schedule::class);
    }

    public function attendances()
    {
        return $this->hasMany(Attendance::class);
    }
}
