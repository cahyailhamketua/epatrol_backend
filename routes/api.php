<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\OrganizationController;
use App\Http\Controllers\Api\ProjectController;
use App\Http\Controllers\Api\ShiftController;
use App\Http\Controllers\Api\PostController;
use App\Http\Controllers\Api\PatrolPointController;

// user routes
Route::post('/login', [AuthController::class, 'login']);

Route::middleware('auth:sanctum')->group(function () {
    // Index user
    Route::get('/users', [UserController::class, 'index']);
    // show user
    Route::get('/users/{user}', [UserController::class, 'show']);
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

// organization routes
Route::middleware('auth:sanctum')->group(function () {
    Route::get('/organizations', [OrganizationController::class, 'index']);
    Route::post('/organizations', [OrganizationController::class, 'store']);
    Route::get('/organizations/{organization}', [OrganizationController::class, 'show']);
    Route::put('/organizations/{organization}', [OrganizationController::class, 'update']);

    Route::patch('/organizations/{organization}/deactivate', [OrganizationController::class, 'deactivate']);
    Route::patch('/organizations/{organization}/activate', [OrganizationController::class, 'activate']);
    //project by organization
    Route::get('/organizations/{organization}/projects', [ProjectController::class, 'projectsByOrganization']
    );
});

// project routes
Route::middleware('auth:sanctum')->group(function () {
    Route::get('/projects', [ProjectController::class, 'index']);
    Route::post('/projects', [ProjectController::class, 'store']);
    Route::get('/projects/{project}', [ProjectController::class, 'show']);
    Route::put('/projects/{project}', [ProjectController::class, 'update']);

    Route::patch('/projects/{project}/deactivate', [ProjectController::class, 'deactivate']);
    Route::patch('/projects/{project}/activate', [ProjectController::class, 'activate']);
    // user by project
    Route::get('/projects/{project}/users', [ProjectController::class, 'users']);
});

// shift routes
Route::middleware('auth:sanctum')->group(function () {
    Route::get('/projects/{project}/shifts', [ShiftController::class, 'index']);
    Route::post('/projects/{project}/shifts', [ShiftController::class, 'store']);

    Route::get('/shifts/{shift}', [ShiftController::class, 'show']);
    Route::put('/shifts/{shift}', [ShiftController::class, 'update']);
    Route::delete('/shifts/{shift}', [ShiftController::class, 'destroy']);
});

// post-patrol routes
Route::middleware('auth:sanctum')->group(function () {
    Route::get('/projects/{project}/posts', [PostController::class, 'indexByProject']);
    Route::post('/projects/{project}/posts', [PostController::class, 'store']);

    Route::get('/posts', [PostController::class, 'index']);
    Route::get('/posts/{post}', [PostController::class, 'show']);
    Route::put('/posts/{post}', [PostController::class, 'update']);
    Route::delete('/posts/{post}', [PostController::class, 'destroy']);

});

// patrol point routes
Route::middleware('auth:sanctum')->group(function () {
    Route::post('/posts/{post}/patrol-points', [PatrolPointController::class, 'store']);
    Route::put('/patrol-points/{patrolPoint}', [PatrolPointController::class, 'update']);
    Route::delete('/patrol-points/{patrolPoint}',[PatrolPointController::class, 'destroy']);
});