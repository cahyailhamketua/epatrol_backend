<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\OrganizationController;
use App\Http\Controllers\Api\ProjectController;
use App\Http\Controllers\Api\ShiftController;
use App\Http\Controllers\Api\AssignmentController;
use App\Http\Controllers\Api\PostController;
use App\Http\Controllers\Api\TeamController;
use App\Http\Controllers\Api\ActivityController;
use App\Http\Controllers\Api\ActivityAssignmentTimeController;
use App\Http\Controllers\Api\PatrolPointController;
use App\Http\Controllers\Api\ScheduleController;
use App\Http\Controllers\Api\AttendanceController;
use App\Http\Controllers\Api\PatrolScanController;
use App\Http\Controllers\Api\QrCodeController;

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
    // assignments by organization
    Route::get('/organizations/{organization}/assignments', [AssignmentController::class, 'indexByOrganization']);
    // activities schedule by organization
    Route::get('/organizations/{organization}/activities/schedule', [ActivityController::class, 'scheduleByOrganization']);
    // user by organization
    Route::get('/organizations/{organization}/users', [OrganizationController::class, 'users']);
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
    // teams by project
    Route::get('/projects/{project}/teams', [TeamController::class, 'indexByProject']);
    Route::post('/projects/{project}/teams', [TeamController::class, 'store']);
    // activities schedule by project
    Route::get('/projects/{project}/activities/schedule', [ActivityController::class, 'scheduleByProject']);
});

// team routes
Route::middleware('auth:sanctum')->group(function () {
    Route::get('/teams', [TeamController::class, 'index']);
    Route::get('/teams/{team}', [TeamController::class, 'show']);
    Route::put('/teams/{team}', [TeamController::class, 'update']);
    Route::delete('/teams/{team}', [TeamController::class, 'destroy']);
    Route::get('/teams/{team}/members', [TeamController::class, 'members']);
});

// shift routes
// Route::middleware('auth:sanctum')->group(function () {
//     Route::get('/projects/{project}/shifts', [ShiftController::class, 'index']);
//     Route::post('/projects/{project}/shifts', [ShiftController::class, 'store']);

//     Route::get('/shifts/{shift}', [ShiftController::class, 'show']);
//     Route::put('/shifts/{shift}', [ShiftController::class, 'update']);
//     Route::delete('/shifts/{shift}', [ShiftController::class, 'destroy']);
// });

// assignment routes
Route::middleware('auth:sanctum')->group(function () {
    Route::get('/assignments', [AssignmentController::class, 'index']);
    Route::get('/projects/{project}/assignments', [AssignmentController::class, 'indexByProject']);
    Route::post('/projects/{project}/assignments', [AssignmentController::class, 'store']);
    
    Route::get('/assignments/{assignment}', [AssignmentController::class, 'show']);
    Route::put('/assignments/{assignment}', [AssignmentController::class, 'update']);
    Route::delete('/assignments/{assignment}', [AssignmentController::class, 'destroy']);
});

// post-patrol routes
Route::middleware('auth:sanctum')->group(function () {
    Route::get('/projects/{project}/posts', [PostController::class, 'indexByProject']);
    Route::post('/projects/{project}/posts', [PostController::class, 'store']);

    Route::get('/posts/types', [PostController::class, 'types']);
    Route::get('/posts/by-type/{type}', [PostController::class, 'byType']);
    Route::get('/posts', [PostController::class, 'index']);
    Route::get('/posts/{post}', [PostController::class, 'show']);
    Route::put('/posts/{post}', [PostController::class, 'update']);
    Route::delete('/posts/{post}', [PostController::class, 'destroy']);

});

// patrol point routes
Route::middleware('auth:sanctum')->group(function () {
    Route::get('/posts/{post}/patrol-points', [PatrolPointController::class, 'indexByPost']);
    Route::post('/posts/{post}/patrol-points', [PatrolPointController::class, 'store']);
    Route::get('/patrol-points/{patrolPoint}', [PatrolPointController::class, 'show']);
    Route::put('/patrol-points/{patrolPoint}', [PatrolPointController::class, 'update']);
    Route::delete('/patrol-points/{patrolPoint}',[PatrolPointController::class, 'destroy']);
});

// activity routes
Route::middleware('auth:sanctum')->group(function () {
    // All activities grouped by post & assignment
    Route::get('/activities/schedule', [ActivityController::class, 'schedule']);

    Route::get('/posts/{post}/activities', [ActivityController::class, 'index']);
    Route::get('/posts/{post}/activities/schedule', [ActivityController::class, 'scheduleByPost']);
    Route::post('/posts/{post}/activities', [ActivityController::class, 'store']);
    Route::put('/posts/{post}/activities', [ActivityController::class, 'update']);
    
    Route::put('/activities/{activity}', [ActivityController::class, 'updateActivity']);
    Route::delete('/activities/{activity}', [ActivityController::class, 'destroy']);
});

