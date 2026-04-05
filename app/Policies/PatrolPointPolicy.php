<?php

namespace App\Policies;

use App\Models\User;
use App\Models\Project;
use App\Models\PatrolPoint;

class PatrolPointPolicy
{
    public function viewAny(User $user, Project $project): bool
    {
        // DEV full access
        if ($user->role === 'dev') {
            return true;
        }

        // HO → semua project dalam organization
        if ($user->role === 'ho') {
            return $user->organization_id === $project->organization_id;
        }

        // Admin project
        if ($user->role === 'admin_project') {
            return $user->project_id === $project->id;
        }

        return false;
    }

    public function view(User $user, PatrolPoint $patrolPoint): bool
    {
        $project = $patrolPoint->post->project;

        // DEV full access
        if ($user->role === 'dev') {
            return true;
        }

        // HO → semua project dalam organization
        if ($user->role === 'ho') {
            return $user->organization_id === $project->organization_id;
        }

        // Admin project
        if ($user->role === 'admin_project') {
            return $user->project_id === $project->id;
        }

        return false;
    }

    public function manage(User $user, Project $project): bool
    {
        // DEV full access
        if ($user->role === 'dev') {
            return true;
        }

        // HO → semua project dalam organization
        if ($user->role === 'ho') {
            return $user->organization_id === $project->organization_id;
        }

        // Admin project
        if ($user->role === 'admin_project') {
            return $user->project_id === $project->id;
        }

        return false;
    }
}
