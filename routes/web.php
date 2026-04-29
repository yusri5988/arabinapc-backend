<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return redirect('/login');
});

Route::get('/login', function () {
    $frontendUrl = config('app.frontend_url') ?: 'http://127.0.0.1:5173';

    return redirect()->away(rtrim($frontendUrl, '/') . '/login');
});
