<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

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
        'is_off',
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

    public function activityTimes()
    {
        return $this->hasMany(ActivityAssignmentTime::class);
    }

    public function overtimeLogsAsScheduled()
    {
        return $this->hasMany(OvertimeLog::class, 'scheduled_assignment_id');
    }

    public function overtimeLogsAsWork()
    {
        return $this->hasMany(OvertimeLog::class, 'work_assignment_id');
    }

    public function payrollDetails()
    {
        return $this->hasMany(PayrollDetail::class);
    }

    // Scopes
    public function scopeOff($query)
    {
        return $query->where('is_off', 1);
    }

    public function scopeActive($query)
    {
        return $query->where('is_off', 0);
    }

    // Helpers
    public function isVisible()
    {
        return !$this->is_off;
    }

    public function isOffDuty()
    {
        return $this->is_off || $this->code === 'o';
    }

    public function getDurationInMinutes()
    {
        if ($this->isOffDuty()) {
            return null;
        }

        $start = \Carbon\Carbon::createFromFormat('H:i:s', $this->start_time);
        $end = \Carbon\Carbon::createFromFormat('H:i:s', $this->end_time);
        
        if ($end->lessThanOrEqualTo($start)) {
            $end->addDay();
        }
        
        return $end->diffInMinutes($start);
    }
}
