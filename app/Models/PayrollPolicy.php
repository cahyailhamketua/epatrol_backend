<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class PayrollPolicy extends Model
{
    use HasFactory;

    protected $fillable = [
        'project_id',
        'policy_code',
        'policy_name',
        'description',
        'effective_from',
        'effective_to',
        'daily_rate',
        'hourly_rate',
        'late_deduction_per_minute',
        'late_minimum_minutes',
        'absence_deduction_amount',
        'alpha_deduction_amount',
        'overtime_rate_percent',
        'overtime_rate_amount',
        'daily_allowance',
        'shift_allowance_amount',
        'perfect_attendance_bonus',
        'status',
    ];

    protected $casts = [
        'effective_from' => 'date',
        'effective_to' => 'date',
        'daily_rate' => 'decimal:2',
        'hourly_rate' => 'decimal:2',
        'late_deduction_per_minute' => 'decimal:4',
        'absence_deduction_amount' => 'decimal:2',
        'alpha_deduction_amount' => 'decimal:2',
        'overtime_rate_percent' => 'decimal:2',
        'overtime_rate_amount' => 'decimal:2',
        'daily_allowance' => 'decimal:2',
        'shift_allowance_amount' => 'decimal:2',
        'perfect_attendance_bonus' => 'decimal:2',
    ];

    public function project()
    {
        return $this->belongsTo(Project::class);
    }

    public function payrollRuns()
    {
        return $this->hasMany(PayrollRun::class);
    }

    // Scopes
    public function scopeActive($query)
    {
        return $query->where('status', 'ACTIVE');
    }

    public function scopeInactive($query)
    {
        return $query->where('status', 'INACTIVE');
    }

    public function scopeByProject($query, $projectId)
    {
        return $query->where('project_id', $projectId);
    }

    public function scopeEffectiveOn($query, $date)
    {
        return $query->where('effective_from', '<=', $date)
                     ->where(function ($q) use ($date) {
                         $q->whereNull('effective_to')
                           ->orWhere('effective_to', '>=', $date);
                     });
    }

    // Helpers
    public function getOvertimeRate()
    {
        if ($this->overtime_rate_amount) {
            return $this->overtime_rate_amount;
        }

        return $this->hourly_rate * ($this->overtime_rate_percent / 100);
    }

    public function getLatePenalty($minutes)
    {
        if ($minutes < $this->late_minimum_minutes) {
            return 0;
        }

        return $minutes * $this->late_deduction_per_minute;
    }
}
