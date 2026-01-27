<?php

namespace App\Policies;

use App\Models\User;
use App\Models\Post;
use App\Models\Project;

class PostPolicy
{
    public function viewAny(User $user): bool
    {
        return in_array($user->role, [
            'ho',
            'komandan_regu',
            'admin_project',
            'anggota',
        ]);
    }

    public function viewAnyByProject(User $user, Project $project): bool
    {
        if ($user->role === 'dev') return true;

        if ($user->role === 'ho') {
            return $user->organization_id === $project->organization_id;
        }

        if (in_array($user->role, ['admin_project', 'komandan_regu', 'anggota'])) {
            return $user->project_id === $project->id;
        }

        return false;
    }

    public function view(User $user, Post $post): bool
    {
        $project = $post->project;

        if ($user->role === 'dev') return true;

        if ($user->role === 'ho') {
            return $user->organization_id === $project->organization_id;
        }

        if ($user->role === 'admin_project') {
            return $user->project_id === $project->id;
        }

        return false;
    }

    public function manage(User $user, Project $project): bool
    {
        if ($user->role === 'dev') return true;

        if ($user->role === 'ho') {
            return $user->organization_id === $project->organization_id;
        }

        if ($user->role === 'admin_project') {
            return $user->project_id === $project->id;
        }

        return false;
    }
}
