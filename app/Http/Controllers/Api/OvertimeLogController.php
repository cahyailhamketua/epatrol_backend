<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\OvertimeLog;
use App\Services\OvertimeService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class OvertimeLogController extends Controller
{
    protected OvertimeService $overtimeService;

    public function __construct(OvertimeService $overtimeService)
    {
        $this->overtimeService = $overtimeService;
        $this->middleware('auth:sanctum');
    }

    /**
     * POST /api/overtime-logs
     * Create overtime request
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'project_id' => 'required|exists:projects,id',
            'user_id' => 'required|exists:users,id',
            'assignment_id' => 'required|exists:assignments,id',
            'schedule_id' => 'required|exists:schedules,id',
            'date' => 'required|date',
            'overtime_type' => 'required|in:OFF_DUTY,EXTEND_SHIFT',
            'planned_start_time' => 'required|date_format:H:i:s',
            'planned_end_time' => 'required|date_format:H:i:s',
            'status' => 'nullable|in:PENDING,APPROVED|default:PENDING',
            'approved_by' => 'nullable|exists:users,id',
            'notes' => 'nullable|string',
        ]);

        try {
            $overtimeLog = $this->overtimeService->createOvertimeLog($validated);

            // If approved immediately, mark it
            if ($request->status === 'APPROVED' && $request->approved_by) {
                $overtimeLog = $this->overtimeService->approveOvertimeLog(
                    $overtimeLog,
                    $request->approved_by
                );
            }

            return response()->json([
                'success' => true,
                'message' => 'Overtime log created',
                'data' => $overtimeLog->load(['user', 'assignment']),
            ], Response::HTTP_CREATED);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], Response::HTTP_BAD_REQUEST);
        }
    }

    /**
     * GET /api/overtime-logs
     * List overtime logs
     */
    public function index(Request $request)
    {
        $query = OvertimeLog::query();

        if ($request->has('project_id')) {
            $query->where('project_id', $request->project_id);
        }

        if ($request->has('user_id')) {
            $query->where('user_id', $request->user_id);
        }

        if ($request->has('date')) {
            $query->where('date', $request->date);
        }

        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        if ($request->has('month') && $request->has('year')) {
            $startDate = \Carbon\Carbon::createFromDate($request->year, $request->month, 1);
            $endDate = $startDate->copy()->endOfMonth();
            $query->inPeriod($startDate, $endDate);
        }

        $logs = $query->with(['user', 'assignment', 'approvedBy'])
            ->orderBy('date', 'desc')
            ->paginate($request->per_page ?? 50);

        return response()->json([
            'success' => true,
            'data' => $logs,
        ]);
    }

    /**
     * GET /api/overtime-logs/{id}
     * Get single overtime log
     */
    public function show(OvertimeLog $overtimeLog)
    {
        return response()->json([
            'success' => true,
            'data' => $overtimeLog->load(['user', 'assignment', 'approvedBy', 'attendance']),
        ]);
    }

    /**
     * PATCH /api/overtime-logs/{id}/approve
     * Approve overtime log
     */
    public function approve(Request $request, OvertimeLog $overtimeLog)
    {
        $validated = $request->validate([
            'approved_by' => 'required|exists:users,id',
        ]);

        try {
            $overtimeLog = $this->overtimeService->approveOvertimeLog(
                $overtimeLog,
                $validated['approved_by']
            );

            return response()->json([
                'success' => true,
                'message' => 'Overtime log approved',
                'data' => $overtimeLog->load(['approvedBy']),
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], Response::HTTP_BAD_REQUEST);
        }
    }

    /**
     * PATCH /api/overtime-logs/{id}/reject
     * Reject overtime log
     */
    public function reject(OvertimeLog $overtimeLog)
    {
        try {
            $overtimeLog = $this->overtimeService->rejectOvertimeLog($overtimeLog);

            return response()->json([
                'success' => true,
                'message' => 'Overtime log rejected',
                'data' => $overtimeLog,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], Response::HTTP_BAD_REQUEST);
        }
    }

    /**
     * PATCH /api/overtime-logs/{id}/complete
     * Complete overtime with actual times
     */
    public function complete(Request $request, OvertimeLog $overtimeLog)
    {
        $validated = $request->validate([
            'actual_start_time' => 'required|date_format:H:i:s',
            'actual_end_time' => 'required|date_format:H:i:s',
        ]);

        try {
            $overtimeLog = $this->overtimeService->completeOvertimeLog(
                $overtimeLog,
                $validated['actual_start_time'],
                $validated['actual_end_time']
            );

            return response()->json([
                'success' => true,
                'message' => 'Overtime log completed',
                'data' => $overtimeLog,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], Response::HTTP_BAD_REQUEST);
        }
    }

    /**
     * DELETE /api/overtime-logs/{id}
     * Delete overtime log (only if PENDING)
     */
    public function destroy(OvertimeLog $overtimeLog)
    {
        if ($overtimeLog->status !== 'PENDING') {
            return response()->json([
                'success' => false,
                'message' => 'Hanya PENDING overtime yang bisa didelete',
            ], Response::HTTP_BAD_REQUEST);
        }

        $overtimeLog->delete();

        return response()->json([
            'success' => true,
            'message' => 'Overtime log deleted',
        ]);
    }
}
