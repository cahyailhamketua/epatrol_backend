<?php

// Add to routes/api.php - Payroll & Attendance Management Routes

use App\Http\Controllers\Api\AbsenceController;
use App\Http\Controllers\Api\OvertimeLogController;
use App\Http\Controllers\Api\PayrollPolicyController;
use App\Http\Controllers\Api\PayrollRunController;
use App\Http\Controllers\Api\PayrollDetailController;

Route::middleware('auth:sanctum')->group(function () {
    
    // ==================== ABSENCE MANAGEMENT ====================
    Route::prefix('absences')->group(function () {
        Route::post('/', [AbsenceController::class, 'store']);
        Route::get('/', [AbsenceController::class, 'index']);
        Route::get('/{absence}', [AbsenceController::class, 'show']);
        Route::patch('/{absence}/approve', [AbsenceController::class, 'approve']);
        Route::patch('/{absence}/reject', [AbsenceController::class, 'reject']);
        Route::delete('/{absence}', [AbsenceController::class, 'destroy']);
    });

    // ==================== OVERTIME MANAGEMENT ====================
    Route::prefix('overtime-logs')->group(function () {
        Route::post('/', [OvertimeLogController::class, 'store']);
        Route::get('/', [OvertimeLogController::class, 'index']);
        Route::get('/{overtimeLog}', [OvertimeLogController::class, 'show']);
        Route::patch('/{overtimeLog}/approve', [OvertimeLogController::class, 'approve']);
        Route::patch('/{overtimeLog}/reject', [OvertimeLogController::class, 'reject']);
        Route::patch('/{overtimeLog}/complete', [OvertimeLogController::class, 'complete']);
        Route::delete('/{overtimeLog}', [OvertimeLogController::class, 'destroy']);
    });

    // ==================== PAYROLL POLICY MANAGEMENT ====================
    Route::prefix('payroll-policies')->group(function () {
        Route::post('/', [PayrollPolicyController::class, 'store']);
        Route::get('/', [PayrollPolicyController::class, 'index']);
        Route::get('/{payrollPolicy}', [PayrollPolicyController::class, 'show']);
        Route::patch('/{payrollPolicy}', [PayrollPolicyController::class, 'update']);
        Route::delete('/{payrollPolicy}', [PayrollPolicyController::class, 'destroy']);
    });

    // ==================== PAYROLL RUN MANAGEMENT ====================
    Route::prefix('payroll-runs')->group(function () {
        Route::post('/', [PayrollRunController::class, 'store']);
        Route::get('/', [PayrollRunController::class, 'index']);
        Route::get('/{payrollRun}', [PayrollRunController::class, 'show']);
        Route::get('/{payrollRun}/calculate', [PayrollRunController::class, 'calculate']);
        Route::patch('/{payrollRun}/finalize', [PayrollRunController::class, 'finalize']);
        Route::patch('/{payrollRun}/mark-paid', [PayrollRunController::class, 'markPaid']);
        Route::patch('/{payrollRun}/cancel', [PayrollRunController::class, 'cancel']);
        Route::patch('/{payrollRun}/recalculate', [PayrollRunController::class, 'recalculate']);
    });

    // ==================== PAYROLL DETAIL MANAGEMENT ====================
    Route::prefix('payroll-details')->group(function () {
        Route::get('/', [PayrollDetailController::class, 'index']);
        Route::get('/{payrollDetail}', [PayrollDetailController::class, 'show']);
        Route::get('/{payrollDetail}/export', [PayrollDetailController::class, 'export']);
    });
});
