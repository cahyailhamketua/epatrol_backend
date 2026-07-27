<?php

namespace App\Policies;

use App\Models\User;

class PayrollTerBracketPolicy
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

    public function manage(User $user): bool
    {
        return $user->role === 'dev';
    }
}