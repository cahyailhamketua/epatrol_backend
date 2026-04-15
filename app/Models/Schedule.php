<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Schedule extends Model
{
    use HasFactory;

    public const STATUS_FULL_EXISTING = 'FULL_EXISTING';
    public const STATUS_PRORATE_IN = 'PRORATE_IN';
    public const STATUS_PRORATE_OUT = 'PRORATE_OUT';

    protected $fillable = [
        'project_id',
        'user_id',
        'assignment_id',
        'team_id',
        'membership_status',
        'date',
    ];

    // protected $casts = [
    //     'date' => 'date',
    // ];

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

    public function attendance()
    {
        return $this->hasOne(Attendance::class);
    }

    public function absence()
    {
        return $this->hasOne(Absence::class);
    }

    public function overtimeLogs()
    {
        return $this->hasMany(OvertimeLog::class);
    }

    // Scopes
    public function scopeByDate($query, $date)
    {
        return $query->where('date', $date);
    }

    public function scopeByUser($query, $userId)
    {
        return $query->where('user_id', $userId);
    }

    public function scopeInPeriod($query, $startDate, $endDate)
    {
        return $query->whereBetween('date', [$startDate, $endDate]);
    }

    // Helper to check status for a day
    public function getFinalStatus()
    {
        if ($this->attendance) {
            return $this->attendance->attendance_status;
        }
        if ($this->absence) {
            return Absence::TYPE_TO_SUMMARY_KEY[$this->absence->absence_type] ?? $this->absence->absence_type;
        }
        return 'ALPHA';
    }

    public function team()
    {
        return $this->belongsTo(Team::class);
    }
}
