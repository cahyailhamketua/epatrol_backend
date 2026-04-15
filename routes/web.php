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
});

Route::get('/', function () {
    return view('welcome');
});

Route::get('/kebijakan-privasi', function () {
    return view('privacy-policy');
});
