<?php

namespace App\Services;

use App\Models\PayrollRun;
use App\Models\Project;
use App\Models\User;
use App\Models\PayrollDetail;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class PayrollRefreshService
{
    /** @var array<string, true> */
    private array $queuedMonthlyRefreshes = [];

    /** @var array<string, true> */
    private array $completedMonthlyRefreshes = [];

    public function __construct(
        private readonly PayrollService $payrollService,
    ) {}

    public function queueRefreshForProjectMonth(int $projectId, string $month): void
    {
        if ($projectId <= 0 || ! preg_match('/^\d{4}-\d{2}$/', $month)) {
            return;
        }

        $this->queuedMonthlyRefreshes["{$projectId}:{$month}"] = true;
    }

    public function queueRefreshForProjectDate(int $projectId, string $date): void
    {
        $this->queueRefreshForProjectMonth(
            $projectId,
            Carbon::parse($date)->format('Y-m')
        );
    }

    /**
     * Queue then immediately flush (for explicit controller calls).
     */
    public function refreshForProjectMonth(int $projectId, string $month): void
    {
        $this->queueRefreshForProjectMonth($projectId, $month);
        $this->flushQueuedRefreshes();
    }

    public function refreshAllPeriodsForProject(int $projectId): void
    {
        if ($projectId <= 0) {
            return;
        }

        try {
            $project = Project::find($projectId);
            if (! $project) {
                return;
            }

            $periods = PayrollRun::query()
                ->where('project_id', $projectId)
                ->select('period')
                ->distinct()
                ->pluck('period');

            foreach ($periods as $period) {
                $this->performRefresh($project, $period);
            }
        } catch (\Throwable $e) {
            Log::warning('Payroll auto-recalculate for project failed: '.$e->getMessage(), [
                'project_id' => $projectId,
            ]);
        }
    }

    public function refreshAllExistingPayrollRuns(): void
    {
        try {
            $runs = PayrollRun::query()
                ->select('project_id', 'period')
                ->distinct()
                ->get();

            foreach ($runs as $run) {
                $project = Project::find($run->project_id);
                if ($project) {
                    $this->performRefresh($project, $run->period);
                }
            }
        } catch (\Throwable $e) {
            Log::warning('Payroll auto-recalculate all runs failed: '.$e->getMessage());
        }
    }

    public function flushQueuedRefreshes(): void
    {
        if ($this->queuedMonthlyRefreshes === []) {
            return;
        }

        $keys = array_keys($this->queuedMonthlyRefreshes);
        $this->queuedMonthlyRefreshes = [];

        foreach ($keys as $key) {
            [$projectId, $month] = explode(':', $key, 2);

            try {
                $project = Project::find((int) $projectId);
                if ($project) {
                    $this->performRefresh($project, $month);
                }
            } catch (\Throwable $e) {
                Log::warning('Payroll auto-recalculate failed: '.$e->getMessage(), [
                    'project_id' => (int) $projectId,
                    'month' => $month,
                ]);
            }
        }
    }

    private function performRefresh(Project $project, string $month): void
    {
        $key = "{$project->id}:{$month}";
        if (isset($this->completedMonthlyRefreshes[$key])) {
            return;
        }

        $this->completedMonthlyRefreshes[$key] = true;
        $this->payrollService->generateOrRefreshDraft($project, $month, true);
    }

    public function syncUserSnapshot(User $user): void
    {
        $updated = PayrollDetail::query()
            ->where('user_id', $user->id)
            ->whereHas('payrollRun', function ($q) {
                $q->where('status', PayrollRun::STATUS_DRAFT);
            })
            ->update([
                'user_nik' => $user->nik,
                'user_bank_name' => $user->bank_name,
                'user_bank_account' => $user->bank_account,
            ]);

        \Log::info('PAYROLL DETAIL UPDATED', [
            'user_id' => $user->id,
            'rows' => $updated,
        ]);
    }
}
