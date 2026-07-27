<?php

namespace App\Policies;

use App\Models\BeritaAcara;
use App\Models\User;

class BeritaAcaraPolicy
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

    public function view(User $user, BeritaAcara $beritaAcara): bool
    {
        if ($user->role === 'dev') {
            return true;
        }

        if ($user->role === 'ho') {
            return $user->organization_id ===
                $beritaAcara->project->organization_id;
        }

        return (int) $user->project_id ===
            (int) $beritaAcara->project_id;
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

    public function update(User $user, BeritaAcara $beritaAcara): bool
    {
        return $this->view($user, $beritaAcara);
    }

    public function delete(User $user, BeritaAcara $beritaAcara): bool
    {
        return $this->view($user, $beritaAcara);
    }

    public function download(User $user, BeritaAcara $beritaAcara): bool
    {
        return $this->view($user, $beritaAcara);
    }

    public function generatePdf(User $user, BeritaAcara $beritaAcara): bool
    {
        return $this->view($user, $beritaAcara);
    }
}
