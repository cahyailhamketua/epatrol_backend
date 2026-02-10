<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PayrollPolicy;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class PayrollPolicyController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth:sanctum');
    }

    /**
     * POST /api/payroll-policies
     * Create new payroll policy
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'project_id' => 'required|exists:projects,id',
            'policy_code' => 'required|unique:payroll_policies,policy_code',
            'policy_name' => 'required|string|max:100',
            'description' => 'nullable|string',
            'effective_from' => 'required|date',
            'effective_to' => 'nullable|date|after:effective_from',
            'daily_rate' => 'required|numeric|min:0',
            'hourly_rate' => 'nullable|numeric|min:0',
            'late_deduction_per_minute' => 'required|numeric|min:0',
            'late_minimum_minutes' => 'nullable|integer|min:1|default:5',
            'absence_deduction_amount' => 'required|numeric|min:0',
            'alpha_deduction_amount' => 'required|numeric|min:0',
            'overtime_rate_percent' => 'nullable|numeric|min:0|default:150',
            'overtime_rate_amount' => 'nullable|numeric|min:0',
            'daily_allowance' => 'nullable|numeric|min:0|default:0',
            'shift_allowance_amount' => 'nullable|numeric|min:0|default:0',
            'perfect_attendance_bonus' => 'nullable|numeric|min:0|default:0',
            'status' => 'nullable|in:ACTIVE,INACTIVE|default:ACTIVE',
        ]);

        try {
            $policy = PayrollPolicy::create($validated);

            return response()->json([
                'success' => true,
                'message' => 'Payroll policy created',
                'data' => $policy,
            ], Response::HTTP_CREATED);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], Response::HTTP_BAD_REQUEST);
        }
    }

    /**
     * GET /api/payroll-policies
     * List payroll policies
     */
    public function index(Request $request)
    {
        $query = PayrollPolicy::query();

        if ($request->has('project_id')) {
            $query->where('project_id', $request->project_id);
        }

        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        if ($request->has('effective_on')) {
            $query->effectiveOn($request->effective_on);
        }

        $policies = $query->orderBy('effective_from', 'desc')
            ->paginate($request->per_page ?? 50);

        return response()->json([
            'success' => true,
            'data' => $policies,
        ]);
    }

    /**
     * GET /api/payroll-policies/{id}
     * Get single policy
     */
    public function show(PayrollPolicy $payrollPolicy)
    {
        return response()->json([
            'success' => true,
            'data' => $payrollPolicy,
        ]);
    }

    /**
     * PATCH /api/payroll-policies/{id}
     * Update policy
     */
    public function update(Request $request, PayrollPolicy $payrollPolicy)
    {
        $validated = $request->validate([
            'policy_name' => 'nullable|string|max:100',
            'description' => 'nullable|string',
            'effective_to' => 'nullable|date|after:effective_from',
            'daily_rate' => 'nullable|numeric|min:0',
            'hourly_rate' => 'nullable|numeric|min:0',
            'late_deduction_per_minute' => 'nullable|numeric|min:0',
            'late_minimum_minutes' => 'nullable|integer|min:1',
            'absence_deduction_amount' => 'nullable|numeric|min:0',
            'alpha_deduction_amount' => 'nullable|numeric|min:0',
            'overtime_rate_percent' => 'nullable|numeric|min:0',
            'overtime_rate_amount' => 'nullable|numeric|min:0',
            'daily_allowance' => 'nullable|numeric|min:0',
            'shift_allowance_amount' => 'nullable|numeric|min:0',
            'perfect_attendance_bonus' => 'nullable|numeric|min:0',
            'status' => 'nullable|in:ACTIVE,INACTIVE',
        ]);

        try {
            $payrollPolicy->update(array_filter($validated));

            return response()->json([
                'success' => true,
                'message' => 'Payroll policy updated',
                'data' => $payrollPolicy,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], Response::HTTP_BAD_REQUEST);
        }
    }

    /**
     * DELETE /api/payroll-policies/{id}
     * Delete policy (only if not used)
     */
    public function destroy(PayrollPolicy $payrollPolicy)
    {
        if ($payrollPolicy->payrollRuns()->exists()) {
            return response()->json([
                'success' => false,
                'message' => 'Tidak bisa delete policy yang sudah dipakai',
            ], Response::HTTP_BAD_REQUEST);
        }

        $payrollPolicy->delete();

        return response()->json([
            'success' => true,
            'message' => 'Payroll policy deleted',
        ]);
    }
}
