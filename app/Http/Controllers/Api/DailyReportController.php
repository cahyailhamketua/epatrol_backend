<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\DailyReportStoreRequest;
use App\Models\Attendance;
use App\Models\DailyReport;
use App\Models\DailyReportEquipmentCheck;
use App\Models\DailyReportPersonnelCondition;
use App\Models\DailyReportUniformCheck;
use App\Models\DailyReportUniformPersonnel;
use App\Models\EquipmentComponent;
use App\Models\Project;
use App\Models\UniformComponent;
use App\Services\DailyReportPdfService;
use App\Support\SignedMediaUrl;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;
use Illuminate\Support\Str;

class DailyReportController extends Controller
{
    public function __construct(
        protected DailyReportPdfService $pdfService,
    ) {}

    /**
     * POST /projects/{project}/daily-reports
     */
    public function store(DailyReportStoreRequest $request, Project $project)
    {
        $this->authorize('create', DailyReport::class);

        $user = $request->user();
        $projectId = $project->id;
        $reportDate = Carbon::parse($request->input('report_date'))->toDateString();

        $validated = $request->validated();
        $presentPersonnel = $this->computePresentPersonnel($project, $reportDate);

        try {
            $report = DB::transaction(function () use ($request, $project, $user, $projectId, $reportDate, $validated, $presentPersonnel) {
                $createdReport = DailyReport::create([
                    'project_id' => $projectId,
                    'user_id' => $user->id,
                    'report_date' => $reportDate,
                    'bos_name' => $validated['bos_name'] ?? null,
                    'bos_position' => $validated['bos_position'] ?? null,
                    'shift' => $validated['shift'] ?? null,
                    'total_personnel' => (int) ($validated['total_personnel'] ?? 0),
                    'present_personnel' => $presentPersonnel,
                    'absent_personnel' => $validated['absent_personnel'] ?? [],
                    'general_information' => $validated['general_information'] ?? null,
                    'further_escalation' => $validated['further_escalation'] ?? null,
                    'incidents' => $validated['incidents'] ?? [],
                    'berita_acara' => $validated['berita_acara'] ?? [],
                ]);

                // 1. Save personnel condition rows.
                if (! empty($validated['personnel_conditions'] ?? [])) {
                    foreach ($validated['personnel_conditions'] as $condition) {
                        DailyReportPersonnelCondition::create([
                            'daily_report_id' => $createdReport->id,
                            'user_id' => $condition['user_id'],
                            'position' => $condition['position'] ?? null,
                            'physical_condition' => $condition['physical_condition'],
                            'remarks' => $condition['remarks'] ?? null,
                        ]);
                    }
                }

                // 2. Create missing uniform components for this project automatically.
                $uniformComponentCache = [];
                foreach ($validated['new_uniform_components'] ?? [] as $componentName) {
                    $normalizedName = trim((string) $componentName);
                    if ($normalizedName === '') {
                        continue;
                    }

                    $component = UniformComponent::firstOrCreate([
                        'project_id' => $projectId,
                        'name' => $normalizedName,
                    ]);

                    $uniformComponentCache[$normalizedName] = $component->id;
                }

                // 3. Create missing equipment components automatically.
                $equipmentComponentCache = [];
                foreach ($validated['new_equipment_components'] ?? [] as $componentPayload) {
                    $normalizedName = trim((string) ($componentPayload['name'] ?? ''));
                    if ($normalizedName === '') {
                        continue;
                    }

                    $component = EquipmentComponent::firstOrCreate([
                        'project_id' => $projectId,
                        'name' => $normalizedName,
                    ], [
                        'standard_quantity' => (int) ($componentPayload['standard_quantity'] ?? 0),
                    ]);

                    $equipmentComponentCache[$normalizedName] = $component->id;
                }

                // 4. Save uniform personnel and their checks.
                if (! empty($validated['uniform_personnels'] ?? [])) {
                    foreach ($validated['uniform_personnels'] as $uniformPersonnel) {
                        $uniformPerson = DailyReportUniformPersonnel::create([
                            'daily_report_id' => $createdReport->id,
                            'user_id' => $uniformPersonnel['user_id'],
                            'overall_status' => $uniformPersonnel['overall_status'],
                            'notes' => $uniformPersonnel['notes'] ?? null,
                        ]);

                        foreach ($uniformPersonnel['checks'] as $check) {
                            $uniformComponentId = $check['uniform_component_id'] ?? null;
                            if (! $uniformComponentId && ! empty($check['uniform_component_name'])) {
                                $uniformComponentId = $uniformComponentCache[trim($check['uniform_component_name'])] ?? null;
                            }

                            if (! $uniformComponentId) {
                                continue;
                            }

                            DailyReportUniformCheck::create([
                                'uniform_personnel_id' => $uniformPerson->id,
                                'uniform_component_id' => $uniformComponentId,
                                'status' => $check['status'],
                            ]);
                        }
                    }
                }

                // 5. Save equipment checks.
                if (! empty($validated['equipment_checks'] ?? [])) {
                    foreach ($validated['equipment_checks'] as $check) {

                        $equipmentComponentId =
                            $check['equipment_component_id'] ?? null;

                        if (
                            ! $equipmentComponentId &&
                            ! empty($check['equipment_component_name'])
                        ) {
                            $equipmentComponentId =
                                $equipmentComponentCache[
                                    trim($check['equipment_component_name'])
                                ] ?? null;
                        }

                        if (! $equipmentComponentId) {
                            continue;
                        }

                        // Update standard quantity jika dikirim
                        if (
                            isset($check['standard_quantity']) &&
                            $check['standard_quantity'] !== null
                        ) {
                            EquipmentComponent::query()
                                ->where('id', $equipmentComponentId)
                                ->update([
                                    'standard_quantity' =>
                                        (int) $check['standard_quantity'],
                                ]);
                        }

                        DailyReportEquipmentCheck::create([
                            'daily_report_id' => $createdReport->id,
                            'equipment_component_id' => $equipmentComponentId,
                            'available_quantity' =>
                                (int) ($check['available_quantity'] ?? 0),
                            'condition' => $check['condition'],
                            'remarks' => $check['remarks'] ?? null,
                        ]);
                    }
                }

                $pdfPath = $this->pdfService->generateAndSave($createdReport, $project);
                $createdReport->update(['pdf_path' => $pdfPath]);

                return $createdReport->fresh([
                    'personnelConditions',
                    'uniformPersonnels.checks',
                    'equipmentChecks',
                    'project.organization',
                ]);
            });

            return response()->json([
                'success' => true,
                'message' => 'Daily report created',
                'data' => [
                    'daily_report_id' => $report->id,
                    'pdf_path' => $report->pdf_path,
                ],
            ], 201);
        } catch (Throwable $e) {
            Log::error('Failed to create daily report', [
                'project_id' => $projectId,
                'user_id' => $user->id,
                'report_date' => $reportDate,
                'message' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to create daily report.',
            ], 500);
        }
    }

