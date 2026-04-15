<?php

namespace App\Policies;

use App\Models\Organization;
use App\Models\Project;
use App\Models\User;

class ProjectPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Project $project): bool
    {
        // DEV bisa lihat semua
        if ($user->role === 'dev') {
            return true;
        }

        // HO → hanya project dalam organization miliknya
        if ($user->role === 'ho') {
            return $user->organization_id === $project->organization_id;
        }

        // Admin project hanya project miliknya
        if ($user->role === 'admin_project') {
            return $user->project_id === $project->id;
        }

        // Komandan regu (danru): laporan & progress di project sendiri
        if ($user->role === 'komandan_regu') {
            return (int) $user->project_id === (int) $project->id;
        }

        return false;
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
        return in_array($user->role, ['dev', 'ho']) && ! $project->active;
    }
}
