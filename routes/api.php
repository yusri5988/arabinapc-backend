<?php

use App\Http\Controllers\AdminController;
use App\Http\Controllers\AdminMediaController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\DeveloperController;
use App\Http\Controllers\SupervisorController;
use App\Http\Controllers\TransactionController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;

// Public routes
Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:5,1');

// Topup route with full logging wrapper (runs before auth)
Route::post('/admin/topup', [AdminController::class, 'topup'])
    ->middleware(['log.topup', 'auth:sanctum', 'can:admin']);

// Protected routes
Route::middleware('auth:sanctum')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/user', function (Request $request) {
        $userId = $request->user()->id;

        return Cache::remember("ui:user:{$userId}", 600, function () use ($request) {
            return $request->user();
        });
    });
    Route::put('/profile', [AuthController::class, 'updateProfile']);
    Route::put('/password', [AuthController::class, 'updatePassword']);

    // Admin only routes
    Route::middleware('can:admin')->group(function () {
        Route::get('/admin/dashboard', [AdminController::class, 'dashboard']);
        Route::get('/admin/supervisors', [AdminController::class, 'listSupervisors']);
        Route::get('/admin/transactions', [TransactionController::class, 'index']);
        Route::post('/admin/transactions', [TransactionController::class, 'store']);
        Route::put('/admin/transactions/{transaction}', [TransactionController::class, 'update']);
        Route::delete('/admin/transactions/{transaction}', [TransactionController::class, 'destroy']);
        Route::get('/admin/supervisors/{supervisor}/transactions', [TransactionController::class, 'supervisorHistory']);
        Route::post('/admin/transactions/export-excel', [AdminController::class, 'exportAllTransactions']);
        Route::get('/admin/transactions/consolidated-report', [AdminController::class, 'consolidatedReportData']);
        Route::post('/admin/transactions/consolidated-report/export', [AdminController::class, 'exportConsolidatedReport']);
        Route::post('/admin/supervisors/{supervisor}/export-excel', [AdminController::class, 'exportSupervisorExcel']);
        Route::get('/admin/export-status/{jobId}', [AdminController::class, 'exportStatus']);
        Route::get('/admin/export-download/{jobId}', [AdminController::class, 'exportDownload']);
        Route::post('/admin/supervisors', [AdminController::class, 'createSupervisor']);
        Route::post('/admin/supervisors/{supervisor}/reset-password', [AdminController::class, 'resetStaffPassword']);
        Route::post('/admin/supervisors/{supervisor}/receive-back', [AdminController::class, 'receiveBack']);
        Route::post('/admin/process-receipt', [AdminMediaController::class, 'processReceipt']);
        Route::post('/admin/process-item-image', [AdminMediaController::class, 'processItemImage']);
        Route::get('/admin/receipt-status/{jobId}', [AdminMediaController::class, 'receiptStatus']);
        Route::delete('/admin/cache/clear', function () {
            Cache::flush();

            return response()->json(['message' => 'Cache berjaya dikosongkan.']);
        });
    });

    // Supervisor only routes
    Route::middleware('can:supervisor')->group(function () {
        Route::get('/supervisor/ledger', [SupervisorController::class, 'ledger']);
        Route::post('/supervisor/expense', [SupervisorController::class, 'expense']);
        Route::post('/supervisor/process-item-image', [SupervisorController::class, 'processItemImage']);
        Route::post('/supervisor/process-receipt', [SupervisorController::class, 'processReceipt']);
        Route::get('/supervisor/receipt-status/{jobId}', [SupervisorController::class, 'receiptStatus']);
    });

    // Developer only routes (read-only)
    Route::middleware('can:developer')->group(function () {
        Route::get('/developer/dashboard', [DeveloperController::class, 'dashboard']);
        Route::get('/developer/activity-logs', [DeveloperController::class, 'activityLogs']);
    });
});
