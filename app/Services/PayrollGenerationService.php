<?php

namespace App\Services;

use App\Models\PayrollRun;
use App\Models\PayrollDetail;
use App\Models\PayrollPolicy;
use App\Models\Schedule;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Exception;

class PayrollGenerationService
{
    protected PayrollCalculationService $calculationService;

    public function __construct(PayrollCalculationService $calculationService)
    {
        $this->calculationService = $calculationService;
    }

    /**
     * Generate payroll details for all employees in a payroll run
     *
     * @param PayrollRun $payrollRun
     * @return int Number of details created
     * @throws Exception
     */
    public function generatePayrollDetails(PayrollRun $payrollRun): int
    {
        if (!$payrollRun->isDraft()) {
            throw new Exception('Payroll run harus dalam status DRAFT untuk generate.');
        }

        DB::beginTransaction();

        try {
            $periodStart = $payrollRun->pay_period_start;
            $periodEnd = $payrollRun->pay_period_end;
            $projectId = $payrollRun->project_id;
            $policy = $payrollRun->policy;

            // Get all unique users with schedules in this period
            $userIds = Schedule::where('project_id', $projectId)
                ->inPeriod($periodStart, $periodEnd)
                ->distinct('user_id')
                ->pluck('user_id')
                ->toArray();

            $detailsCreated = 0;

            // Calculate payroll for each user
            foreach ($userIds as $userId) {
                $calculation = $this->calculationService->calculateUserPayroll(
                    $userId,
                    $projectId,
                    $periodStart,
                    $periodEnd,
                    $policy
                );

                // Build and create PayrollDetail
                $detailData = $this->calculationService->buildPayrollDetail(
                    $payrollRun,
                    $userId,
                    $projectId,
                    $calculation
                );

                $payrollRun->payrollDetails()->create($detailData);
                $detailsCreated++;
            }

            // Update payroll run summary
            $this->updatePayrollRunSummary($payrollRun);

            DB::commit();

            return $detailsCreated;
        } catch (Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * Update payroll run summary totals
     */
    private function updatePayrollRunSummary(PayrollRun $payrollRun): void
    {
        $details = $payrollRun->payrollDetails;

        $payrollRun->update([
            'total_employees' => $details->count(),
            'total_payroll_amount' => $details->sum('net_salary'),
            'total_deductions' => $details->sum('total_deductions'),
            'total_additions' => $details->sum('total_additions'),
        ]);
    }

    /**
     * Finalize payroll run (lock for editing)
     *
     * @param PayrollRun $payrollRun
     * @param int $finalizedById
     * @param string|null $notes
     * @return PayrollRun
     */
    public function finalizePayrollRun(
        PayrollRun $payrollRun,
        int $finalizedById,
        ?string $notes = null
    ): PayrollRun {
        if (!$payrollRun->isDraft()) {
            throw new Exception('Hanya DRAFT payroll yang bisa di-finalize.');
        }

        if ($payrollRun->payrollDetails->count() === 0) {
            throw new Exception('Belum ada payroll details. Generate dulu.');
        }

        $payrollRun->update([
            'status' => 'FINALIZED',
            'finalized_by' => $finalizedById,
            'finalized_at' => now(),
            'notes' => $notes ?? $payrollRun->notes,
        ]);

        return $payrollRun->refresh();
    }

    /**
     * Mark payroll as paid
     *
     * @param PayrollRun $payrollRun
     * @param string|null $paidDate
     * @return PayrollRun
     */
    public function markPayrollAsPaid(
        PayrollRun $payrollRun,
        ?string $paidDate = null
    ): PayrollRun {
        if (!$payrollRun->isFinalized()) {
            throw new Exception('Hanya FINALIZED payroll yang bisa di-mark as PAID.');
        }

        $payrollRun->update([
            'status' => 'PAID',
            'paid_at' => $paidDate ? Carbon::parse($paidDate) : now(),
        ]);

        return $payrollRun->refresh();
    }

    /**
     * Cancel payroll run
     *
     * @param PayrollRun $payrollRun
     * @param string $reason
     * @return PayrollRun
     */
    public function cancelPayrollRun(PayrollRun $payrollRun, string $reason): PayrollRun
    {
        if ($payrollRun->isPaid()) {
            throw new Exception('Tidak bisa cancel PAID payroll.');
        }

        $payrollRun->update([
            'status' => 'CANCELLED',
            'notes' => 'CANCELLED - ' . $reason,
        ]);

        return $payrollRun->refresh();
    }

    /**
     * Recalculate payroll details (delete and regenerate)
     *
     * @param PayrollRun $payrollRun
     * @return int
     */
    public function recalculatePayrollDetails(PayrollRun $payrollRun): int
    {
        if ($payrollRun->isFinalized() || $payrollRun->isPaid()) {
            throw new Exception('Tidak bisa recalculate FINALIZED/PAID payroll.');
        }

        DB::beginTransaction();

        try {
            // Delete existing details
            $payrollRun->payrollDetails()->delete();

            // Regenerate
            $detailsCreated = $this->generatePayrollDetails($payrollRun);

            DB::commit();

            return $detailsCreated;
        } catch (Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }
}
