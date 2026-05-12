<?php

use App\Http\Controllers\AdminController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\TransactionController;
use App\Http\Controllers\SupervisorController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

// Public routes
Route::post('/login', [AuthController::class, 'login']);

// Protected routes
Route::middleware('auth:sanctum')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/user', function (Request $request) {
        return $request->user();
    });

    // Admin only routes
    Route::middleware('can:admin')->group(function () {
        Route::get('/admin/dashboard', [AdminController::class, 'dashboard']);
        Route::get('/admin/supervisors', [AdminController::class, 'listSupervisors']);
        Route::get('/admin/transactions', [TransactionController::class, 'index']);
        Route::get('/admin/supervisors/{supervisor}/transactions', [TransactionController::class, 'supervisorHistory']);
        Route::post('/admin/supervisors', [AdminController::class, 'createSupervisor']);
        Route::post('/admin/supervisors/{supervisor}/reset-password', [AdminController::class, 'resetStaffPassword']);
        Route::post('/admin/topup', [AdminController::class, 'topup']);
    });

    // Supervisor only routes
    Route::middleware('can:supervisor')->group(function () {
        Route::get('/supervisor/ledger', [SupervisorController::class, 'ledger']);
        Route::post('/supervisor/expense', [SupervisorController::class, 'expense']);
        Route::post('/supervisor/process-item-image', [SupervisorController::class, 'processItemImage']);
        Route::post('/supervisor/process-receipt', [SupervisorController::class, 'processReceipt']);
    });
});
