<?php

namespace App\Console\Commands;

use App\Models\Project;
use App\Services\PayrollService;
use Carbon\Carbon;
use Illuminate\Console\Command;

class PreparePayrollDraftsCommand extends Command
{
    protected $signature = 'payroll:prepare-drafts
        {--month= : Format YYYY-MM, default bulan sebelumnya}
        {--project_id=* : Optional filter project id}
        {--force : Hitung ulang meski sudah finalized}';

    protected $description = 'Menyiapkan draft payroll bulanan per project.';

    public function handle(PayrollService $payrollService): int
    {
        $month = $this->option('month') ?: Carbon::now()->subMonth()->format('Y-m');
        $projectIds = array_filter($this->option('project_id') ?? []);

        $projects = Project::query()
            ->when(! empty($projectIds), fn ($q) => $q->whereIn('id', $projectIds))
            ->where('active', true)
            ->get();

        foreach ($projects as $project) {
            try {
                $run = $payrollService->generateOrRefreshDraft(
                    $project,
                    $month,
                    (bool) $this->option('force')
                );

                $this->info("Project {$project->id}: payroll run {$run->id} siap.");
            } catch (\Throwable $e) {
                $this->error("Project {$project->id}: {$e->getMessage()}");
            }
        }

        return self::SUCCESS;
    }
}
