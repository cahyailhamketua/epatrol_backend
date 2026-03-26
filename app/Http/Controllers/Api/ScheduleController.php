<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Models\Schedule;
use App\Models\Team;
use App\Models\User;
use App\Models\Assignment;
use App\Models\TeamUser;
use App\Models\TemplateSchedule;
use App\Services\ScheduleGeneratorService;
use App\Services\ScheduleSheetService;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class ScheduleController extends Controller
{
    /**
     * LIST ALL SCHEDULES (WITH FILTERING)
     * GET /schedules
     * GET /schedules?project_id=1
     * GET /schedules?date=2025-12-06
     * GET /schedules?user_id=1
     */
    public function index(Request $request)
    {
        $this->authorize('viewAny', Schedule::class);

        $query = Schedule::with(['project', 'user', 'assignment']);

        // Filter by project
        if ($request->has('project_id')) {
            $query->where('project_id', $request->project_id);
        }

        // Filter by user
        if ($request->has('user_id')) {
            $query->where('user_id', $request->user_id);
        }

        // Filter by date
        if ($request->has('date')) {
            $query->whereDate('date', $request->date);
        }

        // Filter by date range
        if ($request->has('from_date') && $request->has('to_date')) {
            $query->whereBetween('date', [$request->from_date, $request->to_date]);
        }

        $schedules = $query
            ->select(
                'id',
                'project_id',
                'user_id',
                'assignment_id',
                'membership_status',
                'date',
                'created_at',
                'updated_at'
            )
            ->orderBy('date')
            ->orderBy('user_id')
            ->paginate(50);

        return response()->json([
            'data' => $schedules->items(),
            'pagination' => [
                'total' => $schedules->total(),
                'per_page' => $schedules->perPage(),
                'current_page' => $schedules->currentPage(),
                'last_page' => $schedules->lastPage(),
            ]
        ]);
    }

    /**
     * LIST SCHEDULES BY PROJECT
     * GET /projects/{project}/schedules
     * GET /projects/{project}/schedules?date=2025-12-06
     * GET /projects/{project}/schedules?from_date=2025-12-01&to_date=2025-12-31
     */
    public function indexByProject(Request $request, Project $project)
    {
        $this->authorize('viewAnyByProject', [Schedule::class, $project]);

        $query = $project->schedules()->with(['user', 'assignment']);

        // Filter by date
        if ($request->has('date')) {
            $query->whereDate('date', $request->date);
        }

        // Filter by date range
        if ($request->has('from_date') && $request->has('to_date')) {
            $query->whereBetween('date', [$request->from_date, $request->to_date]);
        }

        $schedules = $query
            ->select(
                'id',
                'project_id',
                'user_id',
                'assignment_id',
                'membership_status',
                'date',
                'created_at'
            )
            ->orderBy('date')
            ->orderBy('user_id')
            ->paginate(50);

        return response()->json([
            'data' => $schedules->items(),
            'pagination' => [
                'total' => $schedules->total(),
                'per_page' => $schedules->perPage(),
                'current_page' => $schedules->currentPage(),
                'last_page' => $schedules->lastPage(),
            ]
        ]);
    }

    /**
     * LIST SCHEDULES BY USER
     * GET /users/{user}/schedules
     * GET /users/{user}/schedules?from_date=2025-12-01&to_date=2025-12-31
     * GET /users/{user}/schedules?date=2025-12-06 (get specific date)
     */
    public function indexByUser(Request $request, User $user)
    {
        $query = $user->schedules()->with(['project', 'assignment']);

        // Filter by specific date
        if ($request->has('date')) {
            $query->whereDate('date', $request->date);
        }
        // Filter by date range
        elseif ($request->has('from_date') && $request->has('to_date')) {
            $query->whereBetween('date', [$request->from_date, $request->to_date]);
        }

        $schedules = $query
            ->select(
                'id',
                'project_id',
                'user_id',
                'assignment_id',
                'membership_status',
                'date',
                'created_at'
            )
            ->orderBy('date')
            ->orderBy('user_id')
            ->paginate(50);

        return response()->json([
            'data' => $schedules->items(),
            'pagination' => [
                'total' => $schedules->total(),
                'per_page' => $schedules->perPage(),
                'current_page' => $schedules->currentPage(),
                'last_page' => $schedules->lastPage(),
            ]
        ]);
    }

    /**
     * CREATE SCHEDULE
     * POST /projects/{project}/schedules
     *
     * Request Body:
     * {
     *   "user_id": 1,
     *   "assignment_id": 1,
     *   "date": "2025-12-06"
     * }
     */
    public function store(Request $request, Project $project)
    {
        $this->authorize('manage', [Schedule::class, $project]);

        try {
            $validated = $request->validate([
                'user_id' => 'required|exists:users,id',
                'assignment_id' => 'required|exists:assignments,id',
                'date' => 'required|date_format:Y-m-d',
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'success'     => false,
                'message'     => 'Validation failed',
                'status_code' => 422,
                'errors'      => $e->errors(),
            ], 422);
        }

        // Check if user belongs to project
        $user = User::find($validated['user_id']);
        if ($user->project_id !== $project->id) {
            return response()->json([
                'message' => 'User does not belong to this project',
            ], 403);
        }

        // Check if assignment belongs to project
        $assignment = Assignment::find($validated['assignment_id']);
        if ($assignment->project_id !== $project->id) {
            return response()->json([
                'message' => 'Assignment does not belong to this project',
            ], 403);
        }

        // Check if schedule already exists for this user on this date
        $existingSchedule = Schedule::where([
            'user_id' => $validated['user_id'],
            'date' => $validated['date'],
        ])->first();

        if ($existingSchedule) {
            return response()->json([
                'message' => 'Schedule already exists for this user on this date',
            ], 409);
        }

        $validated['project_id'] = $project->id;
        $schedule = Schedule::create($validated);
        $schedule->load(['project', 'user', 'assignment']);

        return response()->json([
            'message' => 'Schedule created successfully',
            'data' => $schedule,
        ], 201);
    }

    /**
     * CREATE BULK SCHEDULES
     * POST /projects/{project}/schedules/bulk
     *
     * Request Body:
     * {
     *   "schedules": [
     *     {
     *       "user_id": 1,
     *       "assignment_id": 1,
     *       "date": "2025-12-06"
     *     },
     *     {
     *       "user_id": 2,
     *       "assignment_id": 2,
     *       "date": "2025-12-06"
     *     }
     *   ]
     * }
     */
    public function storeBulk(Request $request, Project $project)
    {
        $this->authorize('manage', [Schedule::class, $project]);

        try {
            $validated = $request->validate([
                'schedules' => 'required|array|min:1',
                'schedules.*.user_id' => 'required|exists:users,id',
                'schedules.*.assignment_id' => 'required|exists:assignments,id',
                'schedules.*.date' => 'required|date_format:Y-m-d',
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'success'     => false,
                'message'     => 'Validation failed',
                'status_code' => 422,
                'errors'      => $e->errors(),
            ], 422);
        }

        $created = [];
        $failed = [];

        foreach ($validated['schedules'] as $index => $scheduleData) {
            try {
                // Check if user belongs to project
                $user = User::find($scheduleData['user_id']);
                if ($user->project_id !== $project->id) {
                    $failed[] = [
                        'index' => $index,
                        'error' => 'User does not belong to this project',
                    ];
                    continue;
                }

                // Check if assignment belongs to project
                $assignment = Assignment::find($scheduleData['assignment_id']);
                if ($assignment->project_id !== $project->id) {
                    $failed[] = [
                        'index' => $index,
                        'error' => 'Assignment does not belong to this project',
                    ];
                    continue;
                }

                // Check if schedule already exists
                $existingSchedule = Schedule::where([
                    'user_id' => $scheduleData['user_id'],
                    'date' => $scheduleData['date'],
                ])->first();

                if ($existingSchedule) {
                    $failed[] = [
                        'index' => $index,
                        'error' => 'Schedule already exists for this user on this date',
                    ];
                    continue;
                }

                $scheduleData['project_id'] = $project->id;
                $schedule = Schedule::create($scheduleData);
                $schedule->load(['project', 'user', 'assignment']);
                $created[] = $schedule;
            } catch (\Exception $e) {
                $failed[] = [
                    'index' => $index,
                    'error' => $e->getMessage(),
                ];
            }
        }

        return response()->json([
            'message' => 'Bulk schedule creation completed',
            'created' => count($created),
            'failed' => count($failed),
            'data' => $created,
            'errors' => $failed,
        ], 201);
    }

    /**
     * GET SCHEDULE DETAIL
     * GET /schedules/{schedule}
     */
    public function show(Schedule $schedule)
    {
        $this->authorize('view', $schedule);

        $schedule->load(['project', 'user', 'assignment']);

        return response()->json([
            'data' => $schedule,
        ]);
    }

    /**
     * UPDATE SCHEDULE
     * PUT /schedules/{schedule}
     *
     * Request Body:
     * {
     *   "assignment_id": 2,
     *   "date": "2025-12-07"
     * }
     */
    public function update(Request $request, Schedule $schedule)
    {
        $this->authorize('manage', [Schedule::class, $schedule->project]);

        try {
            $validated = $request->validate([
                'user_id' => 'sometimes|exists:users,id',
                'assignment_id' => 'sometimes|exists:assignments,id',
                'date' => 'sometimes|date_format:Y-m-d',
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'success'     => false,
                'message'     => 'Validation failed',
                'status_code' => 422,
                'errors'      => $e->errors(),
            ], 422);
        }

        // Check if trying to change user or date, check for conflicts
        if (isset($validated['user_id']) || isset($validated['date'])) {
            $userId = $validated['user_id'] ?? $schedule->user_id;
            $date = $validated['date'] ?? $schedule->date;

            $existingSchedule = Schedule::where([
                'user_id' => $userId,
                'date' => $date,
            ])
            ->where('id', '!=', $schedule->id)
            ->first();

            if ($existingSchedule) {
                return response()->json([
                    'message' => 'Schedule already exists for this user on this date',
                ], 409);
            }
        }

        // Check if assignment belongs to project
        if (isset($validated['assignment_id'])) {
            $assignment = Assignment::find($validated['assignment_id']);
            if ($assignment->project_id !== $schedule->project_id) {
                return response()->json([
                    'message' => 'Assignment does not belong to this project',
                ], 403);
            }
        }

        $schedule->update($validated);
        $schedule->load(['project', 'user', 'assignment']);

        return response()->json([
            'message' => 'Schedule updated successfully',
            'data' => $schedule,
        ]);
    }

    /**
     * DELETE SCHEDULE
     * DELETE /schedules/{schedule}
     */
    public function destroy(Schedule $schedule)
    {
        $this->authorize('manage', [Schedule::class, $schedule->project]);

        $schedule->delete();

        return response()->json([
            'message' => 'Schedule deleted successfully',
        ]);
    }

    /**
     * DELETE BULK SCHEDULES
     * POST /schedules/delete-bulk
     *
     * Request Body:
     * {
     *   "ids": [1, 2, 3]
     * }
     */
    public function destroyBulk(Request $request)
    {
        $this->authorize('viewAny', Schedule::class);

        $validated = $request->validate([
            'ids' => 'required|array|min:1',
            'ids.*' => 'integer|exists:schedules,id',
        ]);

        Schedule::whereIn('id', $validated['ids'])->delete();

        return response()->json([
            'message' => 'Schedules deleted successfully',
            'deleted_count' => count($validated['ids']),
        ]);
    }

    /**
     * GET SCHEDULE SHEET (GRID VIEW)
     * GET /projects/{project}/schedules/sheet?month=2025-12&team_id=1
     */
    public function sheet(Project $project, Request $request)
    {
        $this->authorize('viewAnyByProject', [Schedule::class, $project]);

        $month = $request->query('month');

        $request->validate([
            'month' => 'required|date_format:Y-m',
            'team_id' => 'nullable|exists:teams,id',
        ]);

        $service = new ScheduleSheetService();

        $data = $service->generate($project->id, $month);

        // Optional filter by team_id at response level
        $teamId = $request->query('team_id');
        if ($teamId) {
            $data['rows'] = collect($data['rows'])
                ->filter(function ($row) use ($teamId) {
                    return isset($row['user']['team_id']) && $row['user']['team_id'] == $teamId;
                })
                ->values()
                ->all();
        }

        // Meta days (list tanggal & nama hari) untuk header grid
        $startDate = $data['meta']['start_date'];
        $endDate = $data['meta']['end_date'];

        $days = [];
        $current = \Carbon\Carbon::parse($startDate)->copy();
        $end = \Carbon\Carbon::parse($endDate)->copy();
        while ($current->lessThanOrEqualTo($end)) {
            $days[] = [
                'date' => $current->format('Y-m-d'),
                'day_name' => $current->translatedFormat('l'),
            ];
            $current->addDay();
        }

        $data['meta']['days'] = $days;

        return response()->json($data);
    }

    /**
     * EXPORT SCHEDULE SHEET TO XLSX
     * GET /projects/{project}/schedules/export?month=2025-12&team_id=1
     */
    public function export(Project $project, Request $request)
    {
        $this->authorize('viewAnyByProject', [Schedule::class, $project]);

        $validated = $request->validate([
            'month' => 'required|date_format:Y-m',
            'team_id' => 'sometimes|nullable|exists:teams,id',
        ]);

        $month = $validated['month'];
        $teamId = $validated['team_id'] ?? null;

        $service = new ScheduleSheetService();
        $data = $service->generate($project->id, $month);

        // Filter by team if provided
        if ($teamId) {
            $data['rows'] = collect($data['rows'])
                ->filter(function ($row) use ($teamId) {
                    return isset($row['user']['team_id']) && (int) $row['user']['team_id'] === (int) $teamId;
                })
                ->values()
                ->all();
        }

        $days = $data['meta']['days'] ?? [];
        if (empty($days)) {
            $startDate = $data['meta']['start_date'];
            $endDate = $data['meta']['end_date'];
            $current = \Carbon\Carbon::parse($startDate)->copy();
            $end = \Carbon\Carbon::parse($endDate)->copy();
            while ($current->lessThanOrEqualTo($end)) {
                $days[] = [
                    'date' => $current->format('Y-m-d'),
                ];
                $current->addDay();
            }
        }

        $fileName = sprintf(
            'schedule_project_%d_%s.csv',
            $project->id,
            str_replace('-', '', $month)
        );

        return response()->streamDownload(function () use ($data, $days) {
            $handle = fopen('php://output', 'w');

            // Header
            $header = ['User', 'Team'];
            foreach ($days as $dayMeta) {
                $header[] = $dayMeta['date'];
            }
            $header = array_merge($header, ['SCHEDULE_COUNT', 'HK', 'OT', 'OFF', 'SAKIT', 'IZIN', 'CUTI', 'ALPA']);
            fputcsv($handle, $header);

            // Rows
            foreach ($data['rows'] as $row) {
                $user = $row['user'];
                $summary = $row['summary'] ?? [];
                $daysData = $row['days'] ?? [];

                $line = [
                    $user['name'] ?? '',
                    $user['team_name'] ?? '',
                ];

                foreach ($days as $dayMeta) {
                    $date = $dayMeta['date'];
                    $line[] = isset($daysData[$date]) ? ($daysData[$date]['assignment'] ?? '') : '';
                }

                $line[] = $summary['SCHEDULE_COUNT'] ?? 0;
                $line[] = $summary['HK'] ?? 0;
                $line[] = $summary['OT'] ?? 0;
                $line[] = $summary['OFF'] ?? 0;
                $line[] = $summary['SAKIT'] ?? 0;
                $line[] = $summary['IZIN'] ?? 0;
                $line[] = $summary['CUTI'] ?? 0;
                $line[] = $summary['ALPA'] ?? 0;

                fputcsv($handle, $line);
            }

            fclose($handle);
        }, $fileName, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    /**
     * GENERATE SCHEDULE FOR TEAM & MONTH
     * POST /projects/{project}/teams/{team}/schedules/generate
     */
    public function generateForTeam(Project $project, Team $team, Request $request)
    {
        $this->authorize('manage', [Schedule::class, $project]);

        $validated = $request->validate([
            'month' => 'required|date_format:Y-m',
            'pattern' => 'required|string',
            'overwrite_existing' => 'sometimes|boolean',
        ]);

        if ($team->project_id !== $project->id) {
            return response()->json([
                'message' => 'Team does not belong to this project',
            ], 403);
        }

        $month = $validated['month'];
        $targetStart = \Carbon\Carbon::parse($month . '-01')->startOfMonth();
        $targetEnd = \Carbon\Carbon::parse($month . '-01')->endOfMonth();
        $nowMonthStart = now()->startOfMonth();

        // Requirement: tidak boleh generate jadwal untuk bulan yang sudah lewat
        if ($targetEnd->lt($nowMonthStart)) {
            return response()->json([
                'message' => 'Tidak boleh generate schedule untuk bulan yang sudah lewat.',
                'target_month' => $month,
                'current_month' => now()->format('Y-m'),
            ], 422);
        }
        $overwrite = $validated['overwrite_existing'] ?? false;

        if ($overwrite) {
            $startDate = $targetStart;
            $endDate   = $targetEnd;

            Schedule::where('project_id', $project->id)
                ->where('team_id', $team->id)
                ->whereBetween('date', [$startDate, $endDate])
                ->delete();
        }

        $service = new ScheduleGeneratorService();

        $service->generate(
            $project->id,
            $month,
            $team->id,
            $validated['pattern']
        );

        return response()->json([
            'message' => 'Schedule generated successfully for team.',
        ]);
    }

    /**
     * SET OR UPDATE TEAM SCHEDULE TEMPLATE (PATTERN ONLY)
     * POST /projects/{project}/teams/{team}/schedule-template
     *
     * Body:
     * {
     *   "pattern": ["P","P","M","M","O","O"],
     *   "start_date": "2025-12-01" // optional, default: today
     * }
     */
    public function setTeamScheduleTemplate(Project $project, Team $team, Request $request)
    {
        $this->authorize('manage', [Schedule::class, $project]);

        if ($team->project_id !== $project->id) {
            return response()->json([
                'message' => 'Team does not belong to this project',
            ], 403);
        }

        $validated = $request->validate([
            'pattern' => 'required',
            'start_date' => 'sometimes|date_format:Y-m-d',
        ]);

        // Terima pattern baik sebagai array maupun string "P,P,M,M,O,O"
        $pattern = $validated['pattern'];
        if (is_string($pattern)) {
            $pattern = array_values(array_filter(array_map('trim', explode(',', $pattern))));
        }

        if (! is_array($pattern) || count($pattern) === 0) {
            return response()->json([
                'message' => 'Pattern must be a non-empty array or comma-separated string.',
            ], 422);
        }

        // Validasi bahwa semua kode assignment ada di project ini
        $codes = array_values(array_unique($pattern));

        $assignments = Assignment::where('project_id', $project->id)
            ->whereIn('code', $codes)
            ->get()
            ->keyBy('code');

        if ($assignments->count() !== count($codes)) {
            $missing = array_diff($codes, $assignments->keys()->all());

            return response()->json([
                'message' => 'Some assignment codes are not found in this project.',
                'missing_codes' => array_values($missing),
            ], 422);
        }

        $startDate = $validated['start_date'] ?? now()->toDateString();

        $template = TemplateSchedule::updateOrCreate(
            [
                'project_id' => $project->id,
                'team_id' => $team->id,
            ],
            [
                'pattern' => $pattern,
                'start_date' => $startDate,
            ]
        );

        return response()->json([
            'message' => 'Team schedule template saved successfully.',
            'data' => $template,
        ]);
    }

    /**
     * SHOW TEAM SCHEDULE TEMPLATE
     * GET /projects/{project}/teams/{team}/schedule-template
     */
    public function showTeamScheduleTemplate(Project $project, Team $team)
    {
        $this->authorize('viewAnyByProject', [Schedule::class, $project]);

        if ($team->project_id !== $project->id) {
            return response()->json([
                'message' => 'Team does not belong to this project',
            ], 403);
        }

        $template = TemplateSchedule::where('project_id', $project->id)
            ->where('team_id', $team->id)
            ->first();

        return response()->json([
            'data' => $template,
        ]);
    }

    /**
     * GENERATE SCHEDULE FOR TEAM & MONTH BASED ON CONTINUOUS TEMPLATE PATTERN
     * POST /projects/{project}/teams/{team}/schedules/generate-from-template
     *
     * Body:
     * {
     *   "month": "2026-01",
     *   "template_month": "2025-12", // optional, default: month sebelumnya
     *   "overwrite_existing": true   // optional
     * }
     */
    public function generateForTeamFromTemplate(Project $project, Team $team, Request $request)
    {
        $this->authorize('manage', [Schedule::class, $project]);

        $validated = $request->validate([
            'month' => 'required|date_format:Y-m',
            'template_month' => 'sometimes|date_format:Y-m',
            'overwrite_existing' => 'sometimes|boolean',
        ]);

        if ($team->project_id !== $project->id) {
            return response()->json([
                'message' => 'Team does not belong to this project',
            ], 403);
        }

        $targetMonth = $validated['month'];
        $targetStart = \Carbon\Carbon::parse($targetMonth . '-01')->startOfMonth();
        $targetEnd   = \Carbon\Carbon::parse($targetMonth . '-01')->endOfMonth();
        $nowMonthStart = now()->startOfMonth();

        // Requirement: tidak boleh generate jadwal untuk bulan yang sudah lewat
        if ($targetEnd->lt($nowMonthStart)) {
            return response()->json([
                'message' => 'Tidak boleh generate schedule untuk bulan yang sudah lewat.',
                'target_month' => $targetMonth,
                'current_month' => now()->format('Y-m'),
            ], 422);
        }

        // Tentukan bulan template: dari request atau default ke bulan sebelumnya
        if (! empty($validated['template_month'])) {
            $templateMonth = $validated['template_month'];
        } else {
            $templateMonth = $targetStart->copy()->subMonth()->format('Y-m');
        }

        // Ambil template pattern untuk tim
        $template = TemplateSchedule::where('project_id', $project->id)
            ->where('team_id', $team->id)
            ->first();

        if (! $template) {
            return response()->json([
                'message' => 'Team does not have a schedule template yet.',
            ], 422);
        }

        $patternCodes = $template->pattern ?? [];
        if (! is_array($patternCodes) || count($patternCodes) === 0) {
            return response()->json([
                'message' => 'Team schedule template pattern is empty or invalid.',
            ], 422);
        }

        $cycleLength = count($patternCodes);

        $templateStart = \Carbon\Carbon::parse($template->start_date)->startOfDay();

        // Hitung offset berdasarkan selisih hari dari awal template ke awal bulan target
        $daysSinceStart = $templateStart->diffInDays($targetStart);
        $offset = $daysSinceStart % $cycleLength;

        // Optional: hapus jadwal existing di bulan target
        $overwrite = $validated['overwrite_existing'] ?? false;
        if ($overwrite) {
            Schedule::where('project_id', $project->id)
                ->where('team_id', $team->id)
                ->whereBetween('date', [$targetStart, $targetEnd])
                ->delete();
        }

        // Siapkan map assignment code -> Assignment untuk project ini
        $assignmentMap = Assignment::where('project_id', $project->id)
            ->whereIn('code', $patternCodes)
            ->get()
            ->keyBy('code');

        // Generate jadwal untuk anggota tim yang aktif di bulan target
        // (ambil pivot start/end supaya membership_status bisa prorate)
        $memberships = TeamUser::query()
            ->where('team_id', $team->id)
            ->whereDate('start_date', '<=', $targetEnd)
            ->where(function ($q) use ($targetStart) {
                $q->whereNull('end_date')
                    ->orWhereDate('end_date', '>=', $targetStart);
            })
            ->with('user')
            ->get()
            ->filter(fn($m) => $m->user && $m->user->active)
            ->values();

        $memberIds = $memberships->pluck('user_id')->unique()->values();

        if ($memberIds->isEmpty()) {
            return response()->json([
                'message' => 'Team has no members to generate schedules for.',
            ], 422);
        }

        // Jika jadwal bulan target sudah pernah tergenerate sebelumnya,
        // pastikan user yang sudah tidak aktif lagi tidak tersisa.
        Schedule::where('project_id', $project->id)
            ->where('team_id', $team->id)
            ->whereBetween('date', [$targetStart, $targetEnd])
            ->whereNotIn('user_id', $memberIds)
            ->delete();

        $dayIndex = $offset;
        $current = $targetStart->copy();

        while ($current->lessThanOrEqualTo($targetEnd)) {
            $code = $patternCodes[$dayIndex % $cycleLength];
            $assignment = $assignmentMap[$code] ?? null;

            if (! $assignment) {
                return response()->json([
                    'message' => "Assignment with code '{$code}' not found in project.",
                ], 422);
            }

            foreach ($memberships as $membership) {
                $member = $membership->user;
                $memberStart = $membership->start_date ? \Carbon\Carbon::parse($membership->start_date) : $targetStart;
                $memberEnd = $membership->end_date ? \Carbon\Carbon::parse($membership->end_date) : null;

                // PRORATE-IN: user bergabung setelah tanggal 1 bulan target
                if ($memberStart->gt($targetStart)) {
                    $membershipStatus = Schedule::STATUS_PRORATE_IN;
                } else {
                    // PRORATE-OUT: user keluar sebelum akhir bulan target
                    $membershipStatus = Schedule::STATUS_FULL_EXISTING;
                    if ($memberEnd && $memberEnd->lt($targetEnd)) {
                        $membershipStatus = Schedule::STATUS_PRORATE_OUT;
                    }
                }

                Schedule::updateOrCreate(
                    [
                        'project_id' => $project->id,
                        'user_id' => $member->id,
                        'date' => $current->format('Y-m-d'),
                    ],
                    [
                        'assignment_id' => $assignment->id,
                        'team_id' => $team->id,
                        'membership_status' => $membershipStatus,
                    ]
                );
            }

            $dayIndex++;
            $current->addDay();
        }

        return response()->json([
            'message' => 'Schedule generated from template pattern successfully for team.',
            'target_month' => $targetMonth,
            'template_month' => $templateMonth,
        ]);
    }

    /**
     * DELETE ALL SCHEDULES FOR A TEAM IN A MONTH
     * DELETE /projects/{project}/teams/{team}/schedules?month=2025-12
     */
    public function destroyForTeam(Project $project, Team $team, Request $request)
    {
        $this->authorize('manage', [Schedule::class, $project]);

        $validated = $request->validate([
            'month' => 'required|date_format:Y-m',
        ]);

        if ($team->project_id !== $project->id) {
            return response()->json([
                'message' => 'Team does not belong to this project',
            ], 403);
        }

        $month = $validated['month'];
        $startDate = \Carbon\Carbon::parse($month . '-01')->startOfMonth();
        $endDate   = \Carbon\Carbon::parse($month . '-01')->endOfMonth();

        $deleted = Schedule::where('project_id', $project->id)
            ->where('team_id', $team->id)
            ->whereBetween('date', [$startDate, $endDate])
            ->delete();

        return response()->json([
            'message' => 'Team schedules deleted successfully.',
            'deleted_count' => $deleted,
        ]);
    }

    /**
     * UPDATE ASSIGNMENT (CELL EDIT) FOR A SCHEDULE
     * PATCH /schedules/{schedule}
     */
    public function updateAssignment(Request $request, Schedule $schedule)
    {
        $this->authorize('manage', [Schedule::class, $schedule->project]);

        $validated = $request->validate([
            'assignment_code' => 'required|string|exists:assignments,code',
            'team_id' => 'sometimes|nullable|exists:teams,id',
        ]);

        // Pastikan assignment berasal dari project yang sama
        $assignment = Assignment::where('code', $validated['assignment_code'])
            ->where('project_id', $schedule->project_id)
            ->firstOrFail();

        $updateData = [
            'assignment_id' => $assignment->id,
        ];

        if (array_key_exists('team_id', $validated)) {
            if ($validated['team_id']) {
                $team = Team::where('id', $validated['team_id'])
                    ->where('project_id', $schedule->project_id)
                    ->firstOrFail();
                $updateData['team_id'] = $team->id;
            } else {
                $updateData['team_id'] = null;
            }
        }

        $schedule->update($updateData);

        return response()->json([
            'message' => 'Schedule updated successfully',
            'data' => [
                'id' => $schedule->id,
                'assignment_code' => $assignment->code,
                'assignment_name' => $assignment->name,
                'team_id' => $schedule->team_id,
            ],
        ]);
    }

    /**
     * ADD MEMBER TO TEAM AND COPY LEADER SCHEDULE
     * POST /teams/{team}/members
     */
    public function addTeamMember(Request $request, Team $team)
    {
        $this->authorize('manage', [Schedule::class, $team->project]);

        $validated = $request->validate([
            'user_id' => 'required|exists:users,id',
            'start_date' => 'sometimes|date_format:Y-m-d',
            'month' => 'required|date_format:Y-m',
        ]);

        $user = User::findOrFail($validated['user_id']);

        if ($user->project_id !== $team->project_id) {
            return response()->json([
                'message' => 'User does not belong to this project',
            ], 403);
        }

        // Buat/Update membership di pivot team_user
        TeamUser::updateOrCreate(
            [
                'team_id' => $team->id,
                'user_id' => $user->id,
            ],
            [
                'start_date' => $validated['start_date'] ?? now()->toDateString(),
                'end_date' => null,
            ]
        );

        // Copy jadwal ketua regu untuk bulan yang diminta
        $leaderId = $team->leader_id;

        if (!$leaderId) {
            return response()->json([
                'message' => 'Team leader is not set',
            ], 422);
        }

        $month = $validated['month'];
        $startDate = \Carbon\Carbon::parse($month . '-01')->startOfMonth();
        $endDate   = \Carbon\Carbon::parse($month . '-01')->endOfMonth();

        $nowMonthStart = now()->startOfMonth();
        if ($endDate->lt($nowMonthStart)) {
            return response()->json([
                'message' => 'Tidak boleh menyalin schedule untuk bulan yang sudah lewat.',
                'target_month' => $month,
                'current_month' => now()->format('Y-m'),
            ], 422);
        }
        $memberStartDate = isset($validated['start_date'])
            ? \Carbon\Carbon::parse($validated['start_date'])->startOfDay()
            : now()->startOfDay();
        $isProrateIn = $memberStartDate->greaterThan($startDate->copy()->startOfDay())
            && $memberStartDate->lessThanOrEqualTo($endDate->copy()->endOfDay());
        $memberStatus = $isProrateIn ? Schedule::STATUS_PRORATE_IN : Schedule::STATUS_FULL_EXISTING;

        $leaderSchedules = Schedule::where('project_id', $team->project_id)
            ->where('team_id', $team->id)
            ->where('user_id', $leaderId)
            ->whereBetween('date', [$startDate, $endDate])
            ->get();

        foreach ($leaderSchedules as $leaderSchedule) {
            Schedule::updateOrCreate(
                [
                    'project_id' => $leaderSchedule->project_id,
                    'user_id' => $user->id,
                    'date' => $leaderSchedule->date,
                ],
                [
                    'assignment_id' => $leaderSchedule->assignment_id,
                    'team_id' => $team->id,
                    'membership_status' => $memberStatus,
                ]
            );
        }

        return response()->json([
            'message' => 'Team member added and schedule copied from leader.',
        ]);
    }
}
