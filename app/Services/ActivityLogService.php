<?php

namespace App\Services;

use App\Models\ActivityLog;

class ActivityLogService
{
    public function log(string $action, string $status, array $context = []): ActivityLog
    {
        return ActivityLog::create([
            'user_id' => auth()->id(),
            'action' => $action,
            'status' => $status,
            'context' => $context,
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
        ]);
    }
}
