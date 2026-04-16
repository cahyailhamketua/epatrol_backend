<?php

use App\Http\Controllers\Api\AbsenceController;
use App\Http\Controllers\Api\ActivityAssignmentTimeController;
use App\Http\Controllers\Api\ActivityController;
use App\Http\Controllers\Api\AssignmentController;
use App\Http\Controllers\Api\AttendanceController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\OrganizationController;
use App\Http\Controllers\Api\OvertimeLogController;
use App\Http\Controllers\Api\PatrolPointController;
use App\Http\Controllers\Api\PatrolScanController;
use App\Http\Controllers\Api\PayrollController;
use App\Http\Controllers\Api\PostController;
use App\Http\Controllers\Api\ProjectController;
use App\Http\Controllers\Api\ProjectReportController;
use App\Http\Controllers\Api\ProjectReportExportController;
use App\Http\Controllers\Api\QrCodeController;
use App\Http\Controllers\Api\ScheduleController;
use App\Http\Controllers\Api\ShiftController;
use App\Http\Controllers\Api\TeamController;
use App\Http\Controllers\Api\UserController;
use Illuminate\Support\Facades\Route;

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
    // project by organization
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

    // Laporan dashboard (kehadiran, patrol danru, patrol pos, gabungan)
    Route::get('/projects/{project}/reports/attendance', [ProjectReportController::class, 'attendanceReport']);
    Route::get('/projects/{project}/reports/patrol-danru', [ProjectReportController::class, 'patrolDanruReport']);
    Route::get('/projects/{project}/reports/patrol-pos', [ProjectReportController::class, 'patrolPosReport']);
    Route::get('/projects/{project}/reports/all', [ProjectReportController::class, 'allReports']);

    // Unduh laporan (Excel / PDF) — filter sama seperti JSON; query `limit` opsional (max 5000)
    Route::get('/projects/{project}/reports/attendance/export/excel', [ProjectReportExportController::class, 'exportAttendanceExcel']);
    Route::get('/projects/{project}/reports/attendance/export/pdf', [ProjectReportExportController::class, 'exportAttendancePdf']);
    Route::get('/projects/{project}/reports/patrol-danru/export/excel', [ProjectReportExportController::class, 'exportPatrolDanruExcel']);
    Route::get('/projects/{project}/reports/patrol-danru/export/pdf', [ProjectReportExportController::class, 'exportPatrolDanruPdf']);
    Route::get('/projects/{project}/reports/patrol-pos/export/excel', [ProjectReportExportController::class, 'exportPatrolPosExcel']);
    Route::get('/projects/{project}/reports/patrol-pos/export/pdf', [ProjectReportExportController::class, 'exportPatrolPosPdf']);
    Route::get('/projects/{project}/reports/all/export/excel', [ProjectReportExportController::class, 'exportAllExcel']);
    Route::get('/projects/{project}/reports/all/export/pdf', [ProjectReportExportController::class, 'exportAllPdf']);
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
    Route::post('/posts/{post}/patrol-points/regenerate-qr', [PostController::class, 'regenerateQrForPost']);
    Route::put('/posts/{post}', [PostController::class, 'update']);
    Route::delete('/posts/{post}', [PostController::class, 'destroy']);

});

// patrol point routes
Route::middleware('auth:sanctum')->group(function () {
    Route::get('/posts/{post}/patrol-points', [PatrolPointController::class, 'indexByPost']);
    Route::post('/posts/{post}/patrol-points', [PatrolPointController::class, 'store']);
    Route::get('/patrol-points/{patrolPoint}', [PatrolPointController::class, 'show']);
    Route::put('/patrol-points/{patrolPoint}', [PatrolPointController::class, 'update']);
    Route::delete('/patrol-points/{patrolPoint}', [PatrolPointController::class, 'destroy']);
});

