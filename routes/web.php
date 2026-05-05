<?php

use Illuminate\Support\Facades\Route;

Route::fallback(function () {
    return file_get_contents(public_path('index.html'));
});
