<?php

namespace App\Support;

use App\Models\Attendance;
use App\Models\PatrolScanPhoto;
use App\Models\User;
use App\Models\Document;
use App\Models\DailyReport;
use App\Models\Project;
use App\Models\BeritaAcara;
use DateTimeInterface;
use Illuminate\Support\Facades\URL;

/**
 * URL bertanda tangan (signed / temporary signed URL):
 * Laravel menambahkan query `signature` + `expires` yang di-hash dengan APP_KEY.
 * Siapa pun yang memegang URL lengkap bisa mengakses file sampai waktu kedaluwarsa,
 * tanpa header Authorization (cocok untuk tag img src di web / WebView).
 * Untuk akses pakai Bearer token saja, gunakan endpoint API .../inline (bukan signed).
 *
 * File tetap disimpan di storage/app/public; route web `/media/...` yang membacanya.
 */
class SignedMediaUrl
{
    public static function patrolScanPhoto(PatrolScanPhoto $photo, ?DateTimeInterface $expires = null): string
    {
        return URL::temporarySignedRoute(
            'media.patrol-scan-photo',
            $expires ?? now()->addDays(7),
            ['photo' => $photo->id]
        );
    }

    public static function attendanceSelfie(Attendance $attendance, ?DateTimeInterface $expires = null): string
    {
        return URL::temporarySignedRoute(
            'media.attendance-selfie',
            $expires ?? now()->addDays(7),
            ['attendance' => $attendance->id]
        );
    }

    public static function userAvatar(User $user, ?DateTimeInterface $expires = null): string
    {
        return URL::temporarySignedRoute(
            'media.user-avatar',
            $expires ?? now()->addDays(7),
            ['user' => $user->id]
        );
    }

    public static function userKtpPhoto(User $user, ?DateTimeInterface $expires = null): string
    {
        return URL::temporarySignedRoute(
            'media.user-ktp-photo',
            $expires ?? now()->addDays(7),
            ['user' => $user->id]
        );
    }

    public static function document(Document $document, ?DateTimeInterface $expires = null): string 
    {
        return URL::temporarySignedRoute(
            'media.document',
            $expires ?? now()->addDays(7),
            [
                'document' => $document->id,
            ]
        );
    }

    public static function dailyReport(DailyReport $report, ?DateTimeInterface $expires = null): string 
    {
        return URL::temporarySignedRoute(
            'media.daily-report',
            $expires ?? now()->addDays(7),
            [
                'report' => $report->id,
            ]
        );
    }

    public static function projectLogo(Project $project, ?DateTimeInterface $expires = null): string
    {
        return URL::temporarySignedRoute(
            'media.project-logo',
            $expires ?? now()->addDays(7),
            [
                'project' => $project->id,
            ]
        );
    }

    public static function beritaAcara(BeritaAcara $beritaAcara, ?DateTimeInterface $expires = null): string
    {
        return URL::temporarySignedRoute(
            'media.berita-acara',
            $expires ?? now()->addDays(7),
            [
                'beritaAcara' => $beritaAcara->id,
            ]
        );
    }
}