    protected function computePresentPersonnel(Project $project, string $reportDate): int
    {
        return (int) Attendance::query()
            ->where('project_id', $project->id)
            ->whereDate('date', $reportDate)
            ->whereIn('attendance_status', ['HADIR', 'HADIR TELAT'])
            ->distinct('user_id')
            ->count('user_id');
    }

    public function index(Request $request, Project $project)
    {
        $this->authorize('viewAny', DailyReport::class);

        $month = $request->input(
            'month',
            now()->format('Y-m')
        );

        $reports = DailyReport::query()
            ->with('creator:id,full_name,role')
            ->where('project_id', $project->id)
            ->byMonth($month)
            ->latest('report_date')
            ->get()
            ->map(function ($report) {

                return [
                    'id' => $report->id,
                    'report_date' => $report->report_date
                        ?->format('Y-m-d'),

                    'shift' => $report->shift,

                    'total_personnel' =>
                        $report->total_personnel,

                    'present_personnel' =>
                        $report->present_personnel,

                    'created_by' => [
                        'id' => $report->creator?->id,
                        'full_name' =>
                            $report->creator?->full_name,

                        'role' =>
                            $report->creator?->role,
                    ],

                    'pdf_url' => $report->pdf_path
                        ? SignedMediaUrl::dailyReport($report)
                        : null,

                    'created_at' => $report->created_at,
                ];
            });

        return response()->json([
            'success' => true,
            'data' => $reports,
        ]);
    }

    public function show(Project $project, DailyReport $dailyReport) 
    {
        $this->authorize('view', $dailyReport);

        abort_if(
            $dailyReport->project_id !== $project->id,
            404
        );

        $dailyReport->load([
            'creator',
            'personnelConditions.user',
            'uniformPersonnels.user',
            'uniformPersonnels.checks.component',
            'equipmentChecks.equipmentComponent',
        ]);

        return response()->json([
            'success' => true,
            'data' => [
                ...$dailyReport->toArray(),
                'pdf_url' => $dailyReport->pdf_path
                    ? SignedMediaUrl::dailyReport($dailyReport)
                    : null,
            ],
        ]);
    }

    public function download(Project $project, DailyReport $dailyReport)
    {
        $this->authorize('download', $dailyReport);

        abort_if(
            $dailyReport->project_id !== $project->id,
            404
        );

        abort_if(
            ! $dailyReport->pdf_path || ! Storage::disk('public')->exists($dailyReport->pdf_path),
            404,
            'File PDF tidak ditemukan'
        );

        // 1. Pastikan relasi creator (User) di-load
        $dailyReport->loadMissing('creator');

        // 2. Format Tanggal (misal: 2026-07-20)
        $reportDate = $dailyReport->report_date 
            ? $dailyReport->report_date->format('Y_m_d') 
            : now()->format('Y_m_d');

        // 3. Ambil nama user & bersihkan karakter spasi/simbol khusus
        $rawUserName = $dailyReport->creator->full_name ?? $dailyReport->creator->name ?? 'user';
        $sanitizedUserName = Str::slug($rawUserName, '_'); // Mengubah "John Doe" menjadi "john_doe"

        // 4. Susun nama file
        $fileName = "laporan_harian_{$reportDate}_{$sanitizedUserName}.pdf";

        return Storage::disk('public')->download($dailyReport->pdf_path, $fileName);
    }

    public function destroy(Project $project, DailyReport $dailyReport)
    {
        $this->authorize('delete', $dailyReport);

        abort_if(
            $dailyReport->project_id !== $project->id,
            404
        );

        if (
            $dailyReport->pdf_path &&
            Storage::disk('public')->exists($dailyReport->pdf_path)
        ) {
            Storage::disk('public')->delete(
                $dailyReport->pdf_path
            );
        }

        $dailyReport->delete();

        return response()->json([
            'success' => true,
            'message' => 'Daily report deleted.',
        ]);
    }
}
