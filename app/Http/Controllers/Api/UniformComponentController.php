<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Models\UniformComponent;

class UniformComponentController extends Controller
{
    public function index(Project $project)
    {
        $this->authorize('view', $project);

        $components = UniformComponent::query()
            ->where('project_id', $project->id)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $components,
        ]);
    }

    public function destroy(Project $project, UniformComponent $uniformComponent) 
    {
        $this->authorize('view', $project);

        abort_if(
            $uniformComponent->project_id !== $project->id,
            404
        );

        $uniformComponent->delete();

        return response()->json([
            'success' => true,
            'message' => 'Uniform component deleted.',
        ]);
    }
}