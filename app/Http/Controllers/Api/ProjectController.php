<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Models\User;
use App\Models\Organization;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ProjectController extends Controller
{
    /**
     * LIST PROJECT (PAGINATION + FILTER)
     */
    public function index(Request $request)
    {
        $this->authorize('viewAny', Project::class);

        $user = $request->user();

        $projects = Project::query()
            ->select('id', 'organization_id', 'name', 'code', 'active')

            // 🔒 ROLE BASED SCOPING
            ->when($user->role === 'ho', function ($q) use ($user) {
                $q->where('organization_id', $user->organization_id);
            })

            ->when($user->role === 'admin_project', function ($q) use ($user) {
                $q->where('id', $user->project_id);
            })

            // 🔍 FILTER OPSIONAL
            ->when($request->filled('organization_id'), fn ($q) =>
                $q->where('organization_id', $request->organization_id)
            )
            ->when($request->has('active'), fn ($q) =>
                $q->where('active', $request->boolean('active'))
            )
            ->when($request->filled('search'), fn ($q) =>
                $q->where('name', 'like', '%' . $request->search . '%')
            )

            ->orderBy('name')
            ->paginate($request->get('per_page', 15));

        return response()->json($projects);
    }


    /**
     * DETAIL PROJECT
     */
    public function show(Project $project)
    {
        $this->authorize('view', $project);

        return response()->json([
            'data' => $project,
        ]);
    }

    /**
     * CREATE PROJECT
     */
    public function store(Request $request)
    {
        $this->authorize('create', Project::class);

        $validated = $request->validate([
            'organization_id' => 'required|exists:organizations,id',
            'name' => 'required|string|max:255',
            'code' => 'nullable|string|max:50|unique:projects,code',
            'location_latitude' => 'nullable|string|max:255',
            'location_longitude' => 'nullable|string|max:255',
            'location_address' => 'nullable|string|max:255',
            'location_city' => 'nullable|string|max:255',
        ]);

        $project = Project::create($validated);

        return response()->json([
            'message' => 'Project created successfully',
            'data' => $project,
        ], 201);
    }

    /**
     * UPDATE PROJECT
     */
    public function update(Request $request, Project $project)
    {
        $this->authorize('update', $project);

        $validated = $request->validate([
            'organization_id' => 'sometimes|exists:organizations,id',
            'name' => 'sometimes|string|max:255',
            'code' => [
                'sometimes',
                'string',
                Rule::unique('projects')->ignore($project->id),
            ],
            'location_latitude' => 'sometimes|string|max:255',
            'location_longitude' => 'sometimes|string|max:255',
            'location_address' => 'sometimes|string|max:255',
            'location_city' => 'sometimes|string|max:255',
        ]);

        $project->update($validated);

        return response()->json([
            'message' => 'Project updated successfully',
            'data' => $project,
        ]);
    }

    /**
     * NONAKTIFKAN PROJECT
     */
    public function deactivate(Project $project)
    {
        $this->authorize('deactivate', $project);

        $project->update(['active' => false]);

        return response()->json([
            'message' => 'Project deactivated',
        ]);
    }

    /**
     * AKTIFKAN PROJECT
     */
    public function activate(Project $project)
    {
        $this->authorize('activate', $project);

        $project->update(['active' => true]);

        return response()->json([
            'message' => 'Project activated',
        ]);
    }

     /**
     * USER BY PROJECT
     */
    public function users(Project $project, Request $request)
    {
        $this->authorize('view', $project);

        $users = $project->users()
            ->select(
                'id',
                'full_name',
                'username',
                'email',
                'role',
                'project_id',
                'organization_id',
                'active'
            )
            ->where('active', true) // 🔥 HANYA USER AKTIF
            ->orderBy('full_name')
            ->paginate($request->get('per_page', 15));

        return response()->json($users);
    }

    /**
     * Project by Organization
     */
    public function projectsByOrganization(Organization $organization, Request $request)
    {
        $this->authorize('viewProjects', $organization);

        $projects = $organization->projects()
            ->select('id', 'organization_id', 'name', 'code', 'active')
            ->when($request->has('active'), fn ($q) =>
                $q->where('active', $request->boolean('active'))
            )
            ->when($request->filled('search'), fn ($q) =>
                $q->where('name', 'like', '%' . $request->search . '%')
            )
            ->orderBy('name')
            ->paginate($request->get('per_page', 15));

        return response()->json($projects);
    }
}
