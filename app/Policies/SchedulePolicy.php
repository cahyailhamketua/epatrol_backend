<?php

namespace App\Policies;

use App\Models\User;
use App\Models\Schedule;
use App\Models\Project;

class SchedulePolicy
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

    public function view(User $user, Schedule $schedule): bool
    {
        $project = $schedule->project;

        if ($user->role === 'dev') {
            return true;
        }

        if ($user->role === 'ho') {
            return $user->organization_id === $project->organization_id;
        }

        if ($user->role === 'admin_project') {
            return $user->project_id === $project->id;
        }

        // anggota dan komandan_regu hanya lihat schedule mereka sendiri
        if (in_array($user->role, ['anggota', 'komandan_regu'])) {
            return $user->id === $schedule->user_id && $user->project_id === $project->id;
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
