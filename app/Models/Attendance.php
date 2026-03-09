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
        'computed_status',
        'late_minutes',
        'overtime_minutes',
        'overtime_status',
        'selfie_photo_path',
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

    public function overtimeLog()
    {
        return $this->belongsTo(OvertimeLog::class, 'schedule_id', 'schedule_id')
                    ->whereDate('date', $this->date);
    }

    /**
     * Check if this attendance is for a commander (komandan_regu)
     */
    public function isCommanderAttendance(): bool
    {
        return $this->user->role === 'komandan_regu';
    }

    /**
     * Get patrol points for this attendance
     * For commander: only 1 static point per project
     * For member: all patrol points in selected post
     */
    public function getPatrolPoints()
    {
        if ($this->isCommanderAttendance()) {
            // Commander: patrol points from their static post (prefer attendance->post_id, fallback to first static post in project)
            $staticPostId = null;
            if ($this->post_id && $this->post?->type === 'static') {
                $staticPostId = $this->post_id;
            } else {
                $staticPostId = $this->project?->posts()
                    ->where('type', 'static')
                    ->value('id');
            }

            if (!$staticPostId) {
                return collect();
            }

            return PatrolPoint::where('post_id', $staticPostId)->get();
        }

        // Member: static post does not require patrol scan
        if ($this->post?->type === 'static') {
            return collect();
        }

        // Member: mobile post -> get all patrol points ordered by sequence
        return $this->post?->patrolPoints()->get() ?? collect();
    }

    /**
     * Get location coordinates based on user type
     * For commander: project location
     * For member: post location or first patrol point
     */
    public function getLocationCoordinates(): array
    {
        if ($this->isCommanderAttendance()) {
            // Commander gets location from project
            return [
                'latitude' => $this->project->location_latitude,
                'longitude' => $this->project->location_longitude,
                'altitude' => null,
                'radius' => $this->project->radius,
            ];
        }

        // Member gets location from first patrol point
        $firstPoint = $this->post?->patrolPoints()->first();
        if ($firstPoint) {
            return [
                'latitude' => $firstPoint->latitude,
                'longitude' => $firstPoint->longitude,
                'altitude' => $firstPoint->altitude,
                'radius' => $firstPoint->radius,
            ];
        }

        return [
            'latitude' => null,
            'longitude' => null,
            'altitude' => null,
            'radius' => 0,
        ];
    }

    // Scopes
    public function scopePresent($query)
    {
        return $query->where('attendance_status', 'HADIR')->orWhere('attendance_status', 'HADIR TELAT');
    }

    public function scopeLate($query)
    {
        return $query->where('attendance_status', 'HADIR TELAT');
    }

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
}
