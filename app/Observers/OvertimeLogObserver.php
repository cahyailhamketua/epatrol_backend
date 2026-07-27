<?php

namespace App\Observers;

use App\Models\OvertimeLog;
use App\Services\PayrollRefreshService;

class OvertimeLogObserver
{
    public function __construct(
        private readonly PayrollRefreshService $payrollRefreshService,
    ) {}

    public function created(OvertimeLog $overtimeLog): void
    {
        $this->queuePayrollRefresh($overtimeLog);
    }

    public function updated(OvertimeLog $overtimeLog): void
    {
        $this->queuePayrollRefresh($overtimeLog);

        if ($overtimeLog->wasChanged('date') || $overtimeLog->wasChanged('project_id')) {
            $originalProjectId = (int) ($overtimeLog->getOriginal('project_id') ?? 0);
            $originalDate = $overtimeLog->getOriginal('date');

            if ($originalProjectId > 0 && $originalDate) {
                $this->payrollRefreshService->queueRefreshForProjectDate(
                    $originalProjectId,
                    (string) $originalDate
                );
            }
        }
    }

    public function deleted(OvertimeLog $overtimeLog): void
    {
        $this->queuePayrollRefresh($overtimeLog);
    }

    private function queuePayrollRefresh(OvertimeLog $overtimeLog): void
    {
        if (! $overtimeLog->project_id || ! $overtimeLog->date) {
            return;
        }

        $this->payrollRefreshService->queueRefreshForProjectDate(
            (int) $overtimeLog->project_id,
            $overtimeLog->date instanceof \DateTimeInterface
                ? $overtimeLog->date->format('Y-m-d')
                : (string) $overtimeLog->date
        );
    }
}
