<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PayrollDetail;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class PayrollDetailController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth:sanctum');
    }

    /**
     * GET /api/payroll-details
     * List payroll details with filters
     */
    public function index(Request $request)
    {
        $query = PayrollDetail::query();

        if ($request->has('payroll_run_id')) {
            $query->where('payroll_run_id', $request->payroll_run_id);
        }

        if ($request->has('user_id')) {
            $query->where('user_id', $request->user_id);
        }

        if ($request->has('project_id')) {
            $query->where('project_id', $request->project_id);
        }

        $details = $query->with(['user', 'assignment', 'payrollRun'])
            ->orderBy('created_at', 'desc')
            ->paginate($request->per_page ?? 50);

        return response()->json([
            'success' => true,
            'data' => $details,
        ]);
    }

    /**
     * GET /api/payroll-details/{id}
     * Get single payroll detail with full breakdown
     */
    public function show(PayrollDetail $payrollDetail)
    {
        // Build daily breakdown if needed
        $breakdown = $this->buildDailyBreakdown($payrollDetail);

        return response()->json([
            'success' => true,
            'data' => [
                'payroll_detail' => $payrollDetail->load(['user', 'assignment', 'payrollRun']),
                'summary' => [
                    'base_salary' => $payrollDetail->base_salary,
                    'total_deductions' => $payrollDetail->total_deductions,
                    'total_additions' => $payrollDetail->total_additions,
                    'net_salary' => $payrollDetail->net_salary,
                    'attendance_rate' => $payrollDetail->getAttendanceRate() . '%',
                ],
                'deduction_breakdown' => [
                    'late' => $payrollDetail->deduction_late,
                    'absence' => $payrollDetail->deduction_absence,
                    'cuti' => $payrollDetail->deduction_cuti,
                    'alpha' => $payrollDetail->deduction_alpha,
                ],
                'addition_breakdown' => [
                    'overtime' => $payrollDetail->addition_overtime,
                    'allowance' => $payrollDetail->addition_allowance,
                    'bonus' => $payrollDetail->addition_bonus,
                ],
                'absence_detail' => $payrollDetail->getAbsenceBreakdown(),
                'daily_breakdown' => $breakdown,
            ],
        ]);
    }

    /**
     * Build daily breakdown from attendance/absence records
     */
    private function buildDailyBreakdown(PayrollDetail $payrollDetail): array
    {
        $payrollRun = $payrollDetail->payrollRun;
        $breakdown = [];

        $schedules = $payrollDetail->user->schedules()
            ->whereBetween('date', [$payrollRun->pay_period_start, $payrollRun->pay_period_end])
            ->get();

        foreach ($schedules as $schedule) {
            $dayData = [
                'date' => $schedule->date->format('Y-m-d'),
                'day_name' => $schedule->date->format('l'),
                'assignment_name' => $schedule->assignment->name,
            ];

            if ($attendance = $schedule->attendance) {
                $dayData['attendance'] = [
                    'status' => $attendance->attendance_status,
                    'check_in_at' => $attendance->check_in_at?->format('H:i'),
                    'check_out_at' => $attendance->check_out_at?->format('H:i'),
                    'late_minutes' => $attendance->late_minutes,
                    'overtime_minutes' => $attendance->overtime_minutes,
                ];
            }

            if ($absence = $schedule->absence) {
                $dayData['absence'] = [
                    'absence_type' => $absence->absence_type,
                    'label' => $absence->label,
                ];
            }

            if (!isset($dayData['attendance']) && !isset($dayData['absence'])) {
                $dayData['status'] = 'ALPHA';
            }

            $breakdown[] = $dayData;
        }

        return $breakdown;
    }

    /**
     * GET /api/payroll-details/{id}/export
     * Export payroll detail as formatted text/PDF (optional)
     */
    public function export(PayrollDetail $payrollDetail)
    {
        $text = $this->formatPayrollDetail($payrollDetail);

        return response($text, 200)
            ->header('Content-Type', 'text/plain; charset=utf-8')
            ->header('Content-Disposition', 'attachment; filename=payroll_' . $payrollDetail->user_id . '.txt');
    }

    /**
     * Format payroll detail as readable text
     */
    private function formatPayrollDetail(PayrollDetail $payrollDetail): string
    {
        $user = $payrollDetail->user;
        $run = $payrollDetail->payrollRun;

        $text = "════════════════════════════════════════════════════════════\n";
        $text .= "                     PAYROLL RECAP\n";
        $text .= "════════════════════════════════════════════════════════════\n\n";

        $text .= "Employee        : {$user->full_name} (ID: {$user->id})\n";
        $text .= "Period          : {$run->pay_period_start->format('M d')} - {$run->pay_period_end->format('M d, Y')}\n";
        $text .= "Assignment      : {$payrollDetail->assignment->name}\n";
        $text .= "Working Days    : {$payrollDetail->working_days} hari\n\n";

        $text .= "────────────────────────────────────────────────────────────\n";
        $text .= "ATTENDANCE DETAIL\n";
        $text .= "────────────────────────────────────────────────────────────\n";
        $text .= "Attended        : {$payrollDetail->attendance_count} hari\n";
        $text .= "Late            : {$payrollDetail->late_count} hari ({$payrollDetail->late_total_minutes} menit)\n";
        $text .= "Absence         : {$payrollDetail->absence_count} hari\n";
        $text .= "  - Sakit       : {$payrollDetail->absence_type_sakit} hari\n";
        $text .= "  - Izin        : {$payrollDetail->absence_type_izin} hari\n";
        $text .= "  - Cuti        : {$payrollDetail->absence_type_cuti} hari\n";
        $text .= "Alpha           : {$payrollDetail->alpha_count} hari\x0A";
        $text .= "Overtime        : {$payrollDetail->overtime_count} hari ({$payrollDetail->overtime_total_hours} jam)\n\n";

        $text .= "────────────────────────────────────────────────────────────\n";
        $text .= "SALARY CALCULATION\n";
        $text .= "────────────────────────────────────────────────────────────\n";
        $text .= "Base Salary     : Rp " . number_format($payrollDetail->base_salary, 0, ',', '.') . "\n";
        $text .= "Additions       : Rp " . number_format($payrollDetail->total_additions, 0, ',', '.') . "\n";
        $text .= "Deductions      : Rp " . number_format($payrollDetail->total_deductions, 0, ',', '.') . "\n";
        $text .= "────────────────────────────────────────────────────────────\n";
        $text .= "NET SALARY      : Rp " . number_format($payrollDetail->net_salary, 0, ',', '.') . "\n\n";

        $text .= "════════════════════════════════════════════════════════════\n";

        return $text;
    }
}
