<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Activity;
use App\Models\Assignment;
use App\Models\Organization;
use App\Models\Post;
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
                if (! $activity->active) {
                    continue;
                }

                foreach ($activity->assignmentTimes as $time) {
                    $assignment = $time->assignment;
                    if (! $assignment) {
                        continue;
                    }

                    $assignmentId = $assignment->id;

                    if (! isset($assignmentGroups[$assignmentId])) {
                        $assignmentGroups[$assignmentId] = [
                            'assignment_id' => $assignment->id,
                            'assignment_name' => $assignment->name,
                            'items' => [],
                        ];
                    }

                    $assignmentGroups[$assignmentId]['items'][] = [
                        'activity_assignment_time_id' => $time->id,
                        'activity_id' => $activity->id,
                        'activity_name' => $activity->name,
                        'location' => $activity->location,
                        'start_time' => $time->start_time,
                        'end_time' => $time->end_time,
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
                'post_id' => $post->id,
                'post_name' => $post->name,
                'project_id' => $post->project_id,
                'type' => $post->type,
                'assignments' => array_values($assignmentGroups),
            ];
        });
    }

    // ini buat aktivitas ishoma

    /**
     * LIST ACTIVITY BY PROJECT OR POST + assignment
     * GET /activities?project_id=1&assignment_id=1 or GET /activities?post_id=1&assignment_id=1
     */
    public function index(Request $request)
    {
        $this->authorize('viewAny', Activity::class);

        try {
            $request->validate([
                'project_id' => 'nullable|exists:projects,id',
                'post_id' => 'nullable|exists:posts,id',
                'assignment_id' => 'nullable|exists:assignments,id',
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'status_code' => 422,
                'errors' => $e->errors(),
            ], 422);
        }

        // Ensure either project_id or post_id is provided
        if (! $request->project_id && ! $request->post_id) {
            return response()->json([
                'success' => false,
                'message' => 'Either project_id or post_id must be provided',
                'status_code' => 422,
            ], 422);
        }

        $query = Activity::where('active', true);

        if ($request->project_id) {
            // For project activities: post_id must be null
            $query->where('project_id', $request->project_id)
                ->whereNull('post_id');
        }

        if ($request->post_id) {
            // For post activities: both project_id and post_id must exist
            $query->where('post_id', $request->post_id)
                ->whereNotNull('project_id');
        }

        if ($request->assignment_id) {
            $query->whereHas('assignmentTimes', function ($q) use ($request) {
                $q->where('assignment_id', $request->assignment_id);
            })->with(['assignmentTimes' => function ($q) use ($request) {
                $q->where('assignment_id', $request->assignment_id);
            }]);
        } else {
            $query->with('assignmentTimes');
        }

        $activities = $query->orderBy('name')->get();

        return response()->json([
            'data' => $activities,
        ]);
    }

    public function getAll()// get baru ada project nya juga, tapi optional, kalau tidak dikirim berarti ambil semua activity aktif tanpa filter
    {
        // ================= QUERY =================
        $activities = Activity::where('active', true)
            ->with(['project', 'post', 'assignmentTimes.assignment'])
            ->orderBy('name')
            ->paginate(10); // tetap pakai pagination biar aman

        // ================= FORMAT RESPONSE =================
        $formatted = $activities->items()->map(function ($activity) {
            // Safely get assignmentTimes - ensure it's a collection
            $assignmentTimesData = $activity->assignmentTimes ?? [];

            // If it's already an array, keep it; if Collection, convert to array then back to collection
            if (is_array($assignmentTimesData)) {
                $assignmentTimes = collect($assignmentTimesData);
            } else {
                $assignmentTimes = collect($assignmentTimesData);
            }

            // Map assignment times with safety checks
            $mappedTimes = [];
            if ($assignmentTimes && $assignmentTimes->count() > 0) {
                $mappedTimes = $assignmentTimes->map(function ($time) {
                    return [
                        'id' => $time->id ?? $time['id'] ?? null,
                        'assignment_id' => $time->assignment_id ?? $time['assignment_id'] ?? null,
                        'assignment_name' => isset($time->assignment) ? $time->assignment->name : (isset($time['assignment']) ? $time['assignment']['name'] : 'Unknown'),
                        'start_time' => $time->start_time ?? $time['start_time'] ?? null,
                        'end_time' => $time->end_time ?? $time['end_time'] ?? null,
                    ];
                })->toArray();
            }

            return [
                'id' => $activity->id,
                'activity_name' => $activity->name,
                'location' => $activity->location,
                'active' => (bool) $activity->active,
                'project' => [
                    'id' => $activity->project->id ?? null,
                    'name' => $activity->project->name ?? 'Unknown',
                ],
                'post' => [
                    'id' => $activity->post->id ?? null,
                    'name' => $activity->post->name ?? 'No Post',
                    'type' => $activity->post->type ?? null,
                ],
                'assignment_times' => $mappedTimes,
            ];
        })->toArray();

        // ================= RETURN RESPONSE =================
        return response()->json([
            'message' => 'All activities fetched successfully',
            'data' => $formatted,
            'meta' => [
                'current_page' => $activities->currentPage(),
                'last_page' => $activities->lastPage(),
                'per_page' => $activities->perPage(),
                'total' => $activities->total(),
            ],
        ]);
    }

    /**
     * INDEXACTIVITY - Khusus untuk role 'dev' melihat semua activity yang sudah dibuat
     * GET /activities/indexactivity
     *
     * Hanya bisa diakses oleh role 'dev'
     * Menampilkan semua activity tanpa filter, termasuk yang tidak aktif
     */
    public function indexactivity(Request $request)
    {
        // ================= AUTH =================
        $user = $request->user();

        if ($user->role !== 'dev') {
            return response()->json([
                'message' => 'Access denied. Only dev role can access this endpoint.',
            ], 403);
        }

        // ================= QUERY =================
        $activities = Activity::with(['project', 'post', 'assignmentTimes.assignment'])
            ->orderBy('created_at', 'desc')
            ->get();

        // ================= SPLIT DATA =================
        $withPost = $activities->whereNotNull('post_id');
        $withoutPost = $activities->whereNull('post_id');

        // ================= GROUP WITH POST =================
        $groupedWithPost = $withPost
            ->groupBy('project_id')
            ->map(function ($projectGroup) {

                $project = $projectGroup->first()->project;

                return [
                    'project_id' => $project?->id,
                    'project_name' => $project?->name ?? 'Unknown Project',

                    'posts' => $projectGroup
                        ->groupBy('post_id')
                        ->map(function ($postGroup) {

                            $post = $postGroup->first()->post;

                            return [
                                'post_id' => $post?->id,
                                'post_name' => $post?->name ?? 'No Post',

                                'activities' => $postGroup->map(function ($activity) {

                                    return [
                                        'id' => $activity->id,
                                        'name' => $activity->name,
                                        'location' => $activity->location,
                                        'active' => (bool) $activity->active,

                                        'assignment_times' => collect($activity->assignmentTimes)
                                            ->map(function ($time) {
                                                return [
                                                    'id' => $time->id,
                                                    'assignment_id' => $time->assignment_id,
                                                    'assignment_name' => $time->assignment?->name ?? 'Unknown',
                                                    'start_time' => $time->start_time,
                                                    'end_time' => $time->end_time,
                                                ];
                                            })->values(),
                                    ];
                                })->values(),
                            ];
                        })->values(),
                ];
            })->values();

        // ================= GROUP WITHOUT POST =================
        $groupedWithoutPost = $withoutPost
            ->groupBy('project_id')
            ->map(function ($projectGroup) {

                $project = $projectGroup->first()->project;

                return [
                    'project_id' => $project?->id,
                    'project_name' => $project?->name ?? 'Unknown Project',

                    'activities' => $projectGroup->map(function ($activity) {

                        return [
                            'id' => $activity->id,
                            'name' => $activity->name,
                            'location' => $activity->location,
                            'active' => (bool) $activity->active,

                            'assignment_times' => collect($activity->assignmentTimes)
                                ->map(function ($time) {
                                    return [
                                        'id' => $time->id,
                                        'assignment_id' => $time->assignment_id,
                                        'assignment_name' => $time->assignment?->name ?? 'Unknown',
                                        'start_time' => $time->start_time,
                                        'end_time' => $time->end_time,
                                    ];
                                })->values(),
                        ];
                    })->values(),
                ];
            })->values();

        // ================= RESPONSE =================
        return response()->json([
            'message' => 'Activities grouped successfully',
            'data' => [
                'with_post' => $groupedWithPost,
                'without_post' => $groupedWithoutPost,
            ],
        ]);
    }

    /**
     * CREATE ACTIVITY + assignment TIMES
     * POST /posts/{post}/activities
     */

    /**
     * STORE - Create activities dengan hanya project_id
     * POST /activities
     *
     * Request body:
     * {
     *   "project_id": 1,
     *   "post_id": 1,  //optional
     *   "activities": [
     *     {
     *       "name": "Morning Check",
     *       "location": "Main Gate",
     *       "active": true,
     *       "assignment_times": [
     *         {
     *           "assignment_id": 1,
     *           "start_time": "06:00",
     *           "end_time": "06:30"
     *         }
     *       ]
     *     }
     *   ]
     * }
     */
    public function store(Request $request)
    {
        // ================= VALIDATION =================
        try {
            $validated = $request->validate([
                'project_id' => 'required|exists:projects,id',
                'post_id' => 'nullable|exists:posts,id',

                'activities' => 'required|array|min:1',
                'activities.*.name' => 'required|string|max:100',
                'activities.*.location' => 'required|string|max:100',
                'activities.*.active' => 'sometimes|boolean',

                'activities.*.assignment_times' => 'required|array|min:1',
                'activities.*.assignment_times.*.assignment_id' => 'required|exists:assignments,id',
                'activities.*.assignment_times.*.start_time' => 'required|date_format:H:i',
                'activities.*.assignment_times.*.end_time' => 'required|date_format:H:i|after:start_time',
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $e->errors(),
            ], 422);
        }

        $projectId = $validated['project_id'];
        $postId = $validated['post_id'] ?? null;

        // =============== VALIDASI POST JIKA ADA =================
        if ($postId) {
            $post = Post::findOrFail($postId);
            if ($post->project_id !== $projectId) {
                return response()->json([
                    'message' => 'Post tidak sesuai dengan project_id',
                ], 422);
            }
        }

        // ================= TRANSACTION =================
        try {
            $activities = DB::transaction(function () use ($validated, $projectId, $postId) {
                $created = [];

                foreach ($validated['activities'] as $activityData) {
                    // CREATE ACTIVITY
                    $activity = Activity::create([
                        'project_id' => $projectId,
                        'post_id' => $postId,
                        'name' => $activityData['name'],
                        'location' => $activityData['location'],
                        'active' => $activityData['active'] ?? true,
                    ]);

                    // CREATE ASSIGNMENT TIMES
                    foreach ($activityData['assignment_times'] as $time) {
                        $assignment = Assignment::findOrFail($time['assignment_id']);

                        // 🔒 VALIDASI PROJECT
                        if ($assignment->project_id !== $projectId) {
                            throw ValidationException::withMessages([
                                'assignment_times' => ['Assignment tidak sesuai dengan project_id'],
                            ]);
                        }

                        $activity->assignmentTimes()->create([
                            'assignment_id' => $time['assignment_id'],
                            'start_time' => $time['start_time'],
                            'end_time' => $time['end_time'],
                        ]);
                    }

                    $created[] = $activity->load(['post', 'assignmentTimes.assignment']);
                }

                return $created;
            });

            return response()->json([
                'message' => 'Activities created successfully',
                'data' => $activities,
            ], 201);

        } catch (ValidationException $e) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $e->errors(),
            ], 422);
        }
    }
    // public function store(Request $request, Post $post)
    // {
    //     $this->authorize('manage', [Activity::class, $post->project]);

    //     // Mode BULK (request berisi "activities": [...])
    //     if ($request->has('activities')) {
    //         try {
    //             $validated = $request->validate([
    //                 'activities' => 'required|array|min:1',
    //                 'activities.*.name' => 'required|string|max:100',
    //                 'activities.*.location' => 'required|string|max:100',
    //                 'activities.*.active' => 'boolean',

    //                 'activities.*.assignment_times' => 'required|array|min:1',
    //                 'activities.*.assignment_times.*.assignment_id' => 'required|exists:assignments,id',
    //                 'activities.*.assignment_times.*.start_time' => 'required|date_format:H:i',
    //                 'activities.*.assignment_times.*.end_time' => 'required|date_format:H:i',
    //             ]);
    //         } catch (ValidationException $e) {
    //             return response()->json([
    //                 'success'     => false,
    //                 'message'     => 'Validation failed',
    //                 'status_code' => 422,
    //                 'errors'      => $e->errors(),
    //             ], 422);
    //         }

    //         $activities = DB::transaction(function () use ($validated, $post) {
    //             $created = [];

    //             foreach ($validated['activities'] as $activityData) {
    //                 $activity = $post->activities()->create([
    //                     'name'     => $activityData['name'],
    //                     'location' => $activityData['location'],
    //                     'active'   => $activityData['active'] ?? true,
    //                 ]);

    //                 foreach ($activityData['assignment_times'] as $timeData) {
    //                     // Pastikan assignment berada pada project yang sama dengan post
    //                     $assignment = Assignment::find($timeData['assignment_id']);
    //                     if (!$assignment || $assignment->project_id !== $post->project_id) {
    //                         throw ValidationException::withMessages([
    //                             'activities' => ['Assignment tidak berada pada project yang sama dengan post.'],
    //                         ]);
    //                     }

    //                     $activity->assignmentTimes()->create($timeData);
    //                 }

    //                 $created[] = $activity->load('assignmentTimes');
    //             }

    //             return $created;
    //         });

    //         return response()->json([
    //             'message' => 'Activities created successfully',
    //             'data'    => $activities,
    //         ], 201);
    //     }

    //     // Mode SINGLE (kompatibel dengan struktur lama)
    //     try {
    //         $validated = $request->validate([
    //             'name' => 'required|string|max:100',
    //             'location' => 'required|string|max:100',
    //             'active' => 'boolean',

    //             'assignment_times' => 'required|array|min:1',
    //             'assignment_times.*.assignment_id' => 'required|exists:assignments,id',
    //             'assignment_times.*.start_time' => 'required|date_format:H:i',
    //             'assignment_times.*.end_time' => 'required|date_format:H:i',
    //         ]);
    //     } catch (ValidationException $e) {
    //         return response()->json([
    //             'success'     => false,
    //             'message'     => 'Validation failed',
    //             'status_code' => 422,
    //             'errors'      => $e->errors(),
    //         ], 422);
    //     }

    //     $activity = DB::transaction(function () use ($validated, $post) {
    //         $activity = $post->activities()->create([
    //             'name' => $validated['name'],
    //             'location' => $validated['location'],
    //             'active' => $validated['active'] ?? true,
    //         ]);

    //         foreach ($validated['assignment_times'] as $time) {
    //             // Pastikan assignment berada pada project yang sama dengan post
    //             $assignment = Assignment::find($time['assignment_id']);
    //             if (!$assignment || $assignment->project_id !== $post->project_id) {
    //                 throw ValidationException::withMessages([
    //                     'assignment_times' => ['Assignment tidak berada pada project yang sama dengan post.'],
    //                 ]);
    //             }

    //             $activity->assignmentTimes()->create($time);
    //         }

    //         return $activity;
    //     });

    //     return response()->json([
    //         'message' => 'Activity created successfully',
    //         'data'    => $activity->load('assignmentTimes'),
    //     ], 201);
    // }

    /**
     * BULK UPDATE ACTIVITIES DALAM SATU POST (STATE-BASED SYNC)
     * PUT /posts/{post}/activities
     *
     * Aturan:
     * - activity.id ada   → UPDATE
     * - activity.id null  → CREATE
     * - activity lama yang tidak dikirim → DELETE
     *
     * Untuk assignment_times:
     * - id ada    → UPDATE
     * - id null   → CREATE
     * - time lama yang tidak dikirim → DELETE2
     *
     * Seluruh proses dibungkus DB::transaction
     */

    /**
     * UPDATE - Update activities (state-based sync)
     * PUT /activities
     *
     * Aturan:
     * - activity.id ada   → UPDATE
     * - activity.id null  → CREATE (baru)
     * - activity lama yang tidak dikirim → DELETE
     *
     * Request body:
     * {
     *   "project_id": 1,
     *   "post_id": 1,  //optional
     *   "activities": [
     *     {
     *       "id": 1,  // null untuk create
     *       "name": "Morning Check",
     *       "location": "Main Gate",
     *       "active": true,
     *       "assignment_times": [
     *         {
     *           "id": 5,  // null untuk create
     *           "assignment_id": 1,
     *           "start_time": "06:00",
     *           "end_time": "06:30"
     *         }
     *       ]
     *     }
     *   ]
     * }
     */
    public function update(Request $request)
    {
        // ================= VALIDATION =================
        try {
            $validated = $request->validate([
                'project_id' => 'required|exists:projects,id',
                'post_id' => 'nullable|exists:posts,id',

                'activities' => 'required|array|min:1',
                'activities.*.id' => 'nullable|integer|exists:activities,id',

                'activities.*.name' => 'required|string|max:100',
                'activities.*.location' => 'required|string|max:100',
                'activities.*.active' => 'sometimes|boolean',

                'activities.*.assignment_times' => 'required|array|min:1',
                'activities.*.assignment_times.*.id' => 'nullable|integer|exists:activity_assignment_times,id',
                'activities.*.assignment_times.*.assignment_id' => 'required|exists:assignments,id',
                'activities.*.assignment_times.*.start_time' => 'required|date_format:H:i',
                'activities.*.assignment_times.*.end_time' => 'required|date_format:H:i|after:start_time',
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $e->errors(),
            ], 422);
        }

        $projectId = $validated['project_id'];
        $postId = $validated['post_id'] ?? null;

        // =============== VALIDASI POST JIKA ADA =================
        if ($postId) {
            $post = Post::findOrFail($postId);
            if ($post->project_id !== $projectId) {
                return response()->json([
                    'message' => 'Post tidak sesuai dengan project_id',
                ], 422);
            }
        }

        $activitiesPayload = collect($validated['activities']);

        // ================= TRANSACTION =================
        try {
            $updatedActivities = DB::transaction(function () use ($activitiesPayload, $projectId, $postId) {
                // 🔥 Ambil existing activities berdasarkan scope
                $query = Activity::where('project_id', $projectId);

                if ($postId) {
                    $query->where('post_id', $postId);
                }

                $existingActivities = $query
                    ->with('assignmentTimes')
                    ->get()
                    ->keyBy('id');

                // Ambil ID activity yang dikirim dari request (hanya yang punya id)
                $incomingActivityIds = $activitiesPayload
                    ->pluck('id')
                    ->filter()
                    ->values()
                    ->all();

                // DELETE activities yang tidak dikirim
                $activitiesToDelete = $existingActivities
                    ->whereNotIn('id', $incomingActivityIds);

                foreach ($activitiesToDelete as $activity) {
                    $activity->assignmentTimes()->delete();
                    $activity->delete();
                }

                $result = collect();

                // ================= LOOP CREATE / UPDATE =================
                foreach ($activitiesPayload as $activityData) {
                    $activityId = $activityData['id'] ?? null;

                    if ($activityId && $existingActivities->has($activityId)) {
                        // 🔁 UPDATE ACTIVITY
                        $activity = $existingActivities->get($activityId);

                        // Validasi bahwa activity sesuai scope
                        if ($activity->project_id !== $projectId || ($postId && $activity->post_id !== $postId)) {
                            throw ValidationException::withMessages([
                                'activities' => ['Activity tidak sesuai dengan scope'],
                            ]);
                        }

                        $activity->update([
                            'name' => $activityData['name'],
                            'location' => $activityData['location'],
                            'active' => $activityData['active'] ?? $activity->active,
                        ]);
                    } else {
                        // 🆕 CREATE ACTIVITY BARU
                        $activity = Activity::create([
                            'project_id' => $projectId,
                            'post_id' => $postId,
                            'name' => $activityData['name'],
                            'location' => $activityData['location'],
                            'active' => $activityData['active'] ?? true,
                        ]);
                    }

                    // ================= SYNC ASSIGNMENT TIMES =================
                    $existingTimes = $activity->assignmentTimes()
                        ->get()
                        ->keyBy('id');

                    $timesPayload = collect($activityData['assignment_times']);

                    $incomingTimeIds = $timesPayload
                        ->pluck('id')
                        ->filter()
                        ->values()
                        ->all();

                    // DELETE times yang tidak dikirim
                    $timesToDelete = $existingTimes->whereNotIn('id', $incomingTimeIds);
                    foreach ($timesToDelete as $time) {
                        $time->delete();
                    }

                    // CREATE / UPDATE times
                    foreach ($timesPayload as $timeData) {
                        $assignment = Assignment::findOrFail($timeData['assignment_id']);

                        // 🔒 VALIDASI PROJECT
                        if ($assignment->project_id !== $projectId) {
                            throw ValidationException::withMessages([
                                'assignment_times' => ['Assignment tidak sesuai dengan project_id'],
                            ]);
                        }

                        $timeId = $timeData['id'] ?? null;

                        if ($timeId && $existingTimes->has($timeId)) {
                            // UPDATE TIME
                            $time = $existingTimes->get($timeId);

                            if ($time->activity_id !== $activity->id) {
                                throw ValidationException::withMessages([
                                    'assignment_times' => ['Assignment time tidak sesuai dengan activity'],
                                ]);
                            }

                            $time->update([
                                'assignment_id' => $timeData['assignment_id'],
                                'start_time' => $timeData['start_time'],
                                'end_time' => $timeData['end_time'],
                            ]);
                        } else {
                            // CREATE TIME BARU
                            $activity->assignmentTimes()->create([
                                'assignment_id' => $timeData['assignment_id'],
                                'start_time' => $timeData['start_time'],
                                'end_time' => $timeData['end_time'],
                            ]);
                        }
                    }

                    $result->push($activity->load(['post', 'assignmentTimes.assignment']));
                }

                return $result->values();
            });

            return response()->json([
                'message' => 'Activities updated successfully',
                'data' => $updatedActivities,
            ], 200);

        } catch (ValidationException $e) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $e->errors(),
            ], 422);
        }
    }
    // public function update(Request $request, Post $post)
    // {
    //     // 🔒 Authorization
    //     $this->authorize('manage', [Activity::class, $post->project]);

    //     // ✅ Validasi input
    //     try {
    //         $validated = $request->validate([
    //             'activities' => 'required|array',
    //             'activities.*.id' => 'nullable|integer|exists:activities,id',

    //             'activities.*.name'     => 'required|string|max:100',
    //             'activities.*.location' => 'required|string|max:100',
    //             'activities.*.active'   => 'boolean',

    //             'activities.*.assignment_times' => 'required|array|min:1',
    //             'activities.*.assignment_times.*.id' => 'nullable|integer|exists:activity_assignment_times,id',
    //             'activities.*.assignment_times.*.assignment_id' => 'required|integer|exists:assignments,id',
    //             'activities.*.assignment_times.*.start_time'    => 'required|date_format:H:i',
    //             'activities.*.assignment_times.*.end_time'      => 'required|date_format:H:i|after:start_time',
    //         ]);
    //     } catch (ValidationException $e) {
    //         return response()->json([
    //             'success'     => false,
    //             'message'     => 'Validation failed',
    //             'status_code' => 422,
    //             'errors'      => $e->errors(),
    //         ], 422);
    //     }

    //     $activitiesPayload = collect($validated['activities']);

    //     // 💾 Semua operasi dalam satu transaksi
    //     $updatedActivities = DB::transaction(function () use ($activitiesPayload, $post) {
    //         // Ambil semua activity existing milik post ini
    //         $existingActivities = $post->activities()
    //             ->with('assignmentTimes')
    //             ->get()
    //             ->keyBy('id');

    //         // ID activity yang dikirim dari frontend (hanya yang punya id)
    //         $incomingActivityIds = $activitiesPayload
    //             ->pluck('id')
    //             ->filter()
    //             ->values()
    //             ->all();

    //         // Activity yang harus dihapus = ada di DB tapi tidak ada di payload
    //         $activitiesToDelete = $existingActivities
    //             ->whereNotIn('id', $incomingActivityIds);

    //         foreach ($activitiesToDelete as $activity) {
    //             // Hapus assignment_times dahulu (kalau tidak cascade)
    //             $activity->assignmentTimes()->delete();
    //             $activity->delete();
    //         }

    //         $result = collect();

    //         // Loop semua activity dari payload (create + update)
    //         foreach ($activitiesPayload as $activityData) {
    //             $activityId = $activityData['id'] ?? null;

    //             if ($activityId && $existingActivities->has($activityId)) {
    //                 // 🔁 UPDATE ACTIVITY
    //                 /** @var \App\Models\Activity $activity */
    //                 $activity = $existingActivities->get($activityId);

    //                 // Safety: pastikan activity benar‑benar milik post ini
    //                 if ($activity->post_id !== $post->id) {
    //                     throw ValidationException::withMessages([
    //                         'activities' => ['Activity tidak milik post ini.'],
    //                     ]);
    //                 }

    //                 $activity->update([
    //                     'name'     => $activityData['name'],
    //                     'location' => $activityData['location'],
    //                     'active'   => $activityData['active'] ?? $activity->active,
    //                 ]);
    //             } else {
    //                 // 🆕 CREATE ACTIVITY BARU
    //                 $activity = $post->activities()->create([
    //                     'name'     => $activityData['name'],
    //                     'location' => $activityData['location'],
    //                     'active'   => $activityData['active'] ?? true,
    //                 ]);
    //             }

    //             // ====== SYNC ASSIGNMENT_TIMES UNTUK ACTIVITY INI ======
    //             $existingTimes = $activity->assignmentTimes()
    //                 ->get()
    //                 ->keyBy('id');

    //             $timesPayload = collect($activityData['assignment_times']);

    //             $incomingTimeIds = $timesPayload
    //                 ->pluck('id')
    //                 ->filter()
    //                 ->values()
    //                 ->all();

    //             // DELETE: time yang ada di DB tapi tidak dikirim
    //             $timesToDelete = $existingTimes
    //                 ->whereNotIn('id', $incomingTimeIds);

    //             foreach ($timesToDelete as $time) {
    //                 $time->delete();
    //             }

    //             // CREATE / UPDATE: loop semua times yang dikirim
    //             foreach ($timesPayload as $timeData) {
    //                 $timeId = $timeData['id'] ?? null;

    //                 // Cek assignment belong ke project yang sama
    //                 $assignment = Assignment::find($timeData['assignment_id']);
    //                 if (!$assignment || $assignment->project_id !== $post->project_id) {
    //                     throw ValidationException::withMessages([
    //                         'activities' => ['Assignment tidak berada pada project yang sama dengan post.'],
    //                     ]);
    //                 }

    //                 if ($timeId && $existingTimes->has($timeId)) {
    //                     // 🔁 UPDATE assignment_time
    //                     $time = $existingTimes->get($timeId);

    //                     // Safety: pastikan time milik activity ini
    //                     if ($time->activity_id !== $activity->id) {
    //                         throw ValidationException::withMessages([
    //                             'activities' => ['Activity assignment time tidak milik activity ini.'],
    //                         ]);
    //                     }

    //                     $time->update([
    //                         'assignment_id' => $timeData['assignment_id'],
    //                         'start_time'    => $timeData['start_time'],
    //                         'end_time'      => $timeData['end_time'],
    //                     ]);
    //                 } else {
    //                     // 🆕 CREATE assignment_time BARU
    //                     $activity->assignmentTimes()->create([
    //                         'assignment_id' => $timeData['assignment_id'],
    //                         'start_time'    => $timeData['start_time'],
    //                         'end_time'      => $timeData['end_time'],
    //                     ]);
    //                 }
    //             }

    //             $result->push(
    //                 $activity->load('assignmentTimes')
    //             );
    //         }

    //         return $result->values();
    //     });

    //     return response()->json([
    //         'message' => 'Activities updated successfully',
    //         'data'    => $updatedActivities,
    //     ], 200);
    // }

    /**
     * UPDATE ACTIVITY TUNGGAL
     * PUT /activities/{activity}
     */
    public function updateActivity(Request $request, Activity $activity)
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
                'success' => false,
                'message' => 'Validation failed',
                'status_code' => 422,
                'errors' => $e->errors(),
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

    public function delete(Request $request)
    {
        // ================= VALIDATION =================
        try {
            $validated = $request->validate([
                'project_id' => 'nullable|exists:projects,id',
                'post_id' => 'nullable|exists:posts,id',
                'activity_ids' => 'required|array|min:1',
                'activity_ids.*' => 'integer|exists:activities,id',
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $e->errors(),
            ], 422);
        }

        // ================= VALIDASI RELASI =================
        if (empty($validated['project_id']) && empty($validated['post_id'])) {
            return response()->json([
                'message' => 'Harus pilih project_id atau post_id',
            ], 422);
        }

        // ================= AMBIL PROJECT =================
        if (! empty($validated['post_id'])) {
            $post = Post::findOrFail($validated['post_id']);
            $projectId = $post->project_id;
        } else {
            $projectId = $validated['project_id'];
            $post = null;
        }

        // ================= TRANSACTION =================
        DB::transaction(function () use ($validated, $projectId, $post) {

            $activities = Activity::whereIn('id', $validated['activity_ids'])
                ->where('project_id', $projectId)
                ->when($post, fn ($q) => $q->where('post_id', $post->id))
                ->get();

            foreach ($activities as $activity) {
                // 🔥 delete assignment_times dulu
                $activity->assignmentTimes()->delete();

                // 🔥 delete activity
                $activity->delete();
            }
        });

        return response()->json([
            'message' => 'Activities deleted successfully',
        ], 200);
    }
}