// activity routes
Route::middleware('auth:sanctum')->group(function () {
    // All activities grouped by post & assignment
    Route::get('/activities/schedule', [ActivityController::class, 'schedule']);
    Route::get('/activities', [ActivityController::class, 'getAll']); // get all activity assignment time, with optional filter by project, post, assignment, user
    Route::get('/activities/indexactivity', [ActivityController::class, 'indexactivity']); // Khusus dev: lihat semua activity yang sudah dibuat

    // Route::get('/posts/{post}/activities', [ActivityController::class, 'index']);
    Route::get('/activities/filter', [ActivityController::class, 'index']);
    Route::get('/posts/{post}/activities/schedule', [ActivityController::class, 'scheduleByPost']);
    // Route::post('/posts/{post}/activities', [ActivityController::class, 'store']);
    Route::post('/posts/activities', [ActivityController::class, 'store']);
    // Route::put('/posts/{post}/activities', [ActivityController::class, 'update']);
    Route::put('/posts/activities/put', [ActivityController::class, 'update']);
    Route::put('/activities/{activity}', [ActivityController::class, 'updateActivity']);
    // Route::delete('/activities/{activity}', [ActivityController::class, 'destroy']);
    Route::delete('/activities/delete', [ActivityController::class, 'delete']);
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

// payroll routes
Route::middleware('auth:sanctum')->group(function () {
    Route::get('/projects/{project}/payroll/sheet', [PayrollController::class, 'sheet']);
    Route::get('/projects/{project}/payroll/export', [PayrollController::class, 'downloadSheet']);
    Route::post('/projects/{project}/payroll/release', [PayrollController::class, 'release']);
    Route::post('/projects/{project}/payroll/recalculate', [PayrollController::class, 'recalculate']);
    Route::post('/projects/{project}/payroll/templates', [PayrollController::class, 'upsertTemplates']);

    Route::get('/my/payroll/history', [PayrollController::class, 'myHistory']);
    Route::get('/my/payroll/{month}', [PayrollController::class, 'myDetail']);
    Route::get('/my/payroll/{month}/download', [PayrollController::class, 'mySlipDownload']);
});

// Attendance routes
Route::middleware('auth:sanctum')->group(function () {
    // List attendances (dengan filter)
    Route::get('/attendances', [AttendanceController::class, 'index']);

    // ` check-in/patrol per assignment aktif (project user)
    Route::get('/attendances/progress', [AttendanceController::class, 'progress']);

    // Validate time before check-in (tanpa foto)
    Route::post('/attendances/validate-time', [AttendanceController::class, 'validateCheckInTime']);

    // Check-in (dengan foto)
    Route::post('/attendances/check-in', [AttendanceController::class, 'checkIn']);

    // Delete check-in (jika user salah)
    Route::delete('/attendances/check-in/{attendance}', [AttendanceController::class, 'deleteCheckIn']);

    // Patrol scan (mobile post)
    Route::post('/attendances/patrol-scan', [AttendanceController::class, 'patrolScan']);

    // Check-out
    Route::post('/attendances/check-out', [AttendanceController::class, 'checkOut']);

    // Timesheet
    Route::get('/attendances/timesheet', [AttendanceController::class, 'timesheet']);

    route::get('/attendances/timesheet-three-days', [AttendanceController::class, 'timesheetThreeDays']);

    // View attendance detail
    Route::get('/attendances/{attendance}', [AttendanceController::class, 'show']);

    // Foto selfie absensi (inline, Bearer token)
    Route::get('/attendances/{attendance}/selfie-inline', [AttendanceController::class, 'inlineSelfiePhoto']);
});

// Absence routes
Route::middleware('auth:sanctum')->group(function () {
    Route::post('/absences', [AbsenceController::class, 'store']);
    Route::get('/absences', [AbsenceController::class, 'index']);
    Route::get('/absences/{absence}', [AbsenceController::class, 'show']);
    Route::patch('/absences/{absence}', [AbsenceController::class, 'update']);
    Route::delete('/absences/{absence}', [AbsenceController::class, 'destroy']);
    Route::delete('/schedules/{schedule}/absence', [AbsenceController::class, 'destroyBySchedule']);

    // Overtime (auto dari lembur hari OFF — lihat dokumentasi API check-in)
    Route::get('/overtime-logs', [OvertimeLogController::class, 'index']);
    Route::get('/overtime-logs/{overtimeLog}', [OvertimeLogController::class, 'show']);
});

// Patrol Scan routes
Route::middleware('auth:sanctum')->group(function () {
    // QR Code image (for printing / display)
    Route::get('/qr-codes/{qrCode}/image', [QrCodeController::class, 'image']);

    // Get scan progress for attendance
    Route::get('/attendance/{attendance}/patrol-scan/progress', [PatrolScanController::class, 'getProgress']);

    // Post progress detail (attendance controller): danru/admin_lapang/ho
    // By attendance_id (ambil assignment_id dari user checkin di post)
    // Route::post('/attendance/progress-post-detail', [AttendanceController::class, 'progressPostDetailByAttendance']);

    // Post progress detail by post (existing)
    Route::get('/posts/{post}/attendance/progress-detail', [AttendanceController::class, 'progressPostDetail']);

    // Post members timesheet
    Route::get('/posts/{post}/members-timesheet', [AttendanceController::class, 'postMembersTimesheet']);

    // Get extended progress detail (includes ishoma, timesheet, patrol point status)
    Route::get('/attendance/{attendance}/patrol-scan/progress-detail', [PatrolScanController::class, 'getProgressDetail']);

    // Get unscanned patrol points for attendance
    Route::get('/attendance/{attendance}/patrol-scan/unscanned', [PatrolScanController::class, 'getUnscannedPoints']);

    // Quick QR check / remaining point info (mobile)
    Route::post('/patrol-scan/check-qr', [PatrolScanController::class, 'checkQr']);

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

    // Patrol photo inline (Bearer token)
    Route::get('/patrol-scan-photo/{photoId}/inline', [PatrolScanController::class, 'inlinePhoto']);
});
