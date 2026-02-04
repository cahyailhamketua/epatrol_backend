<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Attendance extends Model
{
    use HasFactory;

    protected $fillable = [
        'project_id',
        'user_id',
        'schedule_id',
        'assignment_id',
        'post_id',
        'date',
        'check_in_at',
        'check_out_at',
        'checkin_lat',
        'checkin_lng',
        'checkout_lat',
        'checkout_lng',
        'attendance_status',
        'late_minutes',
    ];

    protected $casts = [
        'check_in_at' => 'datetime',
        'check_out_at' => 'datetime',
        'date' => 'date',
    ];

    public function project()
    {
        return $this->belongsTo(Project::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function schedule()
    {
        return $this->belongsTo(Schedule::class);
    }

    public function assignment()
    {
        return $this->belongsTo(Assignment::class);
    }

    public function post()
    {
        return $this->belongsTo(Post::class);
    }

    public function patrolScans()
    {
        return $this->hasMany(PatrolScan::class);
    }
}
