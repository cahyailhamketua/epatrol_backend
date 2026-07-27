<?php

namespace App\Observers;

use App\Models\Absence;
use App\Services\PayrollRefreshService;

class AbsenceObserver
{
    public function __construct(
        private readonly PayrollRefreshService $payrollRefreshService,
    ) {}

    public function created(Absence $absence): void
    {
        $this->queuePayrollRefresh($absence);
    }

    public function updated(Absence $absence): void
    {
        $this->queuePayrollRefresh($absence);
    }

    public function deleted(Absence $absence): void
    {
        $this->queuePayrollRefresh($absence);
    }

    private function queuePayrollRefresh(Absence $absence): void
    {
        $schedule = $absence->relationLoaded('schedule')
            ? $absence->schedule
            : $absence->schedule()->first();

        if (! $schedule?->project_id || ! $schedule->date) {
            return;
        }

        $this->payrollRefreshService->queueRefreshForProjectDate(
            (int) $schedule->project_id,
            (string) $schedule->date
        );
    }
}
