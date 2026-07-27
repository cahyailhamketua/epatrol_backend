<?php

namespace App\Policies;

use App\Models\DocumentType;
use App\Models\User;

class DocumentTypePolicy
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

    public function view(User $user, DocumentType $documentType): bool
    {
        if ($user->role === 'dev') {
            return true;
        }

        if ($user->role === 'ho') {
            return $user->organization_id === $documentType->project->organization_id;
        }

        if (in_array($user->role, [
            'admin_project',
            'komandan_regu',
        ])) {
            return (int) $user->project_id === (int) $documentType->project_id;
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

    public function update(User $user, DocumentType $documentType): bool
    {
        return $this->view($user, $documentType);
    }

    public function delete(User $user, DocumentType $documentType): bool
    {
        return $this->view($user, $documentType);
    }
}