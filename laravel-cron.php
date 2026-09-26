<?php

declare(strict_types=1);

// This file is intended for CLI cron execution only.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

// Cloudways Basic Cron can execute a PHP file but cannot pass Artisan arguments.
$_SERVER['argv'] = [__DIR__.'/artisan', 'schedule:run'];
$_SERVER['argc'] = 2;

require __DIR__.'/artisan';
