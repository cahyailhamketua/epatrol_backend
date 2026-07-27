<?php

use App\Http\Controllers\SignedMediaController;
use Illuminate\Support\Facades\Route;

Route::middleware(['signed'])->group(function () {
    Route::get('/media/patrol-scan-photo/{photo}', [SignedMediaController::class, 'patrolScanPhoto'])
        ->name('media.patrol-scan-photo');
    Route::get('/media/attendance-selfie/{attendance}', [SignedMediaController::class, 'attendanceSelfie'])
        ->name('media.attendance-selfie');
    Route::get('/media/user-avatar/{user}', [SignedMediaController::class, 'userAvatar'])
        ->name('media.user-avatar');
    Route::get('/media/user-ktp-photo/{user}', [SignedMediaController::class, 'userKtpPhoto'])
        ->name('media.user-ktp-photo');
    Route::get('/media/document/{document}', [SignedMediaController::class, 'document'])
        ->name('media.document');
    Route::get('/media/daily-report/{report}', [SignedMediaController::class, 'dailyReport'])
        ->name('media.daily-report');
    Route::get('/media/organization-logo/{organization}', [SignedMediaController::class, 'organizationLogo'])
        ->name('media.organization-logo');
    Route::get('/media/project-logo/{project}', [SignedMediaController::class, 'projectLogo'])
        ->name('media.project-logo');
    Route::get('/media/berita-acara/{beritaAcara}', [SignedMediaController::class, 'beritaAcara'])
        ->name('media.berita-acara');
});

Route::get('/', function () {
    return view('welcome');
});

Route::get('/kebijakan-privasi', function () {
    return view('privacy-policy');
});
