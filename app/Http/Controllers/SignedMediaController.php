<?php

namespace App\Http\Controllers;

use App\Models\Attendance;
use App\Models\PatrolScanPhoto;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class SignedMediaController extends Controller
{
    public function patrolScanPhoto(Request $request, PatrolScanPhoto $photo)
    {
        if (! Storage::disk('public')->exists($photo->photo)) {
            abort(404);
        }

        return Storage::disk('public')->response($photo->photo);
    }

    public function attendanceSelfie(Request $request, Attendance $attendance)
    {
        $path = $attendance->selfie_photo_path;
        if (! $path || ! Storage::disk('public')->exists($path)) {
            abort(404);
        }

        return Storage::disk('public')->response($path);
    }

    public function userAvatar(Request $request, User $user)
    {
        $path = $user->avatar;
        if (! $path || ! Storage::disk('public')->exists($path)) {
            abort(404);
        }

        return Storage::disk('public')->response($path);
    }
}
