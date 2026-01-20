<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\AuthController;

// user routes
Route::post('/login', [AuthController::class, 'login']);
Route::middleware('auth:sanctum')->group(function () {
    // Update user
    Route::put('/users/{user}', [UserController::class, 'update']);
    // Nonaktifkan user
    Route::patch('/users/{user}/deactivate', [UserController::class, 'deactivate']);
    // Aktifkan kembali
    Route::patch('/users/{user}/activate', [UserController::class, 'activate']);
    // Create user
    Route::post('/users', [UserController::class, 'store']);
    // logout
    Route::post('/logout', [AuthController::class, 'logout']);
    // change password
    Route::post('/change-password', [AuthController::class, 'changePassword']);
    // check token
    Route::get('/me', [AuthController::class, 'me']);
});