<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Models\assignment;
use Illuminate\Http\Request;

class assignmentController extends Controller
{
    /**
     * LIST assignment PER PROJECT
     * GET /projects/{project}/assignments
     */
    public function index(Project $project)
    {
        $this->authorize('viewAny', [assignment::class, $project]);

        $assignments = $project->assignments()
            ->select(
                'id',
                'project_id',
                'name',
                'code',
                'start_time',
                'end_time',
                'grace_period'
            )
            ->orderBy('start_time')
            ->get();

        return response()->json([
            'data' => $assignments,
        ]);
    }

    /**
     * CREATE assignment
     * POST /projects/{project}/assignments
     */
    public function store(Request $request, Project $project)
    {
        $this->authorize('manage', [assignment::class, $project]);

        $validated = $request->validate([
            'name'         => 'required|string|max:100',
            'code'         => 'required|string|max:50',
            'start_time'   => 'required|date_format:H:i',
            'end_time'     => 'required|date_format:H:i',
            'grace_period' => 'nullable|integer|min:0',
        ]);

        $assignment = $project->assignments()->create($validated);

        return response()->json([
            'message' => 'assignment created successfully',
            'data'    => $assignment,
        ], 201);
    }

    /**
     * DETAIL assignment
     * GET /assignments/{assignment}
     */
    public function show(assignment $assignment)
    {
        $this->authorize('view', $assignment);

        return response()->json([
            'data' => $assignment,
        ]);
    }

    /**
     * UPDATE assignment
     * PUT /assignments/{assignment}
     */
    public function update(Request $request, assignment $assignment)
    {
        $this->authorize('manage', [assignment::class, $assignment->project]);

        $validated = $request->validate([
            'name'         => 'sometimes|string|max:100',
            'code'         => 'sometimes|string|max:50',
            'start_time'   => 'sometimes|date_format:H:i',
            'end_time'     => 'sometimes|date_format:H:i',
            'grace_period' => 'sometimes|integer|min:0',
        ]);

        $assignment->update($validated);

        return response()->json([
            'message' => 'assignment updated successfully',
            'data'    => $assignment,
        ]);
    }

    /**
     * DELETE assignment
     * DELETE /assignments/{assignment}
     */
    public function destroy(assignment $assignment)
    {
        $this->authorize('manage', [assignment::class, $assignment->project]);

        $assignment->delete();

        return response()->json([
            'message' => 'assignment deleted successfully',
        ]);
    }
}
