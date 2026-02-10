<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Absence;
use App\Services\AbsenceService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class AbsenceController extends Controller
{
    protected AbsenceService $absenceService;

    public function __construct(AbsenceService $absenceService)
    {
        $this->absenceService = $absenceService;
        $this->middleware('auth:sanctum');
    }

    /**
     * POST /api/absences
     * Create new absence
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'project_id' => 'required|exists:projects,id',
            'user_id' => 'required|exists:users,id',
            'schedule_id' => 'required|exists:schedules,id',
            'assignment_id' => 'required|exists:assignments,id',
            'date' => 'required|date',
            'absence_type' => 'required|in:SAKIT,IZIN,CUTI',
            'attachment_url' => 'nullable|url',
            'notes' => 'nullable|string',
        ]);

        try {
            $absence = $this->absenceService->createAbsence($validated);

            return response()->json([
                'success' => true,
                'message' => 'Absence created successfully',
                'data' => $absence->load(['user', 'assignment', 'schedule']),
            ], Response::HTTP_CREATED);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], Response::HTTP_BAD_REQUEST);
        }
    }

    /**
     * GET /api/absences
     * List absences with filters
     */
    public function index(Request $request)
    {
        $query = Absence::query();

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

        if ($request->has('absence_type')) {
            $query->where('absence_type', $request->absence_type);
        }

        $absences = $query->with(['user', 'assignment', 'schedule', 'approvedBy'])
            ->orderBy('date', 'desc')
            ->paginate($request->per_page ?? 50);

        return response()->json([
            'success' => true,
            'data' => $absences,
        ]);
    }

    /**
     * GET /api/absences/{id}
     * Get single absence detail
     */
    public function show(Absence $absence)
    {
        return response()->json([
            'success' => true,
            'data' => $absence->load(['user', 'assignment', 'schedule', 'approvedBy']),
        ]);
    }

    /**
     * PATCH /api/absences/{id}/approve
     * Approve absence
     */
    public function approve(Request $request, Absence $absence)
    {
        $validated = $request->validate([
            'approved_by' => 'required|exists:users,id',
            'notes' => 'nullable|string',
        ]);

        try {
            $absence = $this->absenceService->approveAbsence(
                $absence,
                $validated['approved_by'],
                $validated['notes'] ?? null
            );

            return response()->json([
                'success' => true,
                'message' => 'Absence approved',
                'data' => $absence->load(['approvedBy']),
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], Response::HTTP_BAD_REQUEST);
        }
    }

    /**
     * PATCH /api/absences/{id}/reject
     * Reject absence
     */
    public function reject(Request $request, Absence $absence)
    {
        $validated = $request->validate([
            'rejection_reason' => 'required|string',
        ]);

        try {
            $absence = $this->absenceService->rejectAbsence(
                $absence,
                $validated['rejection_reason']
            );

            return response()->json([
                'success' => true,
                'message' => 'Absence rejected',
                'data' => $absence,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], Response::HTTP_BAD_REQUEST);
        }
    }

    /**
     * DELETE /api/absences/{id}
     * Delete absence (only if PENDING)
     */
    public function destroy(Absence $absence)
    {
        if ($absence->status !== 'PENDING') {
            return response()->json([
                'success' => false,
                'message' => 'Hanya PENDING absence yang bisa didelete',
            ], Response::HTTP_BAD_REQUEST);
        }

        $absence->delete();

        return response()->json([
            'success' => true,
            'message' => 'Absence deleted',
        ]);
    }
}
