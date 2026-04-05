<?php

namespace App\Policies;

use App\Models\PayrollRun;
use App\Models\Project;
use App\Models\User;

class PayrollRunPolicy
{
    public function viewAnyByProject(User $user, Project $project): bool
    {
        if ($user->role === 'dev') {
            return true;
        }

        if ($user->role === 'ho') {
            return $user->organization_id === $project->organization_id;
        }

        return false;
    }

    public function manage(User $user, Project $project): bool
    {
        return $this->viewAnyByProject($user, $project);
    }

    public function release(User $user, PayrollRun $run): bool
    {
        return $this->viewAnyByProject($user, $run->project);
    }

    public function download(User $user, PayrollRun $run): bool
    {
        return $this->viewAnyByProject($user, $run->project);
    }
}
