<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Absence;
use App\Models\Schedule;
use App\Services\AbsenceService;
use App\Services\ScheduleCacheService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class AbsenceController extends Controller
{
    public function __construct(
        protected AbsenceService $absenceService,
        protected ScheduleCacheService $scheduleCacheService
    )
    {
        $this->middleware('auth:sanctum');
    }

    /**
     * POST /api/absences
     * Upsert absence untuk satu sel schedule (admin HO / admin lapangan).
     *
     * Body: { "schedule_id": 1, "absence_type": "C" }  // C|S|I|A|SOC-A
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'schedule_id' => 'required|exists:schedules,id',
            'absence_type' => 'required|in:C,S,I,A,SOC-A',
        ]);

        $schedule = Schedule::with('project')->findOrFail($validated['schedule_id']);
        $this->authorize('manageForProject', [Absence::class, $schedule->project]);

        try {
            $absence = $this->absenceService->upsertForSchedule(
                $validated['schedule_id'],
                $validated['absence_type']
            );

            $this->scheduleCacheService->bumpScheduleSheetCacheVersion($schedule->project->id);

            return response()->json([
                'success' => true,
                'message' => 'Absence saved',
                'data' => $absence->load('schedule'),
            ], Response::HTTP_CREATED);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], Response::HTTP_BAD_REQUEST);
        }
    }

    /**
     * PATCH /api/absences/{absence}
     * Ubah tipe absence (masih 1 baris per schedule).
     */
    public function update(Request $request, Absence $absence)
    {
        $absence->load('schedule.project');
        $this->authorize('manageForProject', [Absence::class, $absence->schedule->project]);

        $validated = $request->validate([
            'absence_type' => 'required|in:C,S,I,A,SOC-A',
        ]);

        $absence->update(['absence_type' => $validated['absence_type']]);

        $this->scheduleCacheService->bumpScheduleSheetCacheVersion($absence->schedule->project->id);

        return response()->json([
            'success' => true,
            'message' => 'Absence updated',
            'data' => $absence->fresh()->load('schedule'),
        ]);
    }

    /**
     * GET /api/absences
     * Filter: project_id (wajib untuk scope), user_id, month YYYY-MM, absence_type
     */
    public function index(Request $request)
    {
        $this->authorize('viewAny', Absence::class);

        $validated = $request->validate([
            'project_id' => 'required|exists:projects,id',
            'user_id' => 'sometimes|exists:users,id',
            'month' => 'sometimes|date_format:Y-m',
            'absence_type' => 'sometimes|in:C,S,I,A,SOC-A',
            'per_page' => 'sometimes|integer|min:1|max:100',
        ]);

        $project = \App\Models\Project::findOrFail($validated['project_id']);
        $this->authorize('viewForProject', [Absence::class, $project]);

        $query = Absence::query()
            ->with(['schedule.user', 'schedule.assignment'])
            ->join('schedules', 'schedules.id', '=', 'absences.schedule_id')
            ->where('schedules.project_id', $validated['project_id']);

        if (! empty($validated['user_id'])) {
            $query->where('schedules.user_id', $validated['user_id']);
        }

        if (! empty($validated['month'])) {
            $start = \Carbon\Carbon::parse($validated['month'] . '-01')->startOfMonth();
            $end = \Carbon\Carbon::parse($validated['month'] . '-01')->endOfMonth();
            $query->whereBetween('schedules.date', [$start, $end]);
        }

        if (! empty($validated['absence_type'])) {
            $query->where('absences.absence_type', $validated['absence_type']);
        }

        $absences = $query
            ->select('absences.*')
            ->orderBy('schedules.date', 'desc')
            ->paginate($validated['per_page'] ?? 50);

        return response()->json([
            'success' => true,
            'data' => $absences,
        ]);
    }

    /**
     * GET /api/absences/{absence}
     */
    public function show(Absence $absence)
    {
        $absence->load(['schedule.project', 'schedule.user', 'schedule.assignment']);
        $this->authorize('viewForProject', [Absence::class, $absence->schedule->project]);

        return response()->json([
            'success' => true,
            'data' => $absence,
        ]);
    }

    /**
     * DELETE /api/absences/{absence}
     * Hapus keterangan absence pada sel tersebut.
     */
    public function destroy(Absence $absence)
    {
        $absence->load('schedule.project');
        $this->authorize('manageForProject', [Absence::class, $absence->schedule->project]);

        $absence->delete();
        $this->scheduleCacheService->bumpScheduleSheetCacheVersion($absence->schedule->project->id);

        return response()->json([
            'success' => true,
            'message' => 'Absence deleted',
        ]);
    }

    /**
     * DELETE /api/schedules/{schedule}/absence
     * Alternatif hapus by schedule_id (tanpa id absence).
     */
    public function destroyBySchedule(Schedule $schedule)
    {
        $schedule->load('project');
        $this->authorize('manageForProject', [Absence::class, $schedule->project]);

        $deleted = Absence::where('schedule_id', $schedule->id)->delete();

        $this->scheduleCacheService->bumpScheduleSheetCacheVersion($schedule->project->id);

        if ($deleted) {
            app(\App\Services\PayrollRefreshService::class)->queueRefreshForProjectDate(
                (int) $schedule->project_id,
                (string) $schedule->date
            );
        }

        return response()->json([
            'success' => true,
            'message' => $deleted ? 'Absence deleted' : 'No absence for this schedule',
            'deleted' => (bool) $deleted,
        ]);
    }
}
