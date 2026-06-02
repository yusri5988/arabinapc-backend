<?php

namespace App\Http\Controllers;

use App\Jobs\ExportSupervisorExcelJob;
use App\Models\Transaction;
use App\Models\User;
use App\Services\ActivityLogService;
use App\Services\SupervisorBalanceService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
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
                'total_supervisor_out' => (clone $supervisorTransactions)->where('type', 'expense')->sum('amount'),
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
            ]);
            $log->log('topup.validation', 'success', [
                'supervisor_id' => $request->supervisor_id,
                'amount' => $request->amount,
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

                // 3b: Create transaction record
                Transaction::create([
                    'user_id' => $supervisor->id,
                    'type' => 'topup',
                    'amount' => $request->amount,
                    'payment_to' => 'Supervisor Topup',
                    'description' => 'Duit diterima daripada Admin: '.$admin->name,
                    'date' => now(),
                    'metadata' => [
                        'source' => 'admin_send_to_supervisor',
                        'sent_by_user_id' => $admin->id,
                    ],
                ]);
                $log->log('topup.create_transaction', 'success', [
                    'supervisor_id' => $supervisor->id,
                    'amount' => $request->amount,
                    'type' => 'topup',
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

    public function exportSupervisorExcel(User $supervisor)
    {
        abort_unless($supervisor->role === 'supervisor', 404);

        $jobId = (string) Str::uuid();

        Cache::put("export_result:{$jobId}", ['status' => 'processing'], 600);

        ExportSupervisorExcelJob::dispatch($supervisor->id, $jobId);

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

    private function normalizePhone(string $phone): string
    {
        return preg_replace('/[\s-]+/', '', trim($phone)) ?? '';
    }
}
