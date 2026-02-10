<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Project;     
use App\Models\Assignment;
use App\Models\Organization;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class AssignmentController extends Controller
{
    /**
     * LIST assignment (global, filter by project)
     * GET /assignments?project_id={project}
     * Data mengikuti indexByProject()
     */
    public function index(Request $request)
    {
        $validated = $request->validate([
            'project_id' => 'required|exists:projects,id',
        ]);

        $project = Project::findOrFail($validated['project_id']);

        // Authorization via AssignmentPolicy@viewAny
        $this->authorize('viewAny', [Assignment::class, $project]);

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
     * LIST assignment PER ORGANIZATION
     * GET /organizations/{organization}/assignments
     * Data mengikuti indexByProject()
     */
    public function indexByOrganization(Organization $organization)
    {
        // Ambil project pertama dari organization untuk authorization
        $project = $organization->projects()->first();
        
        if (!$project) {
            return response()->json([
                'data' => [],
            ]);
        }

        // Authorization via AssignmentPolicy@viewAny
        $this->authorize('viewAny', [Assignment::class, $project]);

        $assignments = Assignment::whereHas('project', function ($q) use ($organization) {
                $q->where('organization_id', $organization->id);
            })
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
     * LIST assignment PER PROJECT
     * GET /projects/{project}/assignments
     */
    public function indexByProject(Project $project)
    {
        $this->authorize('viewAny', $project);

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
        $this->authorize('manage', [Assignment::class, $project]);

        try {
            $validated = $request->validate([
                'name'         => 'required|string|max:100',
                'code'         => 'required|string|max:50',
                'start_time'   => 'required|date_format:H:i',
                'end_time'     => 'required|date_format:H:i',
                'grace_period' => 'nullable|integer|min:0',
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'success'     => false,
                'message'     => 'Validation failed',
                'status_code' => 422,
                'errors'      => $e->errors(),
            ], 422);
        }

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
    public function show(Assignment $assignment)
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
    public function update(Request $request, Assignment $assignment)
    {
        $this->authorize('manage', [Assignment::class, $assignment->project]);

        try {
            $validated = $request->validate([
                'name'         => 'sometimes|string|max:100',
                'code'         => 'sometimes|string|max:50',
                'start_time'   => 'sometimes|date_format:H:i',
                'end_time'     => 'sometimes|date_format:H:i',
                'grace_period' => 'sometimes|integer|min:0',
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'success'     => false,
                'message'     => 'Validation failed',
                'status_code' => 422,
                'errors'      => $e->errors(),
            ], 422);
        }

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
    public function destroy(Assignment $assignment)
    {
        $this->authorize('manage', [Assignment::class, $assignment->project]);

        $assignment->delete();

        return response()->json([
            'message' => 'assignment deleted successfully',
        ]);
    }
}
