<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class OrganizationController extends Controller
{
    /**
     * LIST ORGANIZATION (PAGINATED & FILTERED)
     */
    public function index(Request $request)
    {
        $this->authorize('viewAny', Organization::class);

        $organizations = Organization::query()
            ->select('id', 'name', 'logo', 'start_date', 'end_date', 'active')
            ->when($request->has('active'), fn ($q) =>
                $q->where('active', $request->boolean('active'))
            )
            ->when($request->filled('search'), fn ($q) =>
                $q->where('name', 'like', '%' . $request->search . '%')
            )
            ->orderBy('name')
            ->paginate($request->get('per_page', 15));

        return response()->json($organizations);
    }

    /**
     * DETAIL
     */
    public function show(Organization $organization)
    {
        $this->authorize('view', $organization);

        return response()->json([
            'data' => $organization,
        ]);
    }

    /**
     * CREATE
     */
    public function store(Request $request)
    {
        $this->authorize('create', Organization::class);

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|string|max:255',
            'phone' => 'nullable|string|max:20',
            'logo' => 'nullable|string',
            'code' => 'required|string|max:50|unique:organizations,code',
            'address' => 'nullable|string',
            'start_date' => 'nullable|string',
            'end_date' => 'nullable|string',

        ]);

        $org = Organization::create($validated);

        return response()->json([
            'message' => 'Organization created successfully',
            'data' => $org,
        ], 201);
    }

    /**
     * UPDATE
     */
    public function update(Request $request, Organization $organization)
    {
        $this->authorize('update', $organization);

        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'email' => 'sometimes|string|max:255',
            'phone' => 'sometimes|string|max:20',
            'code' => [
                'sometimes',
                'string',
                Rule::unique('organizations')->ignore($organization->id),
            ],
            'address' => 'sometimes|string',
            'start_date' => 'sometimes|string',
            'end_date' => 'sometimes|string',
        ]);

        $organization->update($validated);

        return response()->json([
            'message' => 'Organization updated successfully',
            'data' => $organization,
        ]);
    }

    /**
     * DEACTIVATE
     */
    public function deactivate(Organization $organization)
    {
        $this->authorize('deactivate', $organization);

        $organization->update(['active' => false]);

        return response()->json([
            'message' => 'Organization deactivated',
        ]);
    }

    /**
     * ACTIVATE
     */
    public function activate(Organization $organization)
    {
        $this->authorize('activate', $organization);

        $organization->update(['active' => true]);

        return response()->json([
            'message' => 'Organization activated',
        ]);
    }
}
