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
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;

class ScheduleController extends Controller
{
    private const SHEET_CACHE_TTL_SECONDS = 300;

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
        $this->bumpScheduleSheetCacheVersion($project->id);

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

        if (count($created) > 0) {
            $this->bumpScheduleSheetCacheVersion($project->id);
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
        $this->bumpScheduleSheetCacheVersion($schedule->project_id);

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

        $projectId = $schedule->project_id;
        $schedule->delete();
        $this->bumpScheduleSheetCacheVersion($projectId);

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

        $projectIds = Schedule::query()
            ->whereIn('id', $validated['ids'])
            ->distinct()
            ->pluck('project_id');

        Schedule::whereIn('id', $validated['ids'])->delete();
        foreach ($projectIds as $projectId) {
            $this->bumpScheduleSheetCacheVersion((int) $projectId);
        }

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

        $teamId = $request->query('team_id');
        $cacheVersion = $this->getScheduleSheetCacheVersion($project->id);
        $cacheKey = $this->scheduleSheetCacheKey($project->id, $month, $teamId, $cacheVersion);

        $data = Cache::remember(
            $cacheKey,
            now()->addSeconds(self::SHEET_CACHE_TTL_SECONDS),
            function () use ($project, $month, $teamId) {
                $service = new ScheduleSheetService();
                $sheetData = $service->generate($project->id, $month);

                if ($teamId) {
                    $sheetData['rows'] = collect($sheetData['rows'])
                        ->filter(function ($row) use ($teamId) {
                            return isset($row['user']['team_id']) && $row['user']['team_id'] == $teamId;
                        })
                        ->values()
                        ->all();
                }

                // Meta days (list tanggal & nama hari) untuk header grid
                $startDate = $sheetData['meta']['start_date'];
                $endDate = $sheetData['meta']['end_date'];

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

                $sheetData['meta']['days'] = $days;

                return $sheetData;
            }
        );

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
            'schedule_project_%d_%s.xlsx',
            $project->id,
            str_replace('-', '', $month)
        );

