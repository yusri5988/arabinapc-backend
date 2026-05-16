<?php

namespace App\Http\Controllers;

use App\Models\Transaction;
use App\Models\User;
use App\Services\SupervisorBalanceService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class TransactionController extends Controller
{
    public function index(Request $request)
    {
        $transactions = Transaction::with('user')
            ->whereHas('user', function ($query) {
                $query->where('role', 'supervisor');
            })
            ->orderBy('date', 'desc')
            ->orderBy('id', 'desc')
            ->get()
            ->map(function (Transaction $transaction) {
                return [
                    'id' => $transaction->id,
                    'type' => $transaction->type,
                    'amount' => $transaction->amount,
                    'money_in' => $transaction->type === 'topup' ? $transaction->amount : 0,
                    'money_out' => $transaction->type === 'expense' ? $transaction->amount : 0,
                    'payment_to' => $transaction->payment_to,
                    'description' => $transaction->description,
                    'site_id' => $transaction->site_id,
                    'receipt_url' => $transaction->receipt_url,
                    'metadata' => $transaction->metadata,
                    'date' => optional($transaction->date)->toDateString(),
                    'user' => [
                        'id' => $transaction->user?->id,
                        'name' => $transaction->user?->name,
                        'role' => $transaction->user?->role,
                    ],
                ];
            });

        return response()->json([
            'transactions' => $transactions,
        ]);
    }

    public function supervisorHistory(User $supervisor, SupervisorBalanceService $balanceService)
    {
        abort_unless($supervisor->role === 'supervisor', 404);

        $transactions = Transaction::with('user')
            ->where('user_id', $supervisor->id)
            ->orderBy('date', 'desc')
            ->orderBy('id', 'desc')
            ->get()
            ->map(function (Transaction $transaction) {
                return [
                    'id' => $transaction->id,
                    'type' => $transaction->type,
                    'amount' => $transaction->amount,
                    'money_in' => $transaction->type === 'topup' ? $transaction->amount : 0,
                    'money_out' => $transaction->type === 'expense' ? $transaction->amount : 0,
                    'payment_to' => $transaction->payment_to,
                    'description' => $transaction->description,
                    'site_id' => $transaction->site_id,
                    'receipt_url' => $transaction->receipt_url,
                    'metadata' => $transaction->metadata,
                    'date' => optional($transaction->date)->toDateString(),
                ];
            });

        return response()->json([
            'supervisor' => [
                'id' => $supervisor->id,
                'name' => $supervisor->name,
                'phone' => $supervisor->phone,
                'balance' => $balanceService->calculate($supervisor->id),
            ],
            'transactions' => $transactions,
        ]);
    }

    public function store(Request $request, SupervisorBalanceService $balanceService)
    {
        $validated = $request->validate([
            'supervisor_id' => [
                'required',
                Rule::exists('users', 'id')->where('role', 'supervisor'),
            ],
            'type' => ['required', Rule::in(['topup', 'expense'])],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'payment_to' => ['nullable', 'string', 'max:255'],
            'details' => ['required_if:type,expense', 'nullable', 'string', 'max:255'],
            'description' => ['required_if:type,expense', 'nullable', 'string'],
            'site_id' => ['required_if:type,expense', 'nullable', 'string', 'max:255'],
            'date' => ['required', 'date'],
        ]);

        $transaction = DB::transaction(function () use ($request, $validated, $balanceService) {
            $admin = $request->user();
            $supervisor = User::whereKey($validated['supervisor_id'])->lockForUpdate()->firstOrFail();

            $balanceService->assertLedgerWillNotBeNegative(
                $supervisor->id,
                null,
                [
                    'type' => $validated['type'],
                    'amount' => $validated['amount'],
                    'date' => $validated['date'],
                ],
                'Transaction tidak boleh dicipta kerana akan menyebabkan running balance negatif.'
            );

            $transaction = Transaction::create([
                'user_id' => $supervisor->id,
                'type' => $validated['type'],
                'amount' => $validated['amount'],
                'payment_to' => $validated['payment_to'] ?? ($validated['type'] === 'topup' ? 'Supervisor Topup' : null),
                'details' => $validated['details'] ?? null,
                'description' => $validated['description'] ?? ($validated['type'] === 'topup' ? 'Duit diterima daripada Admin: '.$admin->name : null),
                'site_id' => $validated['site_id'] ?? null,
                'date' => $validated['date'],
                'metadata' => [
                    'source' => 'admin_transaction_crud',
                    'created_by_user_id' => $admin->id,
                ],
            ]);

            $balanceService->recalculate($supervisor);

            return $transaction->fresh('user');
        });

        Cache::forget('ui:admin:dashboard');
        Cache::forget("ui:supervisor:ledger:{$transaction->user_id}");

        return response()->json([
            'message' => 'Transaction berjaya dicipta.',
            'transaction' => $this->formatTransaction($transaction),
        ], 201);
    }

    public function update(Request $request, Transaction $transaction, SupervisorBalanceService $balanceService)
    {
        abort_unless($transaction->user?->role === 'supervisor', 404);

        $validated = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01'],
            'payment_to' => ['nullable', 'string', 'max:255'],
            'details' => [$transaction->type === 'expense' ? 'required' : 'nullable', 'nullable', 'string', 'max:255'],
            'description' => [$transaction->type === 'expense' ? 'required' : 'nullable', 'nullable', 'string'],
            'site_id' => [$transaction->type === 'expense' ? 'required' : 'nullable', 'nullable', 'string', 'max:255'],
            'date' => ['required', 'date'],
        ]);

        $transaction = DB::transaction(function () use ($transaction, $validated, $balanceService) {
            $transaction = Transaction::whereKey($transaction->id)->lockForUpdate()->firstOrFail();
            $supervisor = User::whereKey($transaction->user_id)->lockForUpdate()->firstOrFail();

            $balanceService->assertLedgerWillNotBeNegative(
                $supervisor->id,
                $transaction->id,
                [
                    'id' => $transaction->id,
                    'type' => $transaction->type,
                    'amount' => $validated['amount'],
                    'date' => $validated['date'],
                ],
                'Transaction tidak boleh dikemaskini kerana akan menyebabkan running balance negatif.'
            );

            $transaction->update([
                'amount' => $validated['amount'],
                'payment_to' => $validated['payment_to'] ?? null,
                'details' => $validated['details'] ?? null,
                'description' => $validated['description'] ?? null,
                'site_id' => $validated['site_id'] ?? null,
                'date' => $validated['date'],
            ]);

            $balanceService->recalculate($supervisor);

            return $transaction->fresh('user');
        });

        Cache::forget('ui:admin:dashboard');
        Cache::forget("ui:supervisor:ledger:{$transaction->user_id}");

        return response()->json([
            'message' => 'Transaction berjaya dikemaskini.',
            'transaction' => $this->formatTransaction($transaction),
        ]);
    }

    public function destroy(Transaction $transaction, SupervisorBalanceService $balanceService)
    {
        abort_unless($transaction->user?->role === 'supervisor', 404);

        DB::transaction(function () use ($transaction, $balanceService) {
            $transaction = Transaction::whereKey($transaction->id)->lockForUpdate()->firstOrFail();
            $supervisor = User::whereKey($transaction->user_id)->lockForUpdate()->firstOrFail();

            $balanceService->assertLedgerWillNotBeNegative(
                $supervisor->id,
                $transaction->id,
                null,
                'Transaction tidak boleh dipadam kerana akan menyebabkan running balance negatif dalam ledger/Excel.'
            );

            $supervisorId = $transaction->user_id;
            $transaction->delete();
            $balanceService->recalculate($supervisor);
        });

        Cache::forget('ui:admin:dashboard');
        Cache::forget("ui:supervisor:ledger:{$supervisorId}");

        return response()->json([
            'message' => 'Transaction berjaya dipadam.',
        ]);
    }

    private function formatTransaction(Transaction $transaction): array
    {
        $transaction->loadMissing('user');

        return [
            'id' => $transaction->id,
            'type' => $transaction->type,
            'amount' => $transaction->amount,
            'money_in' => $transaction->type === 'topup' ? $transaction->amount : 0,
            'money_out' => $transaction->type === 'expense' ? $transaction->amount : 0,
            'payment_to' => $transaction->payment_to,
            'details' => $transaction->details,
            'description' => $transaction->description,
            'site_id' => $transaction->site_id,
            'receipt_url' => $transaction->receipt_url,
            'metadata' => $transaction->metadata,
            'date' => optional($transaction->date)->toDateString(),
            'user' => [
                'id' => $transaction->user?->id,
                'name' => $transaction->user?->name,
                'role' => $transaction->user?->role,
            ],
        ];
    }
}
