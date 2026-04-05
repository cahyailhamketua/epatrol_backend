<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Models\Team;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class TeamController extends Controller
{
    /**
     * LIST ALL TEAMS (OPTIONAL FILTER BY PROJECT)
     * GET /teams
     * GET /teams?project_id=1
     */
    public function index(Request $request)
    {
        $this->authorize('viewAny', Team::class);

        $query = Team::query()->with(['project', 'leader']);

        if ($request->has('project_id')) {
            $query->where('project_id', $request->project_id);
        }

        $teams = $query->orderBy('project_id')->orderBy('name')->get();

        return response()->json([
            'data' => $teams,
        ]);
    }

    /**
     * LIST TEAMS BY PROJECT
     * GET /projects/{project}/teams
     */
    public function indexByProject(Project $project)
    {
        $this->authorize('viewAnyByProject', [Team::class, $project]);

        $teams = $project->teams()
            ->with(['leader'])
            ->orderBy('name')
            ->get();

        return response()->json([
            'data' => $teams,
        ]);
    }

    /**
     * CREATE TEAM UNDER PROJECT
     * POST /projects/{project}/teams
     */
    public function store(Request $request, Project $project)
    {
        $this->authorize('manage', [Team::class, $project]);

        try {
            $validated = $request->validate([
                'name' => 'required|string|max:255',
                'leader_id' => 'nullable|exists:users,id',
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'success'     => false,
                'message'     => 'Validation failed',
                'status_code' => 422,
                'errors'      => $e->errors(),
            ], 422);
        }

        if (! empty($validated['leader_id'])) {
            $leader = User::find($validated['leader_id']);
            if ($leader->project_id !== $project->id) {
                return response()->json([
                    'message' => 'Leader user does not belong to this project',
                ], 403);
            }
        }

        $team = Team::create([
            'project_id' => $project->id,
            'name' => $validated['name'],
            'leader_id' => $validated['leader_id'] ?? null,
        ]);

        // Ketua regu otomatis jadi anggota tim agar generate schedule punya minimal 1 user
        if (! empty($team->leader_id)) {
            $team->users()->syncWithoutDetaching([
                $team->leader_id => [
                    'start_date' => now()->toDateString(),
                    'end_date' => null,
                ],
            ]);
        }

        $team->load(['project', 'leader', 'users']);

        return response()->json([
            'message' => 'Team created successfully',
            'data' => $team,
        ], 201);
    }

    /**
     * SHOW TEAM DETAIL
     * GET /teams/{team}
     */
    public function show(Team $team)
    {
        $this->authorize('view', $team);

        $team->load(['project', 'leader', 'users']);

        return response()->json([
            'data' => $team,
        ]);
    }

    /**
     * UPDATE TEAM
     * PUT /teams/{team}
     */
    public function update(Request $request, Team $team)
    {
        $this->authorize('manage', [Team::class, $team->project]);

        try {
            $validated = $request->validate([
                'name' => 'sometimes|string|max:255',
                'leader_id' => 'sometimes|nullable|exists:users,id',
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'success'     => false,
                'message'     => 'Validation failed',
                'status_code' => 422,
                'errors'      => $e->errors(),
            ], 422);
        }

        if (array_key_exists('leader_id', $validated) && ! is_null($validated['leader_id'])) {
            $leader = User::find($validated['leader_id']);
            if ($leader->project_id !== $team->project_id) {
                return response()->json([
                    'message' => 'Leader user does not belong to this project',
                ], 403);
            }
        }

        $team->update($validated);
        $team->load(['project', 'leader']);

        return response()->json([
            'message' => 'Team updated successfully',
            'data' => $team,
        ]);
    }

    /**
     * DELETE TEAM
     * DELETE /teams/{team}
     */
    public function destroy(Team $team)
    {
        $this->authorize('manage', [Team::class, $team->project]);

        $team->delete();

        return response()->json([
            'message' => 'Team deleted successfully',
        ]);
    }

    /**
     * LIST TEAM MEMBERS (ACTIVE PIVOT)
     * GET /teams/{team}/members
     */
    public function members(Team $team)
    {
        $this->authorize('view', $team);

        $members = $team->users()
            ->withPivot(['start_date', 'end_date'])
            ->orderBy('full_name')
            ->get();

        return response()->json([
            'data' => $members,
        ]);
    }
}

