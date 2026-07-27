<?php

namespace App\Policies;

use App\Models\Document;
use App\Models\User;

class DocumentPolicy
{
    public function viewAny(User $user): bool
    {
        return in_array($user->role, [
            'dev',
            'ho',
            'admin_project',
            'komandan_regu',
        ]);
    }

    public function view(User $user, Document $document): bool
    {
        if ($user->role === 'dev') {
            return true;
        }

        if ($user->role === 'ho') {
            return $user->organization_id === $document->project->organization_id;
        }

        if (in_array($user->role, [
            'admin_project',
            'komandan_regu',
        ])) {
            return (int) $user->project_id === (int) $document->project_id;
        }

        return false;
    }

    public function create(User $user): bool
    {
        return in_array($user->role, [
            'dev',
            'ho',
            'admin_project',
            'komandan_regu',
        ]);
    }

    public function update(User $user, Document $document): bool
    {
        return $this->view($user, $document);
    }

    public function delete(User $user, Document $document): bool
    {
        return $this->view($user, $document);
    }

    public function download(User $user, Document $document): bool
    {
        return $this->view($user, $document);
    }
}