<?php

namespace App\Policies;

use App\Models\User;
use App\Models\Project;

class ProjectPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Project $project): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return in_array($user->role, ['dev', 'ho']);
    }

    public function update(User $user, Project $project): bool
    {
        return in_array($user->role, ['dev', 'ho']);
    }

    public function deactivate(User $user, Project $project): bool
    {
        return in_array($user->role, ['dev', 'ho']) && $project->active;
    }

    public function activate(User $user, Project $project): bool
    {
        return in_array($user->role, ['dev', 'ho']) && !$project->active;
    }
}
