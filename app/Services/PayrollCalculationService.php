<?php

namespace App\Services;

use App\Models\Attendance;
use App\Models\Absence;
use App\Models\PayrollPolicy;
use App\Models\PayrollRun;
use App\Models\PayrollDetail;
use App\Models\Schedule;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class PayrollCalculationService
{
    /**
     * Calculate payroll for a single user in a period
     *
     * @param int $userId
     * @param int $projectId
     * @param Carbon $periodStart
     * @param Carbon $periodEnd
     * @param PayrollPolicy $policy
     * @return array
     */
    public function calculateUserPayroll(
        int $userId,
        int $projectId,
        Carbon $periodStart,
        Carbon $periodEnd,
        PayrollPolicy $policy
    ): array {
        // Fetch all schedules for the user in this period
        $schedules = Schedule::whereUserId($userId)
            ->whereProjectId($projectId)
            ->inPeriod($periodStart, $periodEnd)
            ->get();

        // Count working days and get assignment
        $workingDays = $schedules->count();
        $assignment = $schedules->first()?->assignment;

        // Initialize metrics
        $metrics = [
            'working_days' => $workingDays,
            'worked_hours' => 0,
            'attendance_count' => 0,
            'late_count' => 0,
            'late_total_minutes' => 0,
            'absence_count' => 0,
            'absence_type_sakit' => 0,
            'absence_type_izin' => 0,
            'absence_type_cuti' => 0,
            'absence_type_alpa' => 0,
            'alpha_count' => 0,
            'overtime_count' => 0,
            'overtime_total_hours' => 0,
        ];

        // Process each day in period
        foreach ($schedules as $schedule) {
            $attendance = $schedule->attendance;
            $absence = $schedule->absence;

            if ($attendance && $attendance->check_in_at && $attendance->check_out_at) {
                // Count attendance
                $metrics['attendance_count']++;
                $metrics['worked_hours'] += $attendance->check_in_at->diffInHours($attendance->check_out_at);

                // Count late
                if ($attendance->late_minutes > 0) {
                    $metrics['late_count']++;
                    $metrics['late_total_minutes'] += $attendance->late_minutes;
                }

                // Count overtime if approved
                if ($attendance->overtime_status === 'APPROVED') {
                    $metrics['overtime_count']++;
                    $metrics['overtime_total_hours'] += $attendance->overtime_minutes / 60;
                }
            } elseif ($absence) {
                // Count absence (C/S/I/A)
                $metrics['absence_count']++;
                $key = Absence::TYPE_TO_SUMMARY_KEY[$absence->absence_type] ?? '';
                switch ($key) {
                    case 'SAKIT':
                        $metrics['absence_type_sakit']++;
                        break;
                    case 'IZIN':
                        $metrics['absence_type_izin']++;
                        break;
                    case 'CUTI':
                        $metrics['absence_type_cuti']++;
                        break;
                    case 'ALPA':
                        $metrics['absence_type_alpa']++;
                        break;
                }
            } else {
                // Alpha: no attendance, no valid absence
                $metrics['alpha_count']++;
            }
        }

        // Calculate base salary only on days attended
        $baseSalary = $metrics['attendance_count'] * $policy->daily_rate;

        // Calculate deductions
        $deductions = $this->calculateDeductions($metrics, $policy);

        // Calculate additions
        $additions = $this->calculateAdditions($metrics, $policy);

        // Calculate net salary
        $netSalary = $baseSalary + $additions['total'] - $deductions['total'];

        return [
            'metrics' => $metrics,
            'base_salary' => $baseSalary,
            'deductions' => $deductions,
            'additions' => $additions,
            'net_salary' => $netSalary,
            'assignment_id' => $assignment?->id,
        ];
    }

    /**
     * Calculate all deductions
     */
    private function calculateDeductions(array $metrics, PayrollPolicy $policy): array
    {
        $deductions = [
            'late' => 0,
            'absence' => 0,
            'cuti' => 0,
            'alpha' => 0,
            'other' => 0,
            'total' => 0,
        ];

        // Late deduction
        if ($metrics['late_total_minutes'] > 0) {
            $deductions['late'] = $policy->getLatePenalty($metrics['late_total_minutes']);
        }

        // Absence deduction (SAKIT + IZIN)
        $absenceCount = $metrics['absence_type_sakit'] + $metrics['absence_type_izin'];
        $deductions['absence'] = $absenceCount * $policy->absence_deduction_amount;

        // Cuti deduction (optional - configure per policy)
        $deductions['cuti'] = $metrics['absence_type_cuti'] * $policy->absence_deduction_amount;

        // Alpha deduction
        $deductions['alpha'] = $metrics['alpha_count'] * $policy->alpha_deduction_amount;

        // Total deductions
        $deductions['total'] = array_sum([
            $deductions['late'],
            $deductions['absence'],
            $deductions['cuti'],
            $deductions['alpha'],
            $deductions['other'],
        ]);

        return $deductions;
    }

    /**
     * Calculate all additions (overtime, allowance, bonus)
     */
    private function calculateAdditions(array $metrics, PayrollPolicy $policy): array
    {
        $additions = [
            'overtime' => 0,
            'allowance' => 0,
            'bonus' => 0,
            'other' => 0,
            'total' => 0,
        ];

        // Overtime addition
        if ($metrics['overtime_total_hours'] > 0) {
            $overtimeRate = $policy->getOvertimeRate();
            $additions['overtime'] = $metrics['overtime_total_hours'] * $overtimeRate;
        }

        // Daily allowance
        $additions['allowance'] = $metrics['attendance_count'] * $policy->daily_allowance;

        // Perfect attendance bonus (no late, no alpha)
        if ($metrics['late_count'] === 0 && $metrics['alpha_count'] === 0) {
            $additions['bonus'] = $policy->perfect_attendance_bonus;
        }

        // Total additions
        $additions['total'] = array_sum([
            $additions['overtime'],
            $additions['allowance'],
            $additions['bonus'],
            $additions['other'],
        ]);

        return $additions;
    }

    /**
     * Build PayrollDetail from calculation result
     */
    public function buildPayrollDetail(
        PayrollRun $payrollRun,
        int $userId,
        int $projectId,
        array $calculation
    ): array {
        return [
            'payroll_run_id' => $payrollRun->id,
            'project_id' => $projectId,
            'user_id' => $userId,
            'assignment_id' => $calculation['assignment_id'],
            'working_days' => $calculation['metrics']['working_days'],
            'worked_hours' => $calculation['metrics']['worked_hours'],
            'base_salary' => $calculation['base_salary'],
            'attendance_count' => $calculation['metrics']['attendance_count'],
            'late_count' => $calculation['metrics']['late_count'],
            'late_total_minutes' => $calculation['metrics']['late_total_minutes'],
            'absence_count' => $calculation['metrics']['absence_count'],
            'absence_type_sakit' => $calculation['metrics']['absence_type_sakit'],
            'absence_type_izin' => $calculation['metrics']['absence_type_izin'],
            'absence_type_cuti' => $calculation['metrics']['absence_type_cuti'],
            'alpha_count' => $calculation['metrics']['alpha_count'],
            'overtime_count' => $calculation['metrics']['overtime_count'],
            'overtime_total_hours' => $calculation['metrics']['overtime_total_hours'],
            'deduction_late' => $calculation['deductions']['late'],
            'deduction_absence' => $calculation['deductions']['absence'],
            'deduction_cuti' => $calculation['deductions']['cuti'],
            'deduction_alpha' => $calculation['deductions']['alpha'],
            'deduction_other' => $calculation['deductions']['other'],
            'total_deductions' => $calculation['deductions']['total'],
            'addition_overtime' => $calculation['additions']['overtime'],
            'addition_allowance' => $calculation['additions']['allowance'],
            'addition_bonus' => $calculation['additions']['bonus'],
            'addition_other' => $calculation['additions']['other'],
            'total_additions' => $calculation['additions']['total'],
            'net_salary' => $calculation['net_salary'],
        ];
    }
}
