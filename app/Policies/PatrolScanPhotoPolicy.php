<?php

namespace App\Policies;

use App\Models\User;
use App\Models\PatrolScanPhoto;

class PatrolScanPhotoPolicy
{
    /**
     * Can user view photos
     */
    public function view(User $user, PatrolScanPhoto $photo): bool
    {
        return $user->can('view', $photo->patrolScan);
    }

    /**
     * Can user download photo
     */
    public function download(User $user, PatrolScanPhoto $photo): bool
    {
        return $user->can('view', $photo->patrolScan);
    }

    /**
     * Can user delete photo
     */
    public function delete(User $user, PatrolScanPhoto $photo): bool
    {
        return $user->can('deletePhoto', $photo->patrolScan);
    }
}
