<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\OvertimeLog;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Lembur otomatis dibuat saat check-in hari OFF (lihat OffDayOvertimeService + AttendanceController).
 * Endpoint ini untuk list & detail saja.
 */
class OvertimeLogController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth:sanctum');
    }

    /**
     * GET /api/overtime-logs
     */
    public function index(Request $request)
    {
        $validated = $request->validate([
            'project_id' => 'sometimes|exists:projects,id',
            'user_id' => 'sometimes|exists:users,id',
            'month' => 'sometimes|date_format:Y-m',
            'per_page' => 'sometimes|integer|min:1|max:100',
        ]);

        $query = OvertimeLog::with([
            'user',
            'scheduledAssignment',
            'workAssignment',
            'attendance',
            'schedule',
        ]);

        if (! empty($validated['project_id'])) {
            $query->where('project_id', $validated['project_id']);
        }

        if (! empty($validated['user_id'])) {
            $query->where('user_id', $validated['user_id']);
        }

        if (! empty($validated['month'])) {
            $start = \Carbon\Carbon::parse($validated['month'] . '-01')->startOfMonth();
            $end = \Carbon\Carbon::parse($validated['month'] . '-01')->endOfMonth();
            $query->whereBetween('date', [$start, $end]);
        }

        $logs = $query
            ->orderBy('date', 'desc')
            ->paginate($validated['per_page'] ?? 50);

        return response()->json([
            'success' => true,
            'data' => $logs,
        ]);
    }

    /**
     * GET /api/overtime-logs/{overtimeLog}
     */
    public function show(OvertimeLog $overtimeLog)
    {
        return response()->json([
            'success' => true,
            'data' => $overtimeLog->load([
                'user',
                'scheduledAssignment',
                'workAssignment',
                'attendance',
                'schedule',
                'project',
            ]),
        ], Response::HTTP_OK);
    }
}
