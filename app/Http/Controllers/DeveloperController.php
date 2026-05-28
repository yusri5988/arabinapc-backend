<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use Illuminate\Http\Request;

class DeveloperController extends Controller
{
    public function dashboard()
    {
        $totalLogs = ActivityLog::count();
        $successCount = ActivityLog::where('status', 'success')->count();
        $failCount = ActivityLog::where('status', 'fail')->count();

        $topupCount = ActivityLog::where('action', 'like', 'topup.%')->count();
        $expenseCount = ActivityLog::where(function ($q) {
            $q->where('action', 'like', 'item_image.%')
              ->orWhere('action', 'like', 'receipt.%')
              ->orWhere('action', 'like', 'expense.%');
        })->count();

        $recentLogs = ActivityLog::with('user')->latest()->limit(5)->get();

        return response()->json([
            'total_logs' => $totalLogs,
            'success_count' => $successCount,
            'fail_count' => $failCount,
            'topup_count' => $topupCount,
            'expense_count' => $expenseCount,
            'recent_logs' => $recentLogs,
        ]);
    }

    public function activityLogs(Request $request)
    {
        $filter = $request->query('filter');

        $query = ActivityLog::with('user');

        if ($filter === 'topup') {
            $query->where('action', 'like', 'topup.%');
        } elseif ($filter === 'expense') {
            $query->where(function ($q) {
                $q->where('action', 'like', 'item_image.%')
                  ->orWhere('action', 'like', 'receipt.%')
                  ->orWhere('action', 'like', 'expense.%');
            });
        }

        $logs = $query->latest()->paginate(20);

        return response()->json($logs);
    }
}
