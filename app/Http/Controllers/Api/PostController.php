<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Post;
use App\Models\Project;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use SimpleSoftwareIO\QrCode\Facades\QrCode;

class PostController extends Controller
{   
    public function index(Request $request)
    {
        $this->authorize('viewAny', Post::class);

        $user = $request->user();

        $query = Post::with(['patrolPoints.qrCode'])
            ->select('id', 'project_id', 'name', 'type')
            ->orderBy('name');

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

        /**
         * OPTIONAL FILTER BY TYPE
         */
        if ($request->filled('type')) {
            $request->validate([
                'type' => 'in:static,mobile'
            ]);

            $query->where('type', $request->type);
        }

        $posts = $query
            ->orderBy('name')
            ->paginate($request->get('per_page', 15));

        $posts->getCollection()->transform(function ($post) {
            $post->patrolPoints->transform(function ($patrolPoint) {
                if ($patrolPoint->qrCode) {
                    $qrCode = QrCode::format('svg')->size(200)->generate($patrolPoint->qrCode->code);
                    $patrolPoint->qr_code_image = 'data:image/svg+xml;base64,' . base64_encode($qrCode);
                } else {
                    $patrolPoint->qr_code_image = null; // Or a default image
                }
                return $patrolPoint;
            });
            return $post;
        });

        return response()->json($posts);
    }

    /**
     * LIST POST PER PROJECT
     * GET /projects/{project}/posts
     */
    public function indexByProject(Project $project)
    {
        $this->authorize('viewAnyByProject', [Post::class, $project]);

        $posts = $project->posts()
            ->with(['patrolPoints.qrCode'])
            ->select('id', 'project_id', 'name', 'type')
            ->orderBy('name')
            ->get();

        return response()->json([
            'data' => $posts->transform(function ($post) {
                $post->patrolPoints->transform(function ($patrolPoint) {
                    if ($patrolPoint->qrCode) {
                        $qrCode = QrCode::format('svg')->size(200)->generate($patrolPoint->qrCode->code);
                        $patrolPoint->qr_code_image = 'data:image/svg+xml;base64,' . base64_encode($qrCode);
                    } else {
                        $patrolPoint->qr_code_image = null; // Or a default image
                    }
                    return $patrolPoint;
                });
                return $post;
            }),
        ]);
    }

    /**
     * CREATE POST + PATROL POINT
     * POST /projects/{project}/posts
     */
    public function store(Request $request, Project $project)
    {
        $this->authorize('manage', [Post::class, $project]);

        try {
            $validated = $request->validate([
                'name' => 'required|string|max:100',
                'type' => 'required|in:static,mobile',

                'patrol_points' => 'required|array|min:1',
                'patrol_points.*.name' => 'required|string|max:100',
                'patrol_points.*.sequence_order' => 'required|integer|min:1',
                'patrol_points.*.latitude' => 'required|numeric',
                'patrol_points.*.longitude' => 'required|numeric',
                'patrol_points.*.altitude' => 'nullable|numeric',
                'patrol_points.*.radius' => 'nullable|integer|min:1',
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'success'     => false,
                'message'     => 'Validation failed',
                'status_code' => 422,
                'errors'      => $e->errors(),
            ], 422);
        }

        $post = DB::transaction(function () use ($validated, $project) {

            $post = $project->posts()->create([
                'name' => $validated['name'],
                'type' => $validated['type'],
            ]);

            foreach ($validated['patrol_points'] as $point) {
                $post->patrolPoints()->create($point);
                // QR Code otomatis dibuat via model event
            }

            return $post;
        });

        return response()->json([
            'message' => 'Post created successfully',
            'data' => $post->load([
                'patrolPoints.qrCode'
            ]),
        ], 201);
    }


    /**
     * DETAIL POST
     * GET /posts/{post}
     */
    public function show(Post $post)
    {
        $this->authorize('view', $post);

        return response()->json([
            'data' => $post->load('patrolPoints'),
        ]);
    }

    /**
     * UPDATE POST
     * PUT /posts/{post}
     */
    public function update(Request $request, Post $post)
    {
        $this->authorize('manage', [Post::class, $post->project]);

        try {
            $validated = $request->validate([
                'name' => 'sometimes|string|max:100',
                'type' => 'sometimes|in:static,mobile',
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'success'     => false,
                'message'     => 'Validation failed',
                'status_code' => 422,
                'errors'      => $e->errors(),
            ], 422);
        }

        $post->update($validated);

        return response()->json([
            'message' => 'Post updated successfully',
            'data' => $post,
        ]);
    }

    /**
     * DELETE POST
     * DELETE /posts/{post}
     */
    public function destroy(Post $post)
    {
        $this->authorize('manage', [Post::class, $post->project]);

        $post->delete();

        return response()->json([
            'message' => 'Post deleted successfully',
        ]);
    }

    /**
     * GET POST TYPES
     * GET /posts/types
     */
    public function types()
    {
        $this->authorize('viewAny', Post::class);

        $types = DB::table('posts')
            ->select('type')
            ->distinct()
            ->orderBy('type')
            ->pluck('type');

        return response()->json([
            'data' => $types
        ]);
    }

    /**
     * GET POSTS BY TYPE
     * GET /posts/by-type/{type}
     */
    public function byType(Request $request, string $type)
    {
        $this->authorize('viewAny', Post::class);

        $user = $request->user();

        $query = Post::with('patrolPoints')
            ->select('id', 'project_id', 'name', 'type')
            ->where('type', $type)
            ->orderBy('name');

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

        $posts = $query->paginate($request->get('per_page', 15));

        return response()->json($posts);
    }

}
