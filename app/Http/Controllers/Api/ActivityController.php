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
     * LIST ACTIVITY BY POST + SHIFT
     * GET /posts/{post}/activities?shift_id=
     */
    public function index(Request $request, Post $post)
    {
        $this->authorize('viewAny', Activity::class);

        $request->validate([
            'shift_id' => 'nullable|exists:shifts,id',
        ]);

        $activities = Activity::where('post_id', $post->id)
            ->where('active', true)
            ->whereHas('shiftTimes', function ($q) use ($request) {
                $q->where('shift_id', $request->shift_id);
            })
            ->with(['shiftTimes' => function ($q) use ($request) {
                $q->where('shift_id', $request->shift_id);
            }])
            ->orderBy('name')
            ->get();

        return response()->json([
            'data' => $activities,
        ]);
    }

    /**
     * CREATE ACTIVITY + SHIFT TIMES
     * POST /posts/{post}/activities
     */
    public function store(Request $request, Post $post)
    {
        $this->authorize('manage', [Activity::class, $post->project]);

        $validated = $request->validate([
            'name' => 'required|string|max:100',
            'location' => 'required|string|max:100',
            'active' => 'boolean',

            'shift_times' => 'required|array|min:1',
            'shift_times.*.shift_id' => 'required|exists:shifts,id',
            'shift_times.*.start_time' => 'required|date_format:H:i',
            'shift_times.*.end_time' => 'required|date_format:H:i',
        ]);

        $activity = DB::transaction(function () use ($validated, $post) {
            $activity = $post->activities()->create([
                'name' => $validated['name'],
                'location' => $validated['location'],
                'active' => $validated['active'] ?? true,
            ]);

            foreach ($validated['shift_times'] as $time) {
                $activity->shiftTimes()->create($time);
            }

            return $activity;
        });

        return response()->json([
            'message' => 'Activity created successfully',
            'data' => $activity->load('shiftTimes'),
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
