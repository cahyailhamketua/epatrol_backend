<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class OvertimeLog extends Model
{
    use HasFactory;

    protected $table = 'overtime_logs';

    protected $fillable = [
        'project_id',
        'user_id',
        'schedule_id',
        'attendance_id',
        'scheduled_assignment_id',
        'work_assignment_id',
        'date',
        'display_code',
        'minutes',
    ];

    protected $casts = [
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

    public function attendance()
    {
        return $this->belongsTo(Attendance::class);
    }

    /** Assignment OFF di jadwal (mis. O) */
    public function scheduledAssignment()
    {
        return $this->belongsTo(Assignment::class, 'scheduled_assignment_id');
    }

    /** Shift kerja lembur (mis. P atau M) */
    public function workAssignment()
    {
        return $this->belongsTo(Assignment::class, 'work_assignment_id');
    }

    public function scopeByDate($query, $date)
    {
        return $query->where('date', $date);
    }

    public function scopeByUser($query, $userId)
    {
        return $query->where('user_id', $userId);
    }

    public function scopeByProject($query, $projectId)
    {
        return $query->where('project_id', $projectId);
    }

    public function scopeInPeriod($query, $startDate, $endDate)
    {
        return $query->whereBetween('date', [$startDate, $endDate]);
    }
}
