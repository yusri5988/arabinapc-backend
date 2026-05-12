<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\GoogleDriveAuthController;

Route::get('/google/drive/link', [GoogleDriveAuthController::class, 'redirect']);
Route::get('/google-drive/callback', [GoogleDriveAuthController::class, 'callback']);

Route::get('/receipts/{filename}', function ($filename) {
    $path = storage_path('app/public/receipts/' . $filename);

    if (! file_exists($path)) {
        abort(404);
    }

    return response()->file($path);
})->where('filename', '.*');

Route::get('/expense-items/{filename}', function ($filename) {
    $path = storage_path('app/public/expense-items/' . $filename);

    if (! file_exists($path)) {
        abort(404);
    }

    return response()->file($path);
})->where('filename', '.*');

Route::fallback(function () {
    return file_get_contents(public_path('index.html'));
});
