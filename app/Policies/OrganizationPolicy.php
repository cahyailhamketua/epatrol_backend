<?php

namespace App\Policies;

use App\Models\User;
use App\Models\Organization;

class OrganizationPolicy
{
    public function viewAny(User $user): bool
    {
        //return true; // semua user boleh lihat
        return $user->role === 'dev';
    }

    public function view(User $user, Organization $org): bool
    {
        //return true;
        return $user->role === 'dev';
    }

    public function create(User $user): bool
    {
        return $user->role === 'dev';
    }

    public function update(User $user, Organization $org): bool
    {
        return $user->role === 'dev';
    }

    public function deactivate(User $user, Organization $org): bool
    {
        return $user->role === 'dev' && $org->active;
    }

    public function activate(User $user, Organization $org): bool
    {
        return $user->role === 'dev' && !$org->active;
    }

    public function viewProjects(User $user, Organization $organization): bool
    {
        // DEV bebas
        if ($user->role === 'dev') {
            return true;
        }

        // HO hanya organization tempat dia bekerja
        if ($user->role === 'ho') {
            return $user->organization_id === $organization->id;
        }

        return false;
    }
}
