<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PayrollDetail extends Model
{
    use HasFactory;

    protected $fillable = [
        'payroll_run_id',
        'project_id',
        'user_id',
        'assignment_id',
        'period',
        'user_nik',
        'user_bank_name',
        'user_bank_account',
        'user_position',
        'working_days',
        'schedule_full_existing_count',
        'schedule_prorate_in_count',
        'schedule_prorate_out_count',
        'worked_hours',
        'base_salary',
        'attendance_count',
        'late_count',
        'late_total_minutes',
        'absence_count',
        'absence_type_sakit',
        'absence_type_izin',
        'absence_type_cuti',
        'alpha_count',
        'overtime_count',
        'overtime_total_hours',
        'deduction_late',
        'deduction_absence',
        'deduction_cuti',
        'deduction_alpha',
        'deduction_other',
        'total_deductions',
        'addition_overtime',
        'addition_allowance',
        'addition_bonus',
        'addition_other',
        'total_additions',
        'earnings_breakdown',
        'deductions_breakdown',
        'other_breakdown',
        'manual_breakdown',
        'daily_breakdown',
        'calculation_meta',
        'net_salary',
        'payment_method',
        'payment_date',
        'notes',
    ];

    protected $casts = [
        'base_salary' => 'decimal:2',
        'deduction_late' => 'decimal:2',
        'deduction_absence' => 'decimal:2',
        'deduction_cuti' => 'decimal:2',
        'deduction_alpha' => 'decimal:2',
        'deduction_other' => 'decimal:2',
        'total_deductions' => 'decimal:2',
        'addition_overtime' => 'decimal:2',
        'addition_allowance' => 'decimal:2',
        'addition_bonus' => 'decimal:2',
        'addition_other' => 'decimal:2',
        'total_additions' => 'decimal:2',
        'net_salary' => 'decimal:2',
        'payment_date' => 'date',
        'earnings_breakdown' => 'array',
        'deductions_breakdown' => 'array',
        'other_breakdown' => 'array',
        'manual_breakdown' => 'array',
        'daily_breakdown' => 'array',
        'calculation_meta' => 'array',
    ];

    public function payrollRun()
    {
        return $this->belongsTo(PayrollRun::class);
    }

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

    // Scopes
    public function scopeByPayrollRun($query, $payrollRunId)
    {
        return $query->where('payroll_run_id', $payrollRunId);
    }

    public function scopeByUser($query, $userId)
    {
        return $query->where('user_id', $userId);
    }

    public function scopeByProject($query, $projectId)
    {
        return $query->where('project_id', $projectId);
    }

    // Accessors & Mutators
    public function getTotalDeductions()
    {
        return $this->deduction_late + $this->deduction_absence +
               $this->deduction_cuti + $this->deduction_alpha +
               $this->deduction_other;
    }

    public function getTotalAdditions()
    {
        return $this->addition_overtime + $this->addition_allowance +
               $this->addition_bonus + $this->addition_other;
    }

    public function getNetSalary()
    {
        return $this->base_salary + $this->getTotalAdditions() - $this->getTotalDeductions();
    }

    // Helpers for breakdown
    public function getAbsenceBreakdown()
    {
        return [
            'sakit' => $this->absence_type_sakit,
            'izin' => $this->absence_type_izin,
            'cuti' => $this->absence_type_cuti,
            'total' => $this->absence_count,
        ];
    }

    public function getAbsenceLabel()
    {
        $labels = [];
        if ($this->absence_type_sakit > 0) {
            $labels[] = "{$this->absence_type_sakit} sakit";
        }
        if ($this->absence_type_izin > 0) {
            $labels[] = "{$this->absence_type_izin} izin";
        }
        if ($this->absence_type_cuti > 0) {
            $labels[] = "{$this->absence_type_cuti} cuti";
        }
        if ($this->alpha_count > 0) {
            $labels[] = "{$this->alpha_count} alpha";
        }

        return implode(', ', $labels) ?: 'None';
    }

    public function getAttendanceRate()
    {
        $total = $this->attendance_count + $this->absence_count + $this->alpha_count;
        if ($total === 0) {
            return 0;
        }

        return round(($this->attendance_count / $total) * 100, 2);
    }
}
