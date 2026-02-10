<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PayrollRun;
use App\Models\PayrollPolicy;
use App\Services\PayrollGenerationService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class PayrollRunController extends Controller
{
    protected PayrollGenerationService $generationService;

    public function __construct(PayrollGenerationService $generationService)
    {
        $this->generationService = $generationService;
        $this->middleware('auth:sanctum');
    }

    /**
     * POST /api/payroll-runs
     * Create payroll run
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'project_id' => 'required|exists:projects,id',
            'payroll_policy_id' => 'required|exists:payroll_policies,id',
            'year' => 'required|integer|min:2020',
            'month' => 'required|integer|min:1|max:12',
            'pay_period_start' => 'required|date',
            'pay_period_end' => 'required|date|after_or_equal:pay_period_start',
            'notes' => 'nullable|string',
        ]);

        try {
            // Check if payroll already exists for this period
            $existingPayroll = PayrollRun::byProject($validated['project_id'])
                ->byPeriod($validated['year'], $validated['month'])
                ->first();

            if ($existingPayroll) {
                return response()->json([
                    'success' => false,
                    'message' => 'Payroll sudah ada untuk periode ini',
                ], Response::HTTP_CONFLICT);
            }

            $payrollRun = PayrollRun::create($validated);

            return response()->json([
                'success' => true,
                'message' => 'Payroll run created',
                'data' => $payrollRun->load('policy'),
            ], Response::HTTP_CREATED);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], Response::HTTP_BAD_REQUEST);
        }
    }

    /**
     * GET /api/payroll-runs
     * List payroll runs
     */
    public function index(Request $request)
    {
        $query = PayrollRun::query();

        if ($request->has('project_id')) {
            $query->where('project_id', $request->project_id);
        }

        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        if ($request->has('year') && $request->has('month')) {
            $query->where('year', $request->year)->where('month', $request->month);
        }

        $runs = $query->with(['policy', 'finalizedBy'])
            ->orderBy('year', 'desc')
            ->orderBy('month', 'desc')
            ->paginate($request->per_page ?? 50);

        return response()->json([
            'success' => true,
            'data' => $runs,
        ]);
    }

    /**
     * GET /api/payroll-runs/{id}
     * Get single payroll run
     */
    public function show(PayrollRun $payrollRun)
    {
        return response()->json([
            'success' => true,
            'data' => $payrollRun->load(['policy', 'finalizedBy', 'payrollDetails' => function ($q) {
                $q->with(['user', 'assignment'])->limit(5);
            }]),
        ]);
    }

    /**
     * GET /api/payroll-runs/{id}/calculate
     * Calculate payroll details for all users
     */
    public function calculate(PayrollRun $payrollRun)
    {
        if (!$payrollRun->isDraft()) {
            return response()->json([
                'success' => false,
                'message' => 'Hanya DRAFT payroll yang bisa di-calculate',
            ], Response::HTTP_BAD_REQUEST);
        }

        try {
            // Delete existing details if any
            $payrollRun->payrollDetails()->delete();

            // Generate new details
            $detailsCreated = $this->generationService->generatePayrollDetails($payrollRun);

            $payrollRun = $payrollRun->refresh();

            return response()->json([
                'success' => true,
                'message' => 'Payroll calculated successfully',
                'data' => [
                    'payroll_run_id' => $payrollRun->id,
                    'status' => $payrollRun->status,
                    'total_employees' => $payrollRun->total_employees,
                    'details_created' => $detailsCreated,
                    'summary' => [
                        'total_base_salary' => $payrollRun->payrollDetails->sum('base_salary'),
                        'total_deductions' => $payrollRun->payrollDetails->sum('total_deductions'),
                        'total_additions' => $payrollRun->payrollDetails->sum('total_additions'),
                        'total_payroll_amount' => $payrollRun->payrollDetails->sum('net_salary'),
                    ],
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], Response::HTTP_BAD_REQUEST);
        }
    }

    /**
     * PATCH /api/payroll-runs/{id}/finalize
     * Finalize payroll run
     */
    public function finalize(Request $request, PayrollRun $payrollRun)
    {
        $validated = $request->validate([
            'finalized_by' => 'required|exists:users,id',
            'notes' => 'nullable|string',
        ]);

        try {
            $payrollRun = $this->generationService->finalizePayrollRun(
                $payrollRun,
                $validated['finalized_by'],
                $validated['notes'] ?? null
            );

            return response()->json([
                'success' => true,
                'message' => 'Payroll finalized',
                'data' => $payrollRun->load('finalizedBy'),
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], Response::HTTP_BAD_REQUEST);
        }
    }

    /**
     * PATCH /api/payroll-runs/{id}/mark-paid
     * Mark payroll as paid
     */
    public function markPaid(Request $request, PayrollRun $payrollRun)
    {
        $validated = $request->validate([
            'paid_date' => 'nullable|date',
            'notes' => 'nullable|string',
        ]);

        try {
            $payrollRun = $this->generationService->markPayrollAsPaid(
                $payrollRun,
                $validated['paid_date'] ?? null
            );

            if ($validated['notes'] ?? null) {
                $payrollRun->update(['notes' => $validated['notes']]);
            }

            return response()->json([
                'success' => true,
                'message' => 'Payroll marked as paid',
                'data' => $payrollRun,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], Response::HTTP_BAD_REQUEST);
        }
    }

    /**
     * PATCH /api/payroll-runs/{id}/cancel
     * Cancel payroll run
     */
    public function cancel(Request $request, PayrollRun $payrollRun)
    {
        $validated = $request->validate([
            'reason' => 'required|string',
        ]);

        try {
            $payrollRun = $this->generationService->cancelPayrollRun(
                $payrollRun,
                $validated['reason']
            );

            return response()->json([
                'success' => true,
                'message' => 'Payroll cancelled',
                'data' => $payrollRun,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], Response::HTTP_BAD_REQUEST);
        }
    }

    /**
     * PATCH /api/payroll-runs/{id}/recalculate
     * Recalculate payroll details
     */
    public function recalculate(PayrollRun $payrollRun)
    {
        try {
            $detailsCreated = $this->generationService->recalculatePayrollDetails($payrollRun);

            $payrollRun = $payrollRun->refresh();

            return response()->json([
                'success' => true,
                'message' => 'Payroll recalculated',
                'data' => [
                    'details_created' => $detailsCreated,
                    'total_payroll_amount' => $payrollRun->payrollDetails->sum('net_salary'),
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], Response::HTTP_BAD_REQUEST);
        }
    }
}
