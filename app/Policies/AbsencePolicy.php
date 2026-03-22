<?php

namespace App\Policies;

use App\Models\Absence;
use App\Models\Project;
use App\Models\User;

/**
 * Absence hanya dikelola admin lapangan (admin_project) dan HO dari sheet schedule.
 */
class AbsencePolicy
{
    public function viewAny(User $user): bool
    {
        return in_array($user->role, ['dev', 'ho', 'admin_project']);
    }

    /** Lihat/list absence per project */
    public function viewForProject(User $user, Project $project): bool
    {
        if ($user->role === 'dev') {
            return true;
        }

        if ($user->role === 'ho') {
            return $user->organization_id === $project->organization_id;
        }

        return $user->role === 'admin_project' && $user->project_id === $project->id;
    }

    /** Detail satu absence */
    public function view(User $user, Absence $absence): bool
    {
        $absence->loadMissing('schedule.project');
        $project = $absence->schedule->project;

        return $this->viewForProject($user, $project);
    }

    /** Buat / ubah / hapus absence */
    public function manageForProject(User $user, Project $project): bool
    {
        return $this->viewForProject($user, $project);
    }
}
