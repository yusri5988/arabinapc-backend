<?php

namespace App\Http\Controllers;

use App\Models\Transaction;
use App\Models\User;
use App\Services\ActivityLogService;
use App\Services\ExcelExportService;
use App\Services\SupervisorBalanceService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class AdminController extends Controller
{
    public function dashboard(SupervisorBalanceService $balanceService)
    {
        return Cache::remember('ui:admin:dashboard', 120, function () use ($balanceService) {
            $supervisors = User::where('role', 'supervisor')->get();
            $supervisors->each(fn (User $supervisor) => $supervisor->balance = $balanceService->calculate($supervisor->id));
            $supervisor_total = $supervisors->sum('balance');
            $supervisorTransactions = Transaction::whereHas('user', function ($query) {
                $query->where('role', 'supervisor');
            });

            return [
                'supervisors' => $supervisors,
                'total_supervisor_cash' => $supervisor_total,
                'total_supervisor_in' => (clone $supervisorTransactions)->where('type', 'topup')->sum('amount'),
                'total_supervisor_out' => (clone $supervisorTransactions)->whereIn('type', ['expense', 'return_to_admin'])->sum('amount'),
            ];
        });
    }

    public function listSupervisors(SupervisorBalanceService $balanceService, ActivityLogService $log)
    {
        try {
            $supervisors = User::where('role', 'supervisor')->get();
            $supervisors->each(fn (User $supervisor) => $supervisor->balance = $balanceService->calculate($supervisor->id));

            $log->log('supervisors.fetch', 'success', [
                'count' => $supervisors->count(),
                'supervisor_ids' => $supervisors->pluck('id')->toArray(),
            ]);

            return ['supervisors' => $supervisors];
        } catch (\Exception $e) {
            $log->log('supervisors.fetch', 'fail', [
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    public function createSupervisor(Request $request)
    {
        $request->merge([
            'phone' => $this->normalizePhone((string) $request->input('phone', '')),
        ]);

        $request->validate([
            'name' => ['required', 'string'],
            'phone' => ['required', 'string', 'regex:/^\+?[0-9]{8,15}$/', 'unique:users,phone'],
            'password' => ['required', 'min:6'],
            'department' => ['nullable', Rule::in(['Site', 'Human Resource', 'Sales Manager'])],
        ], [
            'phone.regex' => 'No telefon tidak sah.',
            'department.in' => 'Department tidak sah.',
        ]);

        $user = User::create([
            'name' => $request->name,
            'phone' => $request->phone,
            'password' => bcrypt($request->password),
            'role' => 'supervisor',
            'department' => $request->department ?? 'Site',
            'balance' => 0,
        ]);

        Cache::forget('ui:admin:supervisors');
        Cache::forget('ui:admin:dashboard');

        return response()->json($user, 201);
    }

    public function resetStaffPassword(User $supervisor)
    {
        abort_unless($supervisor->role === 'supervisor', 404);

        $supervisor->update([
            'password' => Hash::make('123456'),
        ]);

        Cache::forget("ui:user:{$supervisor->id}");

        return response()->json([
            'message' => 'Password staff berjaya direset kepada 123456.',
            'supervisor' => [
                'id' => $supervisor->id,
                'name' => $supervisor->name,
                'phone' => $supervisor->phone,
                'department' => $supervisor->department,
            ],
        ]);
    }

    public function topup(Request $request, SupervisorBalanceService $balanceService, ActivityLogService $log)
    {
        // Step 1: Validation
        try {
            $request->validate([
                'supervisor_id' => [
                    'required',
                    Rule::exists('users', 'id')->where('role', 'supervisor'),
                ],
                'amount' => 'required|numeric|min:0.01',
                'remark' => 'nullable|string|max:500',
                'details' => 'nullable|string|max:500',
            ]);
            $log->log('topup.validation', 'success', [
                'supervisor_id' => $request->supervisor_id,
                'amount' => $request->amount,
                'remark' => $request->remark ?? $request->details,
            ]);
        } catch (\Exception $e) {
            $log->log('topup.validation', 'fail', [
                'supervisor_id' => $request->supervisor_id,
                'amount' => $request->amount,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }

        // Step 2: Fetch users
        try {
            $admin = $request->user();
            $supervisor = User::findOrFail($request->supervisor_id);
            $log->log('topup.fetch_users', 'success', [
                'admin_id' => $admin->id,
                'supervisor_id' => $supervisor->id,
                'supervisor_name' => $supervisor->name,
            ]);
        } catch (\Exception $e) {
            $log->log('topup.fetch_users', 'fail', [
                'supervisor_id' => $request->supervisor_id,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }

        // Step 3: DB Transaction (lock + create transaction + recalculate balance)
        $previousBalance = $supervisor->balance;
        try {
            $balance = DB::transaction(function () use ($admin, $supervisor, $request, $balanceService, $log, $previousBalance) {
                // 3a: Pessimistic lock
                $supervisor = User::whereKey($supervisor->id)->lockForUpdate()->firstOrFail();
                $log->log('topup.lock_supervisor', 'success', [
                    'supervisor_id' => $supervisor->id,
                    'supervisor_name' => $supervisor->name,
                ]);

                $remark = $request->filled('remark') ? trim((string) $request->input('remark')) : ($request->filled('details') ? trim((string) $request->input('details')) : null);
                $description = 'Duit diterima daripada Admin: '.$admin->name;
                if ($remark) {
                    $description .= ' ('.$remark.')';
                }

                // 3b: Create transaction record
                Transaction::create([
                    'user_id' => $supervisor->id,
                    'type' => 'topup',
                    'amount' => $request->amount,
                    'payment_to' => 'Supervisor Topup',
                    'details' => $remark,
                    'description' => $description,
                    'date' => now(),
                    'metadata' => [
                        'source' => 'admin_send_to_supervisor',
                        'sent_by_user_id' => $admin->id,
                        'remark' => $remark,
                    ],
                ]);
                $log->log('topup.create_transaction', 'success', [
                    'supervisor_id' => $supervisor->id,
                    'amount' => $request->amount,
                    'type' => 'topup',
                    'remark' => $remark,
                ]);

                // 3c: Recalculate balance
                $newBalance = $balanceService->recalculate($supervisor);
                $log->log('topup.recalculate_balance', 'success', [
                    'supervisor_id' => $supervisor->id,
                    'previous_balance' => $previousBalance,
                    'new_balance' => $newBalance,
                ]);

                return $newBalance;
            });
            $log->log('topup.transaction', 'success', [
                'supervisor_id' => $supervisor->id,
                'amount' => $request->amount,
                'new_balance' => $balance,
            ]);
        } catch (\Exception $e) {
            $log->log('topup.transaction', 'fail', [
                'supervisor_id' => $supervisor->id ?? $request->supervisor_id,
                'amount' => $request->amount,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }

        // Step 4: Cache invalidation
        try {
            Cache::forget('ui:admin:supervisors');
            Cache::forget('ui:admin:dashboard');
            Cache::forget("ui:user:{$supervisor->id}");
            Cache::forget("ui:supervisor:ledger:{$supervisor->id}");
            $log->log('topup.cache_clear', 'success', [
                'supervisor_id' => $supervisor->id,
            ]);
        } catch (\Exception $e) {
            $log->log('topup.cache_clear', 'fail', [
                'supervisor_id' => $supervisor->id,
                'error' => $e->getMessage(),
            ]);
        }

        // Step 5: Completed
        $log->log('topup.completed', 'success', [
            'admin_id' => $admin->id,
            'supervisor_id' => $supervisor->id,
            'amount' => (float) $request->amount,
            'new_balance' => $balance,
        ]);

        return response()->json([
            'message' => 'Duit berjaya dihantar kepada supervisor.',
            'balance' => $balance,
        ]);
    }

    public function receiveBack(User $supervisor, Request $request, SupervisorBalanceService $balanceService, ActivityLogService $log)
    {
        abort_unless($supervisor->role === 'supervisor', 404);

        $admin = $request->user();
        $previousBalance = null;

        try {
            $result = DB::transaction(function () use ($admin, $supervisor, $balanceService, $log, &$previousBalance) {
                $supervisor = User::whereKey($supervisor->id)->lockForUpdate()->firstOrFail();
                $previousBalance = $balanceService->calculate($supervisor->id);
                $amount = $previousBalance;

                if ($amount <= 0) {
                    abort(422, 'Staff does not have any petty cash balance to receive back.');
                }

                Transaction::create([
                    'user_id' => $supervisor->id,
                    'type' => 'return_to_admin',
                    'amount' => $amount,
                    'payment_to' => 'Admin',
                    'description' => 'Petty cash returned to Admin: '.$admin->name,
                    'date' => now(),
                    'metadata' => [
                        'source' => 'admin_receive_back',
                        'received_by_user_id' => $admin->id,
                    ],
                ]);

                $newBalance = $balanceService->recalculate($supervisor);

                $log->log('receive_back.transaction', 'success', [
                    'admin_id' => $admin->id,
                    'supervisor_id' => $supervisor->id,
                    'amount' => $amount,
                    'previous_balance' => $previousBalance,
                    'new_balance' => $newBalance,
                ]);

                return [
                    'amount' => $amount,
                    'balance' => $newBalance,
                ];
            });
        } catch (\Exception $e) {
            $log->log('receive_back.transaction', 'fail', [
                'admin_id' => $admin?->id,
                'supervisor_id' => $supervisor->id,
                'previous_balance' => $previousBalance,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }

        Cache::forget('ui:admin:supervisors');
        Cache::forget('ui:admin:dashboard');
        Cache::forget("ui:user:{$supervisor->id}");
        Cache::forget("ui:supervisor:ledger:{$supervisor->id}");

        return response()->json([
            'message' => 'Petty cash successfully received back from staff.',
            'amount' => $result['amount'],
            'balance' => $result['balance'],
        ]);
    }

    public function exportSupervisorExcel(User $supervisor, ExcelExportService $exportService)
    {
        abort_unless($supervisor->role === 'supervisor', 404);

        $jobId = (string) Str::uuid();

        Cache::put("export_result:{$jobId}", ['status' => 'processing'], 600);

        try {
            $result = $exportService->generate($supervisor->id);

            Cache::put("export_result:{$jobId}", [
                'status' => 'completed',
                'file_path' => $result['file_path'],
                'file_name' => $result['file_name'],
            ], 600);
        } catch (\Throwable $exception) {
            Log::error('Supervisor Excel export failed.', [
                'job_id' => $jobId,
                'supervisor_id' => $supervisor->id,
                'message' => $exception->getMessage(),
            ]);

            Cache::put("export_result:{$jobId}", [
                'status' => 'failed',
                'error' => 'Gagal generate fail Excel. Sila cuba lagi.',
            ], 600);
        }

        return response()->json(['job_id' => $jobId]);
    }

    public function exportStatus(string $jobId)
    {
        $result = Cache::get("export_result:{$jobId}");

        if (! $result) {
            return response()->json(['status' => 'not_found'], 404);
        }

        return response()->json($result);
    }

    public function exportDownload(string $jobId)
    {
        $result = Cache::get("export_result:{$jobId}");

        if (! $result || ($result['status'] ?? '') !== 'completed') {
            return response()->json(['message' => 'Export not ready.'], 404);
        }

        $filePath = $result['file_path'];
        $fileName = $result['file_name'];

        if (! file_exists($filePath)) {
            return response()->json(['message' => 'File not found.'], 404);
        }

        return response()->download($filePath, $fileName)->deleteFileAfterSend(true);
    }

    public function exportAllTransactions(ExcelExportService $exportService)
    {
        $jobId = (string) Str::uuid();

        Cache::put("export_result:{$jobId}", ['status' => 'processing'], 600);

        try {
            $result = $exportService->generateAll();

            Cache::put("export_result:{$jobId}", [
                'status' => 'completed',
                'file_path' => $result['file_path'],
                'file_name' => $result['file_name'],
            ], 600);
        } catch (\Throwable $exception) {
            Log::error('All transactions Excel export failed.', [
                'job_id' => $jobId,
                'message' => $exception->getMessage(),
            ]);

            Cache::put("export_result:{$jobId}", [
                'status' => 'failed',
                'error' => 'Gagal generate fail Excel. Sila cuba lagi.',
            ], 600);
        }

        return response()->json(['job_id' => $jobId]);
    }

    public function consolidatedReportData(Request $request)
    {
        $startDate = $request->input('start_date', now()->startOfMonth()->toDateString());
        $endDate = $request->input('end_date', now()->endOfMonth()->toDateString());

        $transactions = Transaction::with('user')
            ->whereHas('user', fn ($q) => $q->where('role', 'supervisor'))
            ->whereBetween('date', [$startDate, $endDate])
            ->orderBy('date')
            ->orderBy('id')
            ->get();

        $totalTopup = $transactions->where('type', 'topup')->sum('amount');
        $totalExpense = $transactions->where('type', 'expense')->sum('amount');
        $totalReturnedToAdmin = $transactions->where('type', 'return_to_admin')->sum('amount');

        $byStaff = $transactions->groupBy('user_id')->map(function ($staffTx, $userId) {
            $user = $staffTx->first()->user;
            $staffTopup = $staffTx->where('type', 'topup')->sum('amount');
            $staffExpense = $staffTx->where('type', 'expense')->sum('amount');
            $staffReturnedToAdmin = $staffTx->where('type', 'return_to_admin')->sum('amount');

            return [
                'user_id' => $userId,
                'name' => $user?->name ?? 'Unknown',
                'department' => $user?->department ?? '-',
                'total_topup' => (float) $staffTopup,
                'total_expense' => (float) $staffExpense,
                'total_returned_to_admin' => (float) $staffReturnedToAdmin,
                'balance' => (float) ($staffTopup - $staffExpense - $staffReturnedToAdmin),
                'transaction_count' => $staffTx->count(),
            ];
        })->values();

        $byDepartment = $transactions->groupBy(fn ($tx) => $tx->user?->department ?? 'Unknown')->map(function ($deptTx, $department) {
            return [
                'department' => $department,
                'total_staff' => $deptTx->pluck('user_id')->unique()->count(),
                'total_topup' => (float) $deptTx->where('type', 'topup')->sum('amount'),
                'total_expense' => (float) $deptTx->where('type', 'expense')->sum('amount'),
                'total_returned_to_admin' => (float) $deptTx->where('type', 'return_to_admin')->sum('amount'),
                'balance' => (float) ($deptTx->where('type', 'topup')->sum('amount') - $deptTx->where('type', 'expense')->sum('amount') - $deptTx->where('type', 'return_to_admin')->sum('amount')),
            ];
        })->values();

        $topExpenses = $transactions->where('type', 'expense')->sortByDesc('amount')->take(10)->map(fn ($tx) => [
            'date' => optional($tx->date)->format('d/m/Y') ?? '',
            'staff' => $tx->user?->name ?? 'Unknown',
            'payment_to' => $tx->payment_to ?? '-',
            'details' => $tx->details ?: $tx->description ?? '',
            'amount' => (float) $tx->amount,
            'site_id' => $tx->site_id ?? '',
        ])->values();

        return response()->json([
            'summary' => [
                'start_date' => $startDate,
                'end_date' => $endDate,
                'total_staff' => $transactions->pluck('user_id')->unique()->count(),
                'total_transactions' => $transactions->count(),
                'total_topup' => (float) $totalTopup,
                'total_expense' => (float) $totalExpense,
                'total_returned_to_admin' => (float) $totalReturnedToAdmin,
                'balance' => (float) ($totalTopup - $totalExpense - $totalReturnedToAdmin),
            ],
            'by_staff' => $byStaff,
            'by_department' => $byDepartment,
            'top_expenses' => $topExpenses,
        ]);
    }

    public function exportConsolidatedReport(Request $request, ExcelExportService $exportService)
    {
        $request->validate([
            'start_date' => 'required|date',
            'end_date' => 'required|date|after_or_equal:start_date',
        ]);

        $jobId = (string) Str::uuid();

        Cache::put("export_result:{$jobId}", ['status' => 'processing'], 600);

        try {
            $result = $exportService->generateConsolidated($request->start_date, $request->end_date);

            Cache::put("export_result:{$jobId}", [
                'status' => 'completed',
                'file_path' => $result['file_path'],
                'file_name' => $result['file_name'],
            ], 600);
        } catch (\Throwable $exception) {
            Log::error('Consolidated report Excel export failed.', [
                'job_id' => $jobId,
                'start_date' => $request->start_date,
                'end_date' => $request->end_date,
                'message' => $exception->getMessage(),
            ]);

            Cache::put("export_result:{$jobId}", [
                'status' => 'failed',
                'error' => 'Gagal generate consolidated report Excel. Sila cuba lagi.',
            ], 600);
        }

        return response()->json(['job_id' => $jobId]);
    }

    private function normalizePhone(string $phone): string
    {
        return preg_replace('/[\s-]+/', '', trim($phone)) ?? '';
    }
}
