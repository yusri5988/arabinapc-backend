<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);

use App\Models\User;

$user = User::where('phone', '0183723744')->first();
echo "User: {$user->name} ({$user->role})\n";

$token = $user->createToken('test')->plainTextToken;
echo "Token: {$token}\n\n";

// Test request tools
$request = Illuminate\Http\Request::create('/api/developer/dashboard', 'GET');
$request->headers->set('Authorization', "Bearer {$token}");

echo "Developer API endpoints ready.\n";
echo "Login credentials: 0183723744 / 123456\n";
echo "Login at: http://127.0.0.1:8000/login\n";
echo "Dashboard: /developer/dashboard\n";
echo "Activity Logs: /developer/activity-logs\n";

unlink(__FILE__);
