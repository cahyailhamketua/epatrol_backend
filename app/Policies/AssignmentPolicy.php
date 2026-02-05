<?php

namespace App\Policies;

use App\Models\User;
use App\Models\Assignment;
use App\Models\Project;

class AssignmentPolicy
{
    /**
     * LIST ASSIGNMENT
     */
    public function viewAny(User $user, Project $project): bool
    {
        // DEV bebas
        if ($user->role === 'dev') {
            return true;
        }

        // HO hanya project dalam organization-nya
        if ($user->role === 'ho') {
            return $user->organization_id === $project->organization_id;
        }

        // Admin project hanya project miliknya
        if ($user->role === 'admin_project') {
            return $user->project_id === $project->id;
        }

        return false;
    }

    /**
     * DETAIL ASSIGNMENT
     */
    public function view(User $user, Assignment $assignment): bool
    {
        $project = $assignment->project;

        if ($user->role === 'dev') {
            return true;
        }

        if ($user->role === 'ho') {
            return $user->organization_id === $project->organization_id;
        }

        if ($user->role === 'admin_project') {
            return $user->project_id === $project->id;
        }

        return false;
    }

    /**
     * CREATE / UPDATE / DELETE
     */
    public function manage(User $user, Project $project): bool
    {
        if ($user->role === 'dev') {
            return true;
        }

        if ($user->role === 'ho') {
            return $user->organization_id === $project->organization_id;
        }

        if ($user->role === 'admin_project') {
            return $user->project_id === $project->id;
        }

        return false;
    }
}
