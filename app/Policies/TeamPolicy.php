<?php

namespace App\Policies;

use App\Models\Project;
use App\Models\Team;
use App\Models\User;

class TeamPolicy
{
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

    public function viewAnyByProject(User $user, Project $project): bool
    {
        if ($user->role === 'dev') {
            return true;
        }

        if ($user->role === 'ho') {
            return $user->organization_id === $project->organization_id;
        }

        if (in_array($user->role, ['admin_project', 'komandan_regu', 'anggota'])) {
            return $user->project_id === $project->id;
        }

        return false;
    }

    public function view(User $user, Team $team): bool
    {
        $project = $team->project;

        if ($user->role === 'dev') {
            return true;
        }

        if ($user->role === 'ho') {
            return $user->organization_id === $project->organization_id;
        }

        if (in_array($user->role, ['admin_project', 'komandan_regu', 'anggota'])) {
            return $user->project_id === $project->id;
        }

        return false;
    }

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

