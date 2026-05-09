<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\GoogleDriveAuthController;

Route::get('/google/drive/link', [GoogleDriveAuthController::class, 'redirect']);
Route::get('/google-drive/callback', [GoogleDriveAuthController::class, 'callback']);

Route::fallback(function () {
    return file_get_contents(public_path('index.html'));
});
