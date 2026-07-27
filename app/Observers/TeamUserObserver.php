<?php

namespace App\Observers;

use App\Models\TeamUser;
use App\Services\PayrollRefreshService;
use Carbon\Carbon;

class TeamUserObserver
{
    public function __construct(
        private readonly PayrollRefreshService $payrollRefreshService,
    ) {}

    public function created(TeamUser $teamUser): void
    {
        $this->queuePayrollRefresh($teamUser);
    }

    public function updated(TeamUser $teamUser): void
    {
        $this->queuePayrollRefresh($teamUser);

        if ($teamUser->wasChanged(['start_date', 'end_date', 'team_id'])) {
            $this->queuePayrollRefreshFromOriginal($teamUser);
        }
    }

    public function deleted(TeamUser $teamUser): void
    {
        $this->queuePayrollRefresh($teamUser);
    }

    private function queuePayrollRefresh(TeamUser $teamUser): void
    {
        $team = $teamUser->relationLoaded('team')
            ? $teamUser->team
            : $teamUser->team()->first();

        if (! $team?->project_id) {
            return;
        }

        $projectId = (int) $team->project_id;

        if ($teamUser->start_date) {
            $this->payrollRefreshService->queueRefreshForProjectMonth(
                $projectId,
                Carbon::parse($teamUser->start_date)->format('Y-m')
            );
        }

        if ($teamUser->end_date) {
            $this->payrollRefreshService->queueRefreshForProjectMonth(
                $projectId,
                Carbon::parse($teamUser->end_date)->format('Y-m')
            );
        }

        if (! $teamUser->start_date && ! $teamUser->end_date) {
            $this->payrollRefreshService->queueRefreshForProjectMonth(
                $projectId,
                now()->format('Y-m')
            );
        }
    }

    private function queuePayrollRefreshFromOriginal(TeamUser $teamUser): void
    {
        $originalTeamId = (int) ($teamUser->getOriginal('team_id') ?? 0);
        if ($originalTeamId <= 0) {
            return;
        }

        $team = \App\Models\Team::find($originalTeamId);
        if (! $team?->project_id) {
            return;
        }

        $projectId = (int) $team->project_id;
        $originalStart = $teamUser->getOriginal('start_date');
        $originalEnd = $teamUser->getOriginal('end_date');

        if ($originalStart) {
            $this->payrollRefreshService->queueRefreshForProjectMonth(
                $projectId,
                Carbon::parse($originalStart)->format('Y-m')
            );
        }

        if ($originalEnd) {
            $this->payrollRefreshService->queueRefreshForProjectMonth(
                $projectId,
                Carbon::parse($originalEnd)->format('Y-m')
            );
        }
    }
}
