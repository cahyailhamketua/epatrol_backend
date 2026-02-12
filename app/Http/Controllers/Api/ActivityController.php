<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Activity;
use App\Models\Post;
use App\Models\Assignment;
use App\Models\Organization;
use App\Models\Project;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ActivityController extends Controller
{
    /**
     * LIST SEMUA ACTIVITY (DIKELOMPOKKAN BY POST → ASSIGNMENT)
     * GET /activities/schedule
     *
     * Contoh struktur response:
     * [
     *   {
     *     "post_id": 1,
     *     "post_name": "Main Gate Security",
     *     "project_id": 1,
     *     "type": "static",
     *     "assignments": [
     *       {
     *         "assignment_id": 10,
     *         "assignment_name": "Morning Shift",
     *         "items": [
     *           {
     *             "activity_id": 5,
     *             "activity_name": "Shift start",
     *             "location": "Main Gate",
     *             "start_time": "06:00",
     *             "end_time": "06:30"
     *           },
     *           ...
     *         ]
     *       },
     *       ...
     *     ]
     *   }
     * ]
     */
    public function schedule(Request $request)
    {
        $this->authorize('viewAny', Activity::class);

        $user = $request->user();

        $query = Post::with([
                'activities.assignmentTimes.assignment',
            ])
            ->select('id', 'project_id', 'name', 'type');

        // 🔒 Role terbatas → hanya project dia
        if (in_array($user->role, ['anggota', 'komandan_regu', 'admin_project'])) {
            $query->where('project_id', $user->project_id);
        }

        // 🔒 HO → semua project dalam organization dia
        if ($user->role === 'ho') {
            $query->whereHas('project', function ($q) use ($user) {
                $q->where('organization_id', $user->organization_id);
            });
        }

        $posts = $query
            ->orderBy('name')
            ->get();

        $data = $this->formatScheduleData($posts);

        return response()->json([
            'data' => $data,
        ]);
    }

    /**
     * LIST ACTIVITY SCHEDULE BY ORGANIZATION
     * GET /organizations/{organization}/activities/schedule
     * Data format sama seperti /activities/schedule
     */
    public function scheduleByOrganization(Organization $organization)
    {
        $this->authorize('viewAny', Activity::class);

        $query = Post::with([
                'activities.assignmentTimes.assignment',
            ])
            ->select('id', 'project_id', 'name', 'type')
            ->whereHas('project', function ($q) use ($organization) {
                $q->where('organization_id', $organization->id);
            });

        $posts = $query
            ->orderBy('name')
            ->get();

        $data = $this->formatScheduleData($posts);

        return response()->json([
            'data' => $data,
        ]);
    }

    /**
     * LIST ACTIVITY SCHEDULE BY PROJECT
     * GET /projects/{project}/activities/schedule
     * Data format sama seperti /activities/schedule
     */
    public function scheduleByProject(Project $project)
    {
        $this->authorize('viewAny', Activity::class);

        $posts = Post::with([
                'activities.assignmentTimes.assignment',
            ])
            ->select('id', 'project_id', 'name', 'type')
            ->where('project_id', $project->id)
            ->orderBy('name')
            ->get();

        $data = $this->formatScheduleData($posts);

        return response()->json([
            'data' => $data,
        ]);
    }

    /**
     * LIST ACTIVITY SCHEDULE BY POST
     * GET /posts/{post}/activities/schedule
     * Data format sama seperti /activities/schedule
     */
    public function scheduleByPost(Post $post)
    {
        $this->authorize('viewAny', Activity::class);

        $posts = Post::with([
                'activities.assignmentTimes.assignment',
            ])
            ->select('id', 'project_id', 'name', 'type')
            ->where('id', $post->id)
            ->orderBy('name')
            ->get();

        $data = $this->formatScheduleData($posts);

        return response()->json([
            'data' => $data,
        ]);
    }

    /**
     * Helper method untuk format schedule data
     * Digunakan oleh schedule(), scheduleByOrganization(), scheduleByProject(), scheduleByPost()
     */
    private function formatScheduleData($posts)
    {
        return $posts->map(function (Post $post) {
            $assignmentGroups = [];

            foreach ($post->activities as $activity) {
                if (!$activity->active) {
                    continue;
                }

                foreach ($activity->assignmentTimes as $time) {
                    $assignment = $time->assignment;
                    if (!$assignment) {
                        continue;
                    }

                    $assignmentId = $assignment->id;

                    if (!isset($assignmentGroups[$assignmentId])) {
                        $assignmentGroups[$assignmentId] = [
                            'assignment_id'   => $assignment->id,
                            'assignment_name' => $assignment->name,
                            'items'           => [],
                        ];
                    }

                    $assignmentGroups[$assignmentId]['items'][] = [
                        'activity_id'   => $activity->id,
                        'activity_name' => $activity->name,
                        'location'      => $activity->location,
                        'start_time'    => $time->start_time,
                        'end_time'      => $time->end_time,
                    ];
                }
            }

            // Urutkan items per assignment berdasarkan start_time
            foreach ($assignmentGroups as &$group) {
                usort($group['items'], function ($a, $b) {
                    return strcmp($a['start_time'], $b['start_time']);
                });
            }

            return [
                'post_id'    => $post->id,
                'post_name'  => $post->name,
                'project_id' => $post->project_id,
                'type'       => $post->type,
                'assignments'=> array_values($assignmentGroups),
            ];
        });
    }

    /**
     * LIST ACTIVITY BY POST + assignment
     * GET /posts/{post}/activities?assignment_id=
     */
    public function index(Request $request, Post $post)
    {
        $this->authorize('viewAny', Activity::class);

        try {
            $request->validate([
                'assignment_id' => 'nullable|exists:assignments,id',
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'success'     => false,
                'message'     => 'Validation failed',
                'status_code' => 422,
                'errors'      => $e->errors(),
            ], 422);
        }

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

        // Mode BULK (request berisi "activities": [...])
        if ($request->has('activities')) {
            try {
                $validated = $request->validate([
                    'activities' => 'required|array|min:1',
                    'activities.*.name' => 'required|string|max:100',
                    'activities.*.location' => 'required|string|max:100',
                    'activities.*.active' => 'boolean',

                    'activities.*.assignment_times' => 'required|array|min:1',
                    'activities.*.assignment_times.*.assignment_id' => 'required|exists:assignments,id',
                    'activities.*.assignment_times.*.start_time' => 'required|date_format:H:i',
                    'activities.*.assignment_times.*.end_time' => 'required|date_format:H:i',
                ]);
            } catch (ValidationException $e) {
                return response()->json([
                    'success'     => false,
                    'message'     => 'Validation failed',
                    'status_code' => 422,
                    'errors'      => $e->errors(),
                ], 422);
            }

            $activities = DB::transaction(function () use ($validated, $post) {
                $created = [];

                foreach ($validated['activities'] as $activityData) {
                    $activity = $post->activities()->create([
                        'name'     => $activityData['name'],
                        'location' => $activityData['location'],
                        'active'   => $activityData['active'] ?? true,
                    ]);

                    foreach ($activityData['assignment_times'] as $timeData) {
                        // Pastikan assignment berada pada project yang sama dengan post
                        $assignment = Assignment::find($timeData['assignment_id']);
                        if (!$assignment || $assignment->project_id !== $post->project_id) {
                            throw ValidationException::withMessages([
                                'activities' => ['Assignment tidak berada pada project yang sama dengan post.'],
                            ]);
                        }

                        $activity->assignmentTimes()->create($timeData);
                    }

                    $created[] = $activity->load('assignmentTimes');
                }

                return $created;
            });

            return response()->json([
                'message' => 'Activities created successfully',
                'data'    => $activities,
            ], 201);
        }

        // Mode SINGLE (kompatibel dengan struktur lama)
        try {
            $validated = $request->validate([
                'name' => 'required|string|max:100',
                'location' => 'required|string|max:100',
                'active' => 'boolean',

                'assignment_times' => 'required|array|min:1',
                'assignment_times.*.assignment_id' => 'required|exists:assignments,id',
                'assignment_times.*.start_time' => 'required|date_format:H:i',
                'assignment_times.*.end_time' => 'required|date_format:H:i',
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'success'     => false,
                'message'     => 'Validation failed',
                'status_code' => 422,
                'errors'      => $e->errors(),
            ], 422);
        }

        $activity = DB::transaction(function () use ($validated, $post) {
            $activity = $post->activities()->create([
                'name' => $validated['name'],
                'location' => $validated['location'],
                'active' => $validated['active'] ?? true,
            ]);

            foreach ($validated['assignment_times'] as $time) {
                // Pastikan assignment berada pada project yang sama dengan post
                $assignment = Assignment::find($time['assignment_id']);
                if (!$assignment || $assignment->project_id !== $post->project_id) {
                    throw ValidationException::withMessages([
                        'assignment_times' => ['Assignment tidak berada pada project yang sama dengan post.'],
                    ]);
                }

                $activity->assignmentTimes()->create($time);
            }

            return $activity;
        });

        return response()->json([
            'message' => 'Activity created successfully',
            'data'    => $activity->load('assignmentTimes'),
        ], 201);
    }

    /**
     * UPDATE ACTIVITY
     * PUT /activities/{activity}
     */
    public function update(Request $request, Activity $activity)
    {
        $this->authorize('manage', [Activity::class, $activity->post->project]);

        try {
            $validated = $request->validate([
                'name' => 'sometimes|string|max:100',
                'location' => 'sometimes|string|max:100',
                'active' => 'sometimes|boolean',
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'success'     => false,
                'message'     => 'Validation failed',
                'status_code' => 422,
                'errors'      => $e->errors(),
            ], 422);
        }

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