// activity assignment time routes
Route::middleware('auth:sanctum')->group(function () {
    // Get all activity assignment times (with filters)
    Route::get('/activity-assignment-times', [ActivityAssignmentTimeController::class, 'index']);
    
    // Activity assignment times by activity
    Route::get('/activities/{activity}/assignment-times', [ActivityAssignmentTimeController::class, 'indexByActivity']);
    Route::post('/activities/{activity}/assignment-times', [ActivityAssignmentTimeController::class, 'store']);
    Route::post('/activities/{activity}/assignment-times/bulk', [ActivityAssignmentTimeController::class, 'storeBulk']);
    
    // Activity assignment times by assignment
    Route::get('/assignments/{assignment}/activity-times', [ActivityAssignmentTimeController::class, 'indexByAssignment']);
    
    // Individual activity assignment time operations
    Route::get('/activity-assignment-times/{activityAssignmentTime}', [ActivityAssignmentTimeController::class, 'show']);
    Route::put('/activity-assignment-times/{activityAssignmentTime}', [ActivityAssignmentTimeController::class, 'update']);
    Route::delete('/activity-assignment-times/{activityAssignmentTime}', [ActivityAssignmentTimeController::class, 'destroy']);
    
    // Bulk delete
    Route::post('/activity-assignment-times/delete-bulk', [ActivityAssignmentTimeController::class, 'destroyBulk']);
});

// schedule routes
Route::middleware('auth:sanctum')->group(function () {
    // Get all schedules (with filters)
    Route::get('/schedules', [ScheduleController::class, 'index']);
    
    // Get schedules by project
    Route::get('/projects/{project}/schedules', [ScheduleController::class, 'indexByProject']);
    Route::post('/projects/{project}/schedules', [ScheduleController::class, 'store']);
    Route::post('/projects/{project}/schedules/bulk', [ScheduleController::class, 'storeBulk']);

    // Grid sheet & export
    Route::get('/projects/{project}/schedules/sheet', [ScheduleController::class, 'sheet']);
    Route::get('/projects/{project}/schedules/export', [ScheduleController::class, 'export']);

    // Generate schedules by team & month
    Route::post('/projects/{project}/teams/{team}/schedules/generate', [ScheduleController::class, 'generateForTeam']);
    Route::post('/projects/{project}/teams/{team}/schedule-template', [ScheduleController::class, 'setTeamScheduleTemplate']);
    Route::get('/projects/{project}/teams/{team}/schedule-template', [ScheduleController::class, 'showTeamScheduleTemplate']);
    Route::post('/projects/{project}/teams/{team}/schedules/generate-from-template', [ScheduleController::class, 'generateForTeamFromTemplate']);
    Route::delete('/projects/{project}/teams/{team}/schedules', [ScheduleController::class, 'destroyForTeam']);
    
    // Get schedules by user
    Route::get('/users/{user}/schedules', [ScheduleController::class, 'indexByUser']);
    
    // Individual schedule operations
    Route::get('/schedules/{schedule}', [ScheduleController::class, 'show']);
    Route::put('/schedules/{schedule}', [ScheduleController::class, 'update']);
    Route::patch('/schedules/{schedule}', [ScheduleController::class, 'updateAssignment']);
    Route::delete('/schedules/{schedule}', [ScheduleController::class, 'destroy']);
    
    // Bulk delete
    Route::post('/schedules/delete-bulk', [ScheduleController::class, 'destroyBulk']);

    // Team members management
    Route::post('/teams/{team}/members', [ScheduleController::class, 'addTeamMember']);
});

// Attendance routes
Route::middleware('auth:sanctum')->group(function () {
    // List attendances (dengan filter)
    Route::get('/attendances', [AttendanceController::class, 'index']);
    
    // Validate time before check-in (tanpa foto)
    Route::post('/attendances/validate-time', [AttendanceController::class, 'validateCheckInTime']);
    
    // Check-in (dengan foto)
    Route::post('/attendances/check-in', [AttendanceController::class, 'checkIn']);
    
    // Patrol scan (mobile post)
    Route::post('/attendances/patrol-scan', [AttendanceController::class, 'patrolScan']);
    
    // Check-out
    Route::post('/attendances/check-out', [AttendanceController::class, 'checkOut']);
    
    // View attendance detail
    Route::get('/attendances/{attendance}', [AttendanceController::class, 'show']);
});

// Patrol Scan routes
Route::middleware('auth:sanctum')->group(function () {
    // QR Code image (for printing / display)
    Route::get('/qr-codes/{qrCode}/image', [QrCodeController::class, 'image']);

    // Get scan progress for attendance
    Route::get('/attendance/{attendance}/patrol-scan/progress', [PatrolScanController::class, 'getProgress']);
    
    // Get all scans for attendance
    Route::get('/attendance/{attendance}/patrol-scans', [PatrolScanController::class, 'getAttendanceScans']);
    
    // Get scan statistics
    Route::get('/attendance/{attendance}/patrol-scan/statistics', [PatrolScanController::class, 'getStatistics']);
    
    // Perform patrol scan (QR scan)
    Route::post('/patrol-scan', [PatrolScanController::class, 'performScan']);
    
    // Get scan details
    Route::get('/patrol-scan/{scan}', [PatrolScanController::class, 'show']);
    
    // Add photo to scan
    Route::post('/patrol-scan/{scan}/photo', [PatrolScanController::class, 'addPhoto']);
    
    // Delete photo from scan
    Route::delete('/patrol-scan/{scan}/photo/{photoId}', [PatrolScanController::class, 'deletePhoto']);
    
    // Download photo
    Route::get('/patrol-scan-photo/{photoId}/download', [PatrolScanController::class, 'downloadPhoto']);
});
