<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Post;
use App\Models\PatrolPoint;
use Illuminate\Http\Request;

class PatrolPointController extends Controller
{
    /**
     * CREATE PATROL POINT
     * POST /posts/{post}/patrol-points
     */
    public function store(Request $request, Post $post)
    {
        $this->authorize('manage', [PatrolPoint::class, $post->project]);

        $validated = $request->validate([
            'name' => 'required|string|max:100',
            'sequence_order' => 'required|integer|min:1',
            'latitude' => 'required|numeric',
            'longitude' => 'required|numeric',
            'radius' => 'nullable|integer|min:1',
        ]);

        $point = $post->patrolPoints()->create($validated);

        return response()->json([
            'message' => 'Patrol point created',
            'data' => $point,
        ], 201);
    }

    /**
     * UPDATE PATROL POINT
     * PUT /patrol-points/{patrolPoint}
     */
    public function update(Request $request, PatrolPoint $patrolPoint)
    {
        $this->authorize(
            'manage',
            [PatrolPoint::class, $patrolPoint->post->project]
        );

        $validated = $request->validate([
            'name' => 'sometimes|string|max:100',
            'sequence_order' => 'sometimes|integer|min:1',
            'latitude' => 'sometimes|numeric',
            'longitude' => 'sometimes|numeric',
            'radius' => 'nullable|integer|min:1',
        ]);

        $patrolPoint->update($validated);

        return response()->json([
            'message' => 'Patrol point updated',
            'data' => $patrolPoint,
        ]);
    }

    /**
     * DELETE PATROL POINT
     * DELETE /patrol-points/{patrolPoint}
     */
    public function destroy(PatrolPoint $patrolPoint)
    {
        $this->authorize(
            'manage',
            [PatrolPoint::class, $patrolPoint->post->project]
        );

        $patrolPoint->delete();

        return response()->json([
            'message' => 'Patrol point deleted',
        ]);
    }
}
