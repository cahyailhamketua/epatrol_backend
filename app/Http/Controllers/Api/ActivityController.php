<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Activity;
use App\Models\Post;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ActivityController extends Controller
{
    /**
     * LIST ACTIVITY BY POST + assignment
     * GET /posts/{post}/activities?assignment_id=
     */
    public function index(Request $request, Post $post)
    {
        $this->authorize('viewAny', Activity::class);

        $request->validate([
            'assignment_id' => 'nullable|exists:assignments,id',
        ]);

        $activities = Activity::where('post_id', $post->id)
            ->where('active', true)
            ->whereHas('assignmentTimes', function ($q) use ($request) {
                $q->where('assignment_id', $request->assignment_id);
            })
            ->with(['assignmentTimes' => function ($q) use ($request) {
                $q->where('assignment_id', $request->assignment_id);
            }])
            ->orderBy('name')
            ->get();

        return response()->json([
            'data' => $activities,
        ]);
    }

    /**
     * CREATE ACTIVITY + assignment TIMES
     * POST /posts/{post}/activities
     */
    public function store(Request $request, Post $post)
    {
        $this->authorize('manage', [Activity::class, $post->project]);

        $validated = $request->validate([
            'name' => 'required|string|max:100',
            'location' => 'required|string|max:100',
            'active' => 'boolean',

            'assignment_times' => 'required|array|min:1',
            'assignment_times.*.assignment_id' => 'required|exists:assignments,id',
            'assignment_times.*.start_time' => 'required|date_format:H:i',
            'assignment_times.*.end_time' => 'required|date_format:H:i',
        ]);

        $activity = DB::transaction(function () use ($validated, $post) {
            $activity = $post->activities()->create([
                'name' => $validated['name'],
                'location' => $validated['location'],
                'active' => $validated['active'] ?? true,
            ]);

            foreach ($validated['assignment_times'] as $time) {
                $activity->assignmentTimes()->create($time);
            }

            return $activity;
        });

        return response()->json([
            'message' => 'Activity created successfully',
            'data' => $activity->load('assignmentTimes'),
        ], 201);
    }

    /**
     * UPDATE ACTIVITY
     * PUT /activities/{activity}
     */
    public function update(Request $request, Activity $activity)
    {
        $this->authorize('manage', [Activity::class, $activity->post->project]);

        $validated = $request->validate([
            'name' => 'sometimes|string|max:100',
            'location' => 'sometimes|string|max:100',
            'active' => 'sometimes|boolean',
        ]);

        $activity->update($validated);

        return response()->json([
            'message' => 'Activity updated',
            'data' => $activity,
        ]);
    }

    /**
     * DELETE ACTIVITY
     * DELETE /activities/{activity}
     */
    public function destroy(Activity $activity)
    {
        $this->authorize('manage', [Activity::class, $activity->post->project]);

        $activity->delete();

        return response()->json([
            'message' => 'Activity deleted',
        ]);
    }
}
