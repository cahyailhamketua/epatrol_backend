<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Models\Schedule;
use App\Models\User;
use App\Models\Assignment;
use App\Models\Post;
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

        $query = Schedule::with(['project', 'post', 'user', 'assignment']);

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
                'post_id',
                'user_id',
                'assignment_id',
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

        $query = $project->schedules()->with(['post', 'user', 'assignment']);

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
                'post_id',
                'user_id',
                'assignment_id',
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
        $query = $user->schedules()->with(['project', 'post', 'assignment']);

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
                'post_id',
                'user_id',
                'assignment_id',
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
                'post_id' => 'required|exists:posts,id',
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

        // Check if post belongs to project
        $post = Post::find($validated['post_id']);
        if ($post->project_id !== $project->id) {
            return response()->json([
                'message' => 'Post does not belong to this project',
            ], 403);
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
        $schedule->load(['project', 'post', 'user', 'assignment']);

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
                'schedules.*.post_id' => 'required|exists:posts,id',
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
                // Check if post belongs to project
                $post = Post::find($scheduleData['post_id']);
                if ($post->project_id !== $project->id) {
                    $failed[] = [
                        'index' => $index,
                        'error' => 'Post does not belong to this project',
                    ];
                    continue;
                }

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
                $schedule->load(['project', 'post', 'user', 'assignment']);
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

        $schedule->load(['project', 'post', 'user', 'assignment']);

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
        $schedule->load(['project', 'post', 'user', 'assignment']);

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
     * GET /projects/{project}/schedules/sheet?from_date=2025-12-01&to_date=2025-12-31
     *
     * Returns schedules in a matrix format (users x dates)
     */
    public function sheet(Request $request, Project $project)
    {
        $this->authorize('viewAnyByProject', [Schedule::class, $project]);

        $validated = $request->validate([
            'from_date' => 'required|date_format:Y-m-d',
            'to_date' => 'required|date_format:Y-m-d',
        ]);

        $users = $project->users()
            ->select('id', 'full_name', 'username')
            ->orderBy('full_name')
            ->get();

        $schedules = $project->schedules()
            ->whereBetween('date', [$validated['from_date'], $validated['to_date']])
            ->with(['post', 'user', 'assignment'])
            ->get();

        // Group schedules by user_id and date
        $schedulesByUserAndDate = [];
        foreach ($schedules as $schedule) {
            $key = $schedule->user_id . '-' . $schedule->date;
            $schedulesByUserAndDate[$key] = [
                'assignment_code' => $schedule->assignment->code,
                'assignment_name' => $schedule->assignment->name,
                'assignment_id' => $schedule->assignment->id,
            ];
        }

        // Build sheet data
        $sheet = [];
        foreach ($users as $user) {
            $row = [
                'user_id' => $user->id,
                'user_name' => $user->full_name,
                'username' => $user->username,
                'schedules' => [],
            ];

            // Generate date range
            $date = new \DateTime($validated['from_date']);
            $endDate = new \DateTime($validated['to_date']);

            while ($date <= $endDate) {
                $dateStr = $date->format('Y-m-d');
                $key = $user->id . '-' . $dateStr;

                if (isset($schedulesByUserAndDate[$key])) {
                    $row['schedules'][$dateStr] = $schedulesByUserAndDate[$key];
                } else {
                    $row['schedules'][$dateStr] = null;
                }

                $date->modify('+1 day');
            }

            $sheet[] = $row;
        }

        return response()->json([
            'from_date' => $validated['from_date'],
            'to_date' => $validated['to_date'],
            'total_users' => count($users),
            'data' => $sheet,
        ]);
    }
}
