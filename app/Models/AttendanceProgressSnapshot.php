<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class AttendanceProgressSnapshot extends Model
{
    use HasFactory;

    protected $fillable = [
        'attendance_id',
        'assignment_id',
        'project_id',
        'post_id',
        'total_patrol_points',
        'scanned_patrol_points',
        'progress_percentage',
        'snapshot_at',
        'scan_details',
        'snapshot_type',
    ];

    protected $casts = [
        'snapshot_at' => 'datetime',
        'scan_details' => 'json',
    ];

    public function attendance()
    {
        return $this->belongsTo(Attendance::class);
    }

    public function assignment()
    {
        return $this->belongsTo(Assignment::class);
    }

    public function project()
    {
        return $this->belongsTo(Project::class);
    }

    public function post()
    {
        return $this->belongsTo(Post::class);
    }
}
