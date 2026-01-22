<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Models\Shift;
use Illuminate\Http\Request;

class ShiftController extends Controller
{
    /**
     * LIST SHIFT PER PROJECT
     * GET /projects/{project}/shifts
     */
    public function index(Project $project)
    {
        $this->authorize('viewAny', [Shift::class, $project]);

        $shifts = $project->shifts()
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
            'data' => $shifts,
        ]);
    }

    /**
     * CREATE SHIFT
     * POST /projects/{project}/shifts
     */
    public function store(Request $request, Project $project)
    {
        $this->authorize('manage', [Shift::class, $project]);

        $validated = $request->validate([
            'name'         => 'required|string|max:100',
            'code'         => 'required|string|max:50',
            'start_time'   => 'required|date_format:H:i',
            'end_time'     => 'required|date_format:H:i',
            'grace_period' => 'nullable|integer|min:0',
        ]);

        $shift = $project->shifts()->create($validated);

        return response()->json([
            'message' => 'Shift created successfully',
            'data'    => $shift,
        ], 201);
    }

    /**
     * DETAIL SHIFT
     * GET /shifts/{shift}
     */
    public function show(Shift $shift)
    {
        $this->authorize('view', $shift);

        return response()->json([
            'data' => $shift,
        ]);
    }

    /**
     * UPDATE SHIFT
     * PUT /shifts/{shift}
     */
    public function update(Request $request, Shift $shift)
    {
        $this->authorize('manage', [Shift::class, $shift->project]);

        $validated = $request->validate([
            'name'         => 'sometimes|string|max:100',
            'code'         => 'sometimes|string|max:50',
            'start_time'   => 'sometimes|date_format:H:i',
            'end_time'     => 'sometimes|date_format:H:i',
            'grace_period' => 'sometimes|integer|min:0',
        ]);

        $shift->update($validated);

        return response()->json([
            'message' => 'Shift updated successfully',
            'data'    => $shift,
        ]);
    }

    /**
     * DELETE SHIFT
     * DELETE /shifts/{shift}
     */
    public function destroy(Shift $shift)
    {
        $this->authorize('manage', [Shift::class, $shift->project]);

        $shift->delete();

        return response()->json([
            'message' => 'Shift deleted successfully',
        ]);
    }
}
