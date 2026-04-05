<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Activity;
use App\Models\ActivityAssignmentTime;
use App\Models\Assignment;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class ActivityAssignmentTimeController extends Controller
{
    /**
     * LIST ALL ACTIVITY ASSIGNMENT TIMES
     * GET /activity-assignment-times
     * GET /activity-assignment-times?activity_id=1&assignment_id=1
     */
    public function index(Request $request)
    {
        $this->authorize('viewAny', ActivityAssignmentTime::class);

        $query = ActivityAssignmentTime::with(['activity', 'assignment']);

        // Filter by activity
        if ($request->has('activity_id')) {
            $query->where('activity_id', $request->activity_id);
        }

        // Filter by assignment
        if ($request->has('assignment_id')) {
            $query->where('assignment_id', $request->assignment_id);
        }

        $times = $query
            ->select('id', 'activity_id', 'assignment_id', 'start_time', 'end_time', 'created_at')
            ->orderBy('activity_id')
            ->paginate($request->get('per_page', 50));

        return response()->json($times);
    }

    /**
     * LIST ACTIVITY ASSIGNMENT TIMES FOR SPECIFIC ACTIVITY
     * GET /activities/{activity}/assignment-times
     */
    public function indexByActivity(Activity $activity)
    {
        $this->authorize('manage', $activity);

        $times = $activity->assignmentTimes()
            ->with('assignment')
            ->select('id', 'activity_id', 'assignment_id', 'start_time', 'end_time')
            ->orderBy('assignment_id')
            ->get();

        return response()->json([
            'data' => $times,
            'total' => $times->count(),
        ]);
    }

    /**
     * LIST ACTIVITY ASSIGNMENT TIMES FOR SPECIFIC ASSIGNMENT
     * GET /assignments/{assignment}/activity-times
     */
    public function indexByAssignment(Assignment $assignment)
    {
        $times = $assignment->activityTimes()
            ->with('activity')
            ->select('id', 'activity_id', 'assignment_id', 'start_time', 'end_time')
            ->orderBy('activity_id')
            ->get();

        return response()->json([
            'data' => $times,
            'total' => $times->count(),
        ]);
    }

    /**
     * CREATE ACTIVITY ASSIGNMENT TIME
     * POST /activities/{activity}/assignment-times
     *
     * Request Body:
     * {
     *   "assignment_id": 1,
     *   "start_time": "12:00",
     *   "end_time": "13:00"
     * }
     *
     * Use Case: Define when activity "Istirahat" happens during shift "Pagi" (12:00-13:00)
     */
    public function store(Request $request, Activity $activity)
    {
        $this->authorize('manage', $activity);

        try {
            $validated = $request->validate([
                'assignment_id' => 'required|exists:assignments,id',
                'start_time' => 'required|date_format:H:i',
                'end_time' => 'required|date_format:H:i|after:start_time',
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'success'     => false,
                'message'     => 'Validation failed',
                'status_code' => 422,
                'errors'      => $e->errors(),
            ], 422);
        }

        // Check if this combination already exists
        $existingTime = $activity->assignmentTimes()
            ->where('assignment_id', $validated['assignment_id'])
            ->first();

        if ($existingTime) {
            return response()->json([
                'message' => 'Activity assignment time already exists for this assignment',
            ], 409);
        }

        // Verify assignment exists and get project_id
        $assignment = Assignment::find($validated['assignment_id']);
        
        // Verify activity's post belongs to same project
        if ($activity->post->project_id !== $assignment->project_id) {
            return response()->json([
                'message' => 'Assignment does not belong to the same project as the post',
            ], 403);
        }

        $validated['activity_id'] = $activity->id;
        $time = ActivityAssignmentTime::create($validated);
        $time->load(['activity', 'assignment']);

        return response()->json([
            'message' => 'Activity assignment time created successfully',
            'data' => $time,
        ], 201);
    }

    /**
     * GET ACTIVITY ASSIGNMENT TIME DETAIL
     * GET /activity-assignment-times/{id}
     */
    public function show(ActivityAssignmentTime $activityAssignmentTime)
    {
        $this->authorize('view', $activityAssignmentTime);

        $activityAssignmentTime->load(['activity', 'assignment']);

        return response()->json([
            'data' => $activityAssignmentTime,
        ]);
    }

    /**
     * UPDATE ACTIVITY ASSIGNMENT TIME
     * PUT /activity-assignment-times/{id}
     *
     * Request Body:
     * {
     *   "start_time": "13:00",
     *   "end_time": "14:00"
     * }
     */
    public function update(Request $request, ActivityAssignmentTime $activityAssignmentTime)
    {
        $this->authorize('manage', $activityAssignmentTime->activity);

        try {
            $validated = $request->validate([
                'start_time' => 'sometimes|date_format:H:i',
                'end_time' => 'sometimes|date_format:H:i',
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'success'     => false,
                'message'     => 'Validation failed',
                'status_code' => 422,
                'errors'      => $e->errors(),
            ], 422);
        }

        // If both times are provided, validate end_time > start_time
        if (isset($validated['start_time']) && isset($validated['end_time'])) {
            if ($validated['end_time'] <= $validated['start_time']) {
                return response()->json([
                    'message' => 'End time must be after start time',
                ], 422);
            }
        } elseif (isset($validated['end_time'])) {
            // If only end_time is provided, validate against current start_time
            if ($validated['end_time'] <= $activityAssignmentTime->start_time) {
                return response()->json([
                    'message' => 'End time must be after start time',
                ], 422);
            }
        } elseif (isset($validated['start_time'])) {
            // If only start_time is provided, validate against current end_time
            if ($activityAssignmentTime->end_time <= $validated['start_time']) {
                return response()->json([
                    'message' => 'End time must be after start time',
                ], 422);
            }
        }

        $activityAssignmentTime->update($validated);
        $activityAssignmentTime->load(['activity', 'assignment']);

        return response()->json([
            'message' => 'Activity assignment time updated successfully',
            'data' => $activityAssignmentTime,
        ]);
    }

    /**
     * DELETE ACTIVITY ASSIGNMENT TIME
     * DELETE /activity-assignment-times/{id}
     */
    public function destroy(ActivityAssignmentTime $activityAssignmentTime)
    {
        $this->authorize('manage', $activityAssignmentTime->activity);

        $activityAssignmentTime->delete();

        return response()->json([
            'message' => 'Activity assignment time deleted successfully',
        ]);
    }

    /**
     * BULK CREATE ACTIVITY ASSIGNMENT TIMES
     * POST /activities/{activity}/assignment-times/bulk
     *
     * Request Body:
     * {
     *   "times": [
     *     {
     *       "assignment_id": 1,
     *       "start_time": "12:00",
     *       "end_time": "13:00"
     *     },
     *     {
     *       "assignment_id": 2,
     *       "start_time": "00:00",
     *       "end_time": "01:00"
     *     }
     *   ]
     * }
     *
     * Use Case: Define activity times for multiple shifts at once
     */
    public function storeBulk(Request $request, Activity $activity)
    {
        $this->authorize('manage', $activity);

        try {
            $validated = $request->validate([
                'times' => 'required|array|min:1',
                'times.*.assignment_id' => 'required|exists:assignments,id',
                'times.*.start_time' => 'required|date_format:H:i',
                'times.*.end_time' => 'required|date_format:H:i',
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

        foreach ($validated['times'] as $index => $timeData) {
            try {
                // Validate end_time > start_time
                if ($timeData['end_time'] <= $timeData['start_time']) {
                    $failed[] = [
                        'index' => $index,
                        'error' => 'End time must be after start time',
                    ];
                    continue;
                }

                // Check if this combination already exists
                $existingTime = $activity->assignmentTimes()
                    ->where('assignment_id', $timeData['assignment_id'])
                    ->first();

                if ($existingTime) {
                    $failed[] = [
                        'index' => $index,
                        'error' => 'Activity assignment time already exists for this assignment',
                    ];
                    continue;
                }

                // Verify assignment belongs to same project
                $assignment = Assignment::find($timeData['assignment_id']);
                if ($activity->post->project_id !== $assignment->project_id) {
                    $failed[] = [
                        'index' => $index,
                        'error' => 'Assignment does not belong to the same project',
                    ];
                    continue;
                }

                $timeData['activity_id'] = $activity->id;
                $time = ActivityAssignmentTime::create($timeData);
                $time->load(['activity', 'assignment']);
                $created[] = $time;
            } catch (\Exception $e) {
                $failed[] = [
                    'index' => $index,
                    'error' => $e->getMessage(),
                ];
            }
        }

        return response()->json([
            'message' => 'Bulk activity assignment time creation completed',
            'created' => count($created),
            'failed' => count($failed),
            'data' => $created,
            'errors' => $failed,
        ], 201);
    }

    /**
     * BULK DELETE ACTIVITY ASSIGNMENT TIMES
     * POST /activity-assignment-times/delete-bulk
     *
     * Request Body:
     * {
     *   "ids": [1, 2, 3]
     * }
     */
    public function destroyBulk(Request $request)
    {
        $this->authorize('viewAny', ActivityAssignmentTime::class);

        try {
            $validated = $request->validate([
                'ids' => 'required|array|min:1',
                'ids.*' => 'integer|exists:activity_assignment_times,id',
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'success'     => false,
                'message'     => 'Validation failed',
                'status_code' => 422,
                'errors'      => $e->errors(),
            ], 422);
        }

        ActivityAssignmentTime::whereIn('id', $validated['ids'])->delete();

        return response()->json([
            'message' => 'Activity assignment times deleted successfully',
            'deleted_count' => count($validated['ids']),
        ]);
    }
}
