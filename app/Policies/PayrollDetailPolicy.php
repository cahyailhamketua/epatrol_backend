<?php

namespace App\Policies;

use App\Models\PayrollDetail;
use App\Models\User;

class PayrollDetailPolicy
{
    public function view(User $user, PayrollDetail $detail): bool
    {
        if (in_array($user->role, ['dev', 'ho'], true)) {
            return true;
        }

        return $user->id === $detail->user_id;
    }
}
