<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\EquipmentComponent;
use App\Models\Project;

class EquipmentComponentController extends Controller
{
    public function index(Project $project)
    {
        $this->authorize('view', $project);

        $components = EquipmentComponent::query()
            ->where('project_id', $project->id)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $components,
        ]);
    }

    public function destroy(Project $project, EquipmentComponent $equipmentComponent) 
    {
        $this->authorize('view', $project);

        abort_if(
            $equipmentComponent->project_id !== $project->id,
            404
        );

        $equipmentComponent->delete();

        return response()->json([
            'success' => true,
            'message' => 'Equipment component deleted.',
        ]);
    }
}