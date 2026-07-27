<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PayrollRun;
use App\Models\PayrollTerBracket;
use App\Services\PayrollRefreshService;
use App\Services\PayrollService;
use Illuminate\Http\Request;

class PayrollTerBracketController extends Controller
{
    public function __construct(
        protected PayrollService $payrollService,
        protected PayrollRefreshService $payrollRefreshService,
    ) {
        $this->middleware('auth:sanctum');
    }

    public function index(Request $request)
    {
        $validated = $request->validate([
            'category' => 'sometimes|in:A,B,C',
        ]);

        $query = PayrollTerBracket::query()
            ->orderBy('category')
            ->orderBy('sort_order')
            ->orderBy('min_income');

        if (isset($validated['category'])) {
            $query->where('category', $validated['category']);
        }

        $rows = $query->get()->map(fn (PayrollTerBracket $row) => [
            'id' => $row->id,
            'category' => $row->category,
            'ptkp_group' => $row->ptkp_group,
            'min_income' => (float) $row->min_income,
            'max_income' => $row->max_income === null ? null : (float) $row->max_income,
            'rate' => (float) $row->rate,
            'rate_percent' => round((float) $row->rate * 100, 4),
        ])->values();

        $grouped = $rows->groupBy('category')->map(fn ($items, $category) => [
            'category' => $category,
            'ptkp_group' => $items->first()['ptkp_group'] ?? null,
            'brackets' => $items->values()->all(),
        ])->values();

        return response()->json([
            'data' => [
                'brackets' => $rows,
                'categories' => $grouped,
            ],
        ]);
    }

    public function store(Request $request)
    {
        $this->authorize('manage', PayrollTerBracket::class);

        $validated = $request->validate([
            'category' => 'required|in:A,B,C',
            'ptkp_group' => 'nullable|string|max:100',
            'min_income' => 'nullable|numeric',
            'max_income' => 'nullable|numeric',
            'rate' => 'required|numeric|min:0|max:1',
            'sort_order' => 'required|integer|min:0',
        ]);

        $bracket = PayrollTerBracket::create($validated);

        $this->refreshPayrollForTerBrackets();

        return response()->json([
            'message' => 'TER bracket created successfully',
            'data' => $bracket,
        ], 201);
    }

    public function update(Request $request, PayrollTerBracket $payrollTerBracket)
    {
        $this->authorize('manage', PayrollTerBracket::class);

        $validated = $request->validate([
            'category' => 'sometimes|in:A,B,C',
            'ptkp_group' => 'sometimes|nullable|string|max:100',
            'min_income' => 'sometimes|numeric|min:0',
            'max_income' => 'sometimes|nullable|numeric',
            'rate' => 'sometimes|numeric|min:0|max:1',
            'sort_order' => 'sometimes|integer|min:0',
        ]);

        $payrollTerBracket->update($validated);

        $this->refreshPayrollForTerBrackets();

        return response()->json([
            'message' => 'TER bracket updated successfully',
            'data' => $payrollTerBracket->fresh(),
        ]);
    }

    public function destroy(PayrollTerBracket $payrollTerBracket)
    {
        $this->authorize('manage', PayrollTerBracket::class);

        $payrollTerBracket->delete();

        $this->refreshPayrollForTerBrackets();

        return response()->json([
            'message' => 'TER bracket deleted successfully',
        ]);
    }

    private function refreshPayrollForTerBrackets(): void
    {
        $this->payrollRefreshService->refreshAllExistingPayrollRuns();
    }
}
