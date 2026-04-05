<?php

namespace App\Policies;

use App\Models\Activity;
use App\Models\User;
use App\Models\Project;
use Illuminate\Auth\Access\Response;

class ActivityPolicy
{
    /**
     * View activities
     */
    public function viewAny(User $user): bool
    {
        return in_array($user->role, [
            'dev',
            'ho',
            'admin_project',
            'komandan_regu',
            'anggota',
        ]);
    }

    /**
     * Manage activities (create/update/delete)
     */
    public function manage(User $user, Project $project): bool
    {
        if ($user->role === 'dev') {
            return true;
        }

        if ($user->role === 'admin_project') {
            return $user->project_id === $project->id;
        }

        if ($user->role === 'ho') {
            return $project->organization_id === $user->organization_id;
        }

        return false;
    }
}