        return response()->streamDownload(function () use ($data, $days, $project, $month) {
            $spreadsheet = new Spreadsheet();
            $sheet = $spreadsheet->getActiveSheet();
            $sheet->setTitle('Schedule');

            // Helpers
            $cell = function (int $col, int $row): string {
                return Coordinate::stringFromColumnIndex($col) . $row;
            };
            $dayCode = function (string $date): string {
                $dow = \Carbon\Carbon::parse($date)->dayOfWeek; // 0=Sun
                return match ($dow) {
                    0 => 'MG',
                    1 => 'SN',
                    2 => 'SL',
                    3 => 'RB',
                    4 => 'KM',
                    5 => 'JM',
                    6 => 'SB',
                    default => '',
                };
            };

            // Layout constants
            $colUser = 1; // A
            $colTeam = 2; // B
            $firstDayCol = 3; // C
            $dayCount = count($days);
            $lastDayCol = $firstDayCol + max(0, $dayCount - 1);
            $summaryCols = [
                'SCHEDULE_COUNT',
                'HK',
                'OT',
                'OFF',
                'SAKIT',
                'IZIN',
                'CUTI',
                'ALPA',
            ];
            $firstSummaryCol = $lastDayCol + 1;
            $lastSummaryCol = $firstSummaryCol + count($summaryCols) - 1;

            // Title row
            $titleRow = 1;
            $headerRowDay = 2;
            $headerRowDate = 3;
            $row = 4;

            $title = sprintf(
                '%s %s',
                \Carbon\Carbon::parse($month . '-01')->translatedFormat('F Y'),
                $project->name ? ('- ' . $project->name) : ''
            );
            $sheet->setCellValue($cell($colUser, $titleRow), $title);
            $sheet->mergeCells($cell($colUser, $titleRow) . ':' . $cell($lastSummaryCol, $titleRow));
            $sheet->getStyle($cell($colUser, $titleRow))->getFont()->setBold(true)->setSize(14);
            $sheet->getStyle($cell($colUser, $titleRow))->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);

            // Header left labels (merged vertically)
            $sheet->setCellValue($cell($colUser, $headerRowDay), 'Nama');
            $sheet->setCellValue($cell($colTeam, $headerRowDay), 'Tim');
            $sheet->mergeCells($cell($colUser, $headerRowDay) . ':' . $cell($colUser, $headerRowDate));
            $sheet->mergeCells($cell($colTeam, $headerRowDay) . ':' . $cell($colTeam, $headerRowDate));

            // Day headers (two rows)
            foreach ($days as $i => $dayMeta) {
                $date = $dayMeta['date'];
                $col = $firstDayCol + $i;
                $sheet->setCellValue($cell($col, $headerRowDay), $dayCode($date));
                $sheet->setCellValue($cell($col, $headerRowDate), (int) \Carbon\Carbon::parse($date)->format('d'));
            }

            // Summary headers (merged vertically)
            foreach ($summaryCols as $i => $key) {
                $col = $firstSummaryCol + $i;
                $sheet->setCellValue($cell($col, $headerRowDay), $key);
                $sheet->mergeCells($cell($col, $headerRowDay) . ':' . $cell($col, $headerRowDate));
            }

            // Header styling
            $headerRange = $sheet->getStyle($cell($colUser, $headerRowDay) . ':' . $cell($lastSummaryCol, $headerRowDate));
            $headerRange->getFont()->setBold(true);
            $headerRange->getAlignment()
                ->setHorizontal(Alignment::HORIZONTAL_CENTER)
                ->setVertical(Alignment::VERTICAL_CENTER);
            $headerRange->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFEFEFEF');
            $headerRange->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN)->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color('FFCCCCCC'));

            // Column widths
            $sheet->getColumnDimension('A')->setWidth(26);
            $sheet->getColumnDimension('B')->setWidth(18);
            for ($c = $firstDayCol; $c <= $lastDayCol; $c++) {
                $sheet->getColumnDimensionByColumn($c)->setWidth(4);
            }
            for ($c = $firstSummaryCol; $c <= $lastSummaryCol; $c++) {
                $sheet->getColumnDimensionByColumn($c)->setWidth(14);
            }

            // Freeze header
            $sheet->freezePane($cell($firstDayCol, $row));

            // Group rows by team
            $rows = collect($data['rows'] ?? []);
            $groups = $rows->groupBy(fn ($r) => ($r['user']['team_name'] ?? 'Tanpa Tim') . '|' . ($r['user']['team_id'] ?? '0'));

            foreach ($groups as $teamKey => $teamRows) {
                [$teamName] = explode('|', (string) $teamKey, 2);

                // Team header row
                $sheet->setCellValue($cell($colUser, $row), $teamName);
                $sheet->mergeCells($cell($colUser, $row) . ':' . $cell($lastSummaryCol, $row));
                $teamStyle = $sheet->getStyle($cell($colUser, $row) . ':' . $cell($lastSummaryCol, $row));
                $teamStyle->getFont()->setBold(true);
                $teamStyle->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFDDEEFF');
                $teamStyle->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN)->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color('FFCCCCCC'));
                $row++;

                // Member rows
                foreach ($teamRows as $r) {
                    $user = $r['user'] ?? [];
                    $summary = $r['summary'] ?? [];
                    $daysData = $r['days'] ?? [];

                    $sheet->setCellValue($cell($colUser, $row), $user['name'] ?? '');
                    $sheet->setCellValue($cell($colTeam, $row), $user['team_name'] ?? '');

                    foreach ($days as $i => $dayMeta) {
                        $date = $dayMeta['date'];
                        $col = $firstDayCol + $i;
                        $val = $daysData[$date]['assignment'] ?? '';
                        $sheet->setCellValue($cell($col, $row), $val);
                    }

                    foreach ($summaryCols as $i => $key) {
                        $col = $firstSummaryCol + $i;
                        $sheet->setCellValue($cell($col, $row), (int) ($summary[$key] ?? 0));
                    }

                    // Row style
                    $sheet->getStyle($cell($colUser, $row) . ':' . $cell($lastSummaryCol, $row))
                        ->getBorders()->getAllBorders()
                        ->setBorderStyle(Border::BORDER_THIN)
                        ->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color('FFEEEEEE'));

                    $sheet->getStyle($cell($firstDayCol, $row) . ':' . $cell($lastSummaryCol, $row))
                        ->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

                    $row++;
                }

                // Spacer row
                $row++;
            }

            $writer = new Xlsx($spreadsheet);
            $writer->save('php://output');
        }, $fileName, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
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
        $this->bumpScheduleSheetCacheVersion($project->id);

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
        $this->bumpScheduleSheetCacheVersion($project->id);

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
        $this->bumpScheduleSheetCacheVersion($project->id);

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
        if ($deleted > 0) {
            $this->bumpScheduleSheetCacheVersion($project->id);
        }

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
        $this->bumpScheduleSheetCacheVersion($schedule->project_id);

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
        $this->bumpScheduleSheetCacheVersion($team->project_id);

        return response()->json([
            'message' => 'Team member added and schedule copied from leader.',
        ]);
    }

    private function scheduleSheetVersionKey(int $projectId): string
    {
        return "schedule_sheet:project:{$projectId}:version";
    }

    private function getScheduleSheetCacheVersion(int $projectId): int
    {
        return (int) Cache::rememberForever(
            $this->scheduleSheetVersionKey($projectId),
            fn() => 1
        );
    }

    private function bumpScheduleSheetCacheVersion(int $projectId): void
    {
        $versionKey = $this->scheduleSheetVersionKey($projectId);
        if (! Cache::has($versionKey)) {
            Cache::forever($versionKey, 1);
        }
        Cache::increment($versionKey);
    }

    private function scheduleSheetCacheKey(int $projectId, string $month, ?string $teamId, int $version): string
    {
        $team = $teamId ?: 'all';

        return sprintf(
            'schedule_sheet:project:%d:month:%s:team:%s:v:%d',
            $projectId,
            $month,
            $team,
            $version
        );
    }
}
