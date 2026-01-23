<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Post;
use App\Models\Project;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PostController extends Controller
{
    /**
     * LIST POST PER PROJECT
     * GET /projects/{project}/posts
     */
    public function index(Project $project)
    {
        $this->authorize('viewAny', [Post::class, $project]);

        $posts = $project->posts()
            ->with('patrolPoints')
            ->select('id', 'project_id', 'name', 'type')
            ->orderBy('name')
            ->get();

        return response()->json([
            'data' => $posts,
        ]);
    }

    /**
     * CREATE POST + PATROL POINT
     * POST /projects/{project}/posts
     */
    public function store(Request $request, Project $project)
    {
        $this->authorize('manage', [Post::class, $project]);

        $validated = $request->validate([
            'name' => 'required|string|max:100',
            'type' => 'required|in:static,mobile',

            'patrol_points' => 'required|array|min:1',
            'patrol_points.*.name' => 'required|string|max:100',
            'patrol_points.*.sequence_order' => 'required|integer|min:1',
            'patrol_points.*.latitude' => 'required|numeric',
            'patrol_points.*.longitude' => 'required|numeric',
            'patrol_points.*.radius' => 'nullable|integer|min:1',
        ]);

        DB::transaction(function () use ($validated, $project, &$post) {
            $post = $project->posts()->create([
                'name' => $validated['name'],
                'type' => $validated['type'],
            ]);

            foreach ($validated['patrol_points'] as $point) {
                $post->patrolPoints()->create($point);
            }
        });

        return response()->json([
            'message' => 'Post created successfully',
            'data' => $post->load('patrolPoints'),
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

        $validated = $request->validate([
            'name' => 'sometimes|string|max:100',
            'type' => 'sometimes|in:static,mobile',
        ]);

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
}
