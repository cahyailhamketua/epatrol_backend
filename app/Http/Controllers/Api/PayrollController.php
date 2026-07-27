<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PayrollRun;
use App\Models\PayrollDetail;
use App\Models\Project;
use App\Models\User;
use App\Services\PayrollService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class PayrollController extends Controller
{
    public function __construct(private readonly PayrollService $payrollService)
    {
        $this->middleware('auth:sanctum');
    }

    public function sheet(Project $project, Request $request)
    {
        $this->authorize('viewAnyByProject', [PayrollRun::class, $project]);

        $validated = $request->validate([
            'month' => 'required|date_format:Y-m',
            'refresh' => 'sometimes|boolean',
        ]);

        if (($validated['refresh'] ?? false) === true) {
            $this->payrollService->generateOrRefreshDraft($project, $validated['month'], true);
        }

        return response()->json(
            $this->payrollService->sheet($project, $validated['month'])
        );
    }

    public function downloadSheet(Project $project, Request $request)
    {
        $this->authorize('viewAnyByProject', [PayrollRun::class, $project]);

        $validated = $request->validate([
            'month' => 'required|date_format:Y-m',
        ]);

        $sheetData = $this->payrollService->sheet($project, $validated['month']);
        $spreadsheet = $this->payrollService->buildSpreadsheet($sheetData);
        $fileName = 'payroll_project_'.$project->id.'_'.str_replace('-', '', $validated['month']).'.xlsx';

        return response()->streamDownload(function () use ($spreadsheet) {
            $writer = new Xlsx($spreadsheet);
            $writer->save('php://output');
        }, $fileName, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    public function release(Project $project, Request $request)
    {
        $this->authorize('manage', [PayrollRun::class, $project]);

        $validated = $request->validate([
            'month' => 'required|date_format:Y-m',
            'notes' => 'nullable|string',
        ]);

        $run = PayrollRun::query()
            ->where('project_id', $project->id)
            ->where('period', $validated['month'])
            ->first();

        if (! $run) {
            $run = $this->payrollService->generateOrRefreshDraft($project, $validated['month']);
        }

        $released = $this->payrollService->release($run, $request->user(), $validated['notes'] ?? null);

        return response()->json([
            'message' => 'Payroll berhasil dirilis.',
            'data' => [
                'run_id' => $released->id,
                'status' => $released->status,
                'released_at' => $released->released_at,
            ],
        ]);
    }

    public function recalculate(Project $project, Request $request)
    {
        $this->authorize('manage', [PayrollRun::class, $project]);

        $validated = $request->validate([
            'month' => 'required|date_format:Y-m',
        ]);

        $run = $this->payrollService->generateOrRefreshDraft($project, $validated['month'], true);

        return response()->json([
            'message' => 'Payroll draft berhasil dihitung ulang.',
            'data' => [
                'run_id' => $run->id,
                'status' => $run->status,
                'total_employees' => $run->total_employees,
                'total_payroll_amount' => (float) $run->total_payroll_amount,
            ],
        ]);
    }

    public function indexTemplates(Project $project)
    {
        $this->authorize('viewAnyByProject', [PayrollRun::class, $project]);

        return response()->json([
            'data' => $this->payrollService->listUserTemplates($project),
        ]);
    }

    public function showTemplate(Project $project, User $user)
    {
        $this->authorize('viewAnyByProject', [PayrollRun::class, $project]);

        return response()->json([
            'data' => $this->payrollService->formatUserTemplate($project, $user),
        ]);
    }

    public function upsertTemplates(Project $project, Request $request)
    {
        $this->authorize('manage', [PayrollRun::class, $project]);

        $validated = $request->validate([
            'month' => 'required|date_format:Y-m',
            'user_id' => 'required|exists:users,id',
            'gaji_pokok' => 'nullable|numeric|min:0',
            'bpjs_tk_tambah' => 'nullable|numeric|min:0',
            'bpjs_kes_tambah' => 'nullable|numeric|min:0',
            'tukin_budget' => 'nullable|numeric|min:0',
            'bonus_thr' => 'nullable|numeric|min:0',
            'bpjs_tk_potongan' => 'nullable|numeric|min:0',
            'bpjs_kes_potongan' => 'nullable|numeric|min:0',
            'sanksi_sp' => 'nullable|numeric|min:0',
            'pinjaman_potongan' => 'nullable|numeric|min:0',
            'lain_lain_potongan' => 'nullable|numeric|min:0',
            'ptkp_status' => ['nullable', 'string', Rule::in(PayrollService::PTKP_STATUSES)],
        ]);

        $user = User::findOrFail($validated['user_id']);
        $template = $this->payrollService->syncUserTemplate($project, $user, $validated);

        $month = $validated['month'];
        $run = PayrollRun::query()
            ->where('project_id', $project->id)
            ->where('period', $month)
            ->first();

        if ($run && $run->isFinalized()) {
            return response()->json([
                'message' => 'Template disimpan. Payroll periode ini sudah dirilis; snapshot tidak diubah. Template akan dipakai untuk periode berikutnya.',
                'payroll_locked' => true,
                'data' => [
                    'template' => $template,
                    'sheet' => $this->payrollService->sheet($project, $month),
                ],
            ]);
        }

        $this->payrollService->generateOrRefreshDraft($project, $month, false);

        return response()->json([
            'message' => 'Template disimpan dan sheet payroll draft diperbarui.',
            'payroll_locked' => false,
            'data' => [
                'template' => $template,
                'sheet' => $this->payrollService->sheet($project, $month),
            ],
        ]);
    }

    public function myHistory(Request $request)
    {
        $validated = $request->validate([
            'project_id' => 'sometimes|integer|exists:projects,id',
            'year' => 'required|integer|min:2000|max:2100',
        ]);

        $query = PayrollDetail::query()
            ->with('payrollRun')
            ->where('user_id', $request->user()->id)
            ->whereHas('payrollRun', function ($q) use ($validated) {
                $q->where('status', PayrollRun::STATUS_FINALIZED)
                    ->where('year', $validated['year']);
            });

        if (isset($validated['project_id'])) {
            $query->where('project_id', $validated['project_id']);
        }

        $rows = $query->orderByDesc('period')->get()->map(function (PayrollDetail $detail) {
            $arRow = collect($detail->other_breakdown ?? [])->firstWhere('key', 'ar');

            return [
                'month' => $detail->period,
                'project_id' => $detail->project_id,
                'thp' => (float) ($arRow['amount'] ?? $detail->net_salary),
            ];
        })->values();

        return response()->json([
            'data' => $rows,
        ]);
    }

    public function myDetail(Request $request, string $month)
    {
        $request->validate([
            'project_id' => 'sometimes|integer|exists:projects,id',
        ]);

        $detail = PayrollDetail::query()
            ->with(['payrollRun', 'user', 'project.organization'])
            ->where('user_id', $request->user()->id)
            ->where('period', $month)
            ->when($request->filled('project_id'), fn ($q) => $q->where('project_id', $request->integer('project_id')))
            ->whereHas('payrollRun', fn ($q) => $q->where('status', PayrollRun::STATUS_FINALIZED))
            ->firstOrFail();

        $this->authorize('view', $detail);

        return response()->json([
            'data' => $this->payrollService->formatPayrollSlip($detail),
        ]);
    }

    public function projectSlips(Project $project, Request $request)
    {
        $this->authorize('viewAnyByProject', [PayrollRun::class, $project]);

        $validated = $request->validate([
            'month' => 'required|date_format:Y-m',
        ]);

        return response()->json([
            'data' => $this->payrollService->listProjectSlips($project, $validated['month']),
        ]);
    }

    public function projectHistory(Project $project, Request $request)
    {
        $this->authorize('viewAnyByProject', [PayrollRun::class, $project]);

        $validated = $request->validate([
            'year' => 'required|integer|min:2000|max:2100',
        ]);

        return response()->json([
            'data' => $this->payrollService->projectPayrollHistory($project, (int) $validated['year']),
        ]);
    }

    public function mySlipDownload(Request $request, string $month)
    {
        $request->validate([
            'project_id' => 'sometimes|integer|exists:projects,id',
        ]);

        $detail = PayrollDetail::query()
            ->with('payrollRun')
            ->where('user_id', $request->user()->id)
            ->where('period', $month)
            ->when($request->filled('project_id'), fn ($q) => $q->where('project_id', $request->integer('project_id')))
            ->whereHas('payrollRun', fn ($q) => $q->where('status', PayrollRun::STATUS_FINALIZED))
            ->firstOrFail();

        $this->authorize('view', $detail);

        $content = [
            'Slip Gaji '.$detail->period,
            'Nama: '.($request->user()->full_name ?? ''),
            'NIK: '.($detail->user_nik ?? '-'),
            'Take Home Pay: Rp '.number_format((float) $detail->net_salary, 0, ',', '.'),
        ];

        return response(implode("\n", $content), 200, [
            'Content-Type' => 'text/plain; charset=utf-8',
            'Content-Disposition' => 'attachment; filename=slip-gaji-'.$detail->period.'.txt',
        ]);
    }
}
