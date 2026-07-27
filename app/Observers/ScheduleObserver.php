<?php

namespace App\Observers;

use App\Models\Schedule;
use App\Services\PayrollRefreshService;

class ScheduleObserver
{
    public function __construct(
        private readonly PayrollRefreshService $payrollRefreshService,
    ) {}

    public function created(Schedule $schedule): void
    {
        $this->queuePayrollRefresh($schedule);
    }

    public function updated(Schedule $schedule): void
    {
        $this->queuePayrollRefresh($schedule);

        if ($schedule->wasChanged('date') || $schedule->wasChanged('project_id')) {
            $originalProjectId = (int) ($schedule->getOriginal('project_id') ?? 0);
            $originalDate = $schedule->getOriginal('date');

            if ($originalProjectId > 0 && $originalDate) {
                $this->payrollRefreshService->queueRefreshForProjectDate($originalProjectId, $originalDate);
            }
        }
    }

    public function deleted(Schedule $schedule): void
    {
        $this->queuePayrollRefresh($schedule);
    }

    private function queuePayrollRefresh(Schedule $schedule): void
    {
        if (! $schedule->project_id || ! $schedule->date) {
            return;
        }

        $this->payrollRefreshService->queueRefreshForProjectDate(
            (int) $schedule->project_id,
            (string) $schedule->date
        );
    }
}
