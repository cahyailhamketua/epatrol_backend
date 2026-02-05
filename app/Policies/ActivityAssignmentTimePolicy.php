<?php

namespace App\Policies;

use App\Models\User;
use App\Models\Activity;
use App\Models\ActivityAssignmentTime;

class ActivityAssignmentTimePolicy
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

    public function view(User $user, ActivityAssignmentTime $time): bool
    {
        $project = $time->activity->post->project;

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

    public function manage(User $user, Activity $activity): bool
    {
        $project = $activity->post->project;

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
