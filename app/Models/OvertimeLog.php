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
        'assignment_id',
        'schedule_id',
        'date',
        'overtime_type',
        'planned_start_time',
        'planned_end_time',
        'planned_minutes',
        'actual_start_time',
        'actual_end_time',
        'actual_minutes',
        'status',
        'approved_by',
        'approved_at',
        'notes',
    ];

    protected $casts = [
        'date' => 'date',
        'approved_at' => 'datetime',
    ];

    public function project()
    {
        return $this->belongsTo(Project::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function assignment()
    {
        return $this->belongsTo(Assignment::class);
    }

    public function schedule()
    {
        return $this->belongsTo(Schedule::class);
    }

    public function approvedBy()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    // Relationships
    public function attendance()
    {
        return $this->hasOne(Attendance::class, 'schedule_id', 'schedule_id')
                    ->whereDate('date', $this->date);
    }

    // Scopes
    public function scopeApproved($query)
    {
        return $query->where('status', 'APPROVED');
    }

    public function scopePending($query)
    {
        return $query->where('status', 'PENDING');
    }

    public function scopeCompleted($query)
    {
        return $query->where('status', 'COMPLETED');
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

    // Mutators
    public function calculatePlannedMinutes()
    {
        $start = \Carbon\Carbon::createFromFormat('H:i:s', $this->planned_start_time);
        $end = \Carbon\Carbon::createFromFormat('H:i:s', $this->planned_end_time);
        
        if ($end->lessThanOrEqualTo($start)) {
            $end->addDay();
        }
        
        return $end->diffInMinutes($start);
    }

    public function calculateActualMinutes()
    {
        if (!$this->actual_start_time || !$this->actual_end_time) {
            return null;
        }

        $start = \Carbon\Carbon::createFromFormat('H:i:s', $this->actual_start_time);
        $end = \Carbon\Carbon::createFromFormat('H:i:s', $this->actual_end_time);
        
        if ($end->lessThanOrEqualTo($start)) {
            $end->addDay();
        }
        
        return $end->diffInMinutes($start);
    }
}
