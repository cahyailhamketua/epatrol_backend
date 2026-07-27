<?php

namespace App\Http\Controllers;

use App\Models\Attendance;
use App\Models\Organization;
use App\Models\PatrolScanPhoto;
use App\Models\User;
use App\Models\Document;
use App\Models\DailyReport;
use App\Models\Project;
use App\Models\BeritaAcara;
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

    public function userKtpPhoto(Request $request, User $user)
    {
        $path = $user->ktp_photo;
        if (! $path || ! Storage::disk('public')->exists($path)) {
            abort(404);
        }

        return Storage::disk('public')->response($path);
    }

    public function document(Request $request, Document $document)
    {
        $path = $document->file_path;

        if (! $path || ! Storage::disk('public')->exists($path)) {
            abort(404);
        }

        return Storage::disk('public')->response(
            $path,
            $document->file_name,
            [
                'Content-Type' => 'application/pdf',
            ]
        );
    }

    public function dailyReport(Request $request, DailyReport $report)
    {
        $path = $report->pdf_path;

        if (! $path || ! Storage::disk('public')->exists($path)) {
            abort(404);
        }

        return Storage::disk('public')->response(
            $path,
            basename($path),
            [
                'Content-Type' => 'application/pdf',
            ]
        );
    }

    public function organizationLogo(Request $request, Organization $organization)
    {
        $path = $organization->logo;

        if (! $path || ! Storage::disk('public')->exists($path)) {
            abort(404);
        }

        return Storage::disk('public')->response($path);
    }

    public function projectLogo(Request $request, Project $project)
    {
        $path = $project->logo;

        if (! $path || ! Storage::disk('public')->exists($path)) {
            abort(404);
        }

        return Storage::disk('public')->response($path);
    }

    public function beritaAcara(Request $request, BeritaAcara $beritaAcara)
    {
        $path = $beritaAcara->pdf_path;

        if (! $path || ! Storage::disk('public')->exists($path)) {
            abort(404);
        }

        return Storage::disk('public')->response(
            $path,
            basename($path),
            [
                'Content-Type' => 'application/pdf',
            ]
        );
    }
}
