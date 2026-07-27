<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PayrollProjectRule;
use App\Models\PayrollRun;
use App\Models\Project;
use App\Services\PayrollRefreshService;
use App\Services\PayrollService;
use Illuminate\Http\Request;

class PayrollProjectRuleController extends Controller
{
    public function __construct(
        protected PayrollService $payrollService,
        protected PayrollRefreshService $payrollRefreshService,
    ) {
        $this->middleware('auth:sanctum');
    }

    public function show(Project $project)
    {
        $this->authorize('viewAnyByProject', [PayrollRun::class, $project]);

        $rules = PayrollProjectRule::query()->where('project_id', $project->id)->first();

        return response()->json([
            'data' => $this->formatRules($rules),
        ]);
    }

    public function upsert(Project $project, Request $request)
    {
        $this->authorize('manage', [PayrollRun::class, $project]);

        $validated = $request->validate([
            'backup_rate' => 'nullable|numeric|min:0',
            'potongan_sakit' => 'nullable|numeric|min:0',
            'potongan_izin' => 'nullable|numeric|min:0',
            'potongan_cuti' => 'nullable|numeric|min:0',
            'potongan_alpha' => 'nullable|numeric|min:0',
            'potongan_soc_a' => 'nullable|numeric|min:0',
        ]);

        $rules = PayrollProjectRule::query()->updateOrCreate(
            ['project_id' => $project->id],
            array_merge([
                'backup_rate' => 0,
                'potongan_sakit' => 0,
                'potongan_izin' => 0,
                'potongan_cuti' => 0,
                'potongan_alpha' => 0,
                'potongan_soc_a' => 0,
            ], $validated)
        );

        $this->payrollRefreshService->refreshAllPeriodsForProject($project->id);

        return response()->json([
            'message' => 'Aturan payroll project berhasil disimpan.',
            'data' => $this->formatRules($rules),
        ]);
    }

    public function destroy(Project $project)
    {
        $this->authorize('manage', [PayrollRun::class, $project]);

        PayrollProjectRule::query()->where('project_id', $project->id)->delete();

        $this->payrollRefreshService->refreshAllPeriodsForProject($project->id);

        return response()->json([
            'message' => 'Aturan payroll project dihapus.',
        ]);
    }

    private function formatRules(?PayrollProjectRule $rules): array
    {
        return [
            'project_id' => $rules?->project_id,
            'backup_rate' => (float) ($rules?->backup_rate ?? 0),
            'potongan_sakit' => (float) ($rules?->potongan_sakit ?? 0),
            'potongan_izin' => (float) ($rules?->potongan_izin ?? 0),
            'potongan_cuti' => (float) ($rules?->potongan_cuti ?? 0),
            'potongan_alpha' => (float) ($rules?->potongan_alpha ?? 0),
            'potongan_soc_a' => (float) ($rules?->potongan_soc_a ?? 0),
        ];
    }
}
