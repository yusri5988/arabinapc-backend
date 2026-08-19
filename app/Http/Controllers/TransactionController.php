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
        $baseQuery = Transaction::whereHas('user', function ($query) {
                $query->where('role', 'supervisor');
            })
            ->when($request->filled('user_id'), function ($query) use ($request) {
                $query->where('user_id', $request->input('user_id'));
            });

        $totalAmount = (float) (clone $baseQuery)->sum('amount');
        $perPage = max(1, min(100, (int) $request->input('per_page', 20)));

        $paginator = (clone $baseQuery)
            ->with('user')
            ->orderBy('date', 'desc')
            ->orderBy('id', 'desc')
            ->paginate($perPage);

        $transactions = collect($paginator->items())->map(function (Transaction $transaction) {
            return [
                'id' => $transaction->id,
                'type' => $transaction->type,
                'amount' => $transaction->amount,
                'money_in' => $transaction->type === 'topup' ? $transaction->amount : 0,
                'money_out' => in_array($transaction->type, ['expense', 'return_to_admin'], true) ? $transaction->amount : 0,
                'payment_to' => $transaction->payment_to,
                'details' => $transaction->details,
                'description' => $transaction->description,
                'site_id' => $transaction->site_id,
                'receipt_url' => $transaction->receipt_url,
                'metadata' => $transaction->metadata,
                'date' => optional($transaction->date)->toDateString(),
                'created_at' => optional($transaction->created_at)->toISOString(),
                'user' => [
                    'id' => $transaction->user?->id,
                    'name' => $transaction->user?->name,
                    'role' => $transaction->user?->role,
                    'department' => $transaction->user?->department,
                ],
            ];
        });

        return response()->json([
            'transactions' => $transactions,
            'total_amount' => $totalAmount,
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'has_more' => $paginator->hasMorePages(),
            ],
        ]);
    }

    public function supervisorHistory(Request $request, User $supervisor, SupervisorBalanceService $balanceService)
    {
        abort_unless($supervisor->role === 'supervisor', 404);

        $baseQuery = Transaction::where('user_id', $supervisor->id);
        $perPage = max(1, min(100, (int) $request->input('per_page', 20)));

        $paginator = (clone $baseQuery)
            ->with('user')
            ->orderBy('date', 'desc')
            ->orderBy('id', 'desc')
            ->paginate($perPage);

        $transactions = collect($paginator->items())->map(function (Transaction $transaction) {
            return [
                'id' => $transaction->id,
                'type' => $transaction->type,
                'amount' => $transaction->amount,
                'money_in' => $transaction->type === 'topup' ? $transaction->amount : 0,
                'money_out' => in_array($transaction->type, ['expense', 'return_to_admin'], true) ? $transaction->amount : 0,
                'payment_to' => $transaction->payment_to,
                'details' => $transaction->details,
                'description' => $transaction->description,
                'site_id' => $transaction->site_id,
                'receipt_url' => $transaction->receipt_url,
                'metadata' => $transaction->metadata,
                'date' => optional($transaction->date)->toDateString(),
                'created_at' => optional($transaction->created_at)->toISOString(),
            ];
        });

        return response()->json([
            'supervisor' => [
                'id' => $supervisor->id,
                'name' => $supervisor->name,
                'phone' => $supervisor->phone,
                'department' => $supervisor->department,
                'balance' => $balanceService->calculate($supervisor->id),
            ],
            'transactions' => $transactions,
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'has_more' => $paginator->hasMorePages(),
            ],
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
            'receipt_url' => ['required_if:type,expense', 'nullable', 'string'],
            'item_images' => ['nullable', 'array', 'max:4'],
            'item_images.*.url' => ['required_with:item_images', 'string'],
            'item_images.*.name' => ['required_with:item_images', 'string'],
            'date' => ['required', 'date'],
        ]);

        $transaction = DB::transaction(function () use ($request, $validated, $balanceService) {
            $admin = $request->user();
            $supervisor = User::whereKey($validated['supervisor_id'])->lockForUpdate()->firstOrFail();

            $transaction = Transaction::create([
                'user_id' => $supervisor->id,
                'type' => $validated['type'],
                'amount' => $validated['amount'],
                'payment_to' => $validated['payment_to'] ?? ($validated['type'] === 'topup' ? 'Supervisor Topup' : null),
                'details' => $validated['details'] ?? null,
                'description' => $validated['description'] ?? ($validated['type'] === 'topup' ? 'Duit diterima daripada Admin: '.$admin->name : null),
                'site_id' => $validated['site_id'] ?? null,
                'receipt_url' => $validated['receipt_url'] ?? null,
                'date' => $validated['date'],
                'metadata' => [
                    'source' => 'admin_transaction_crud',
                    'created_by_user_id' => $admin->id,
                    'item_images' => $validated['item_images'] ?? [],
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

        if ($transaction->type === 'return_to_admin') {
            abort(403, 'Receive-back transactions cannot be edited.');
        }

        $validated = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01'],
            'payment_to' => ['nullable', 'string', 'max:255'],
            'details' => [$transaction->type === 'expense' ? 'required' : 'nullable', 'nullable', 'string', 'max:255'],
            'description' => [$transaction->type === 'expense' ? 'required' : 'nullable', 'nullable', 'string'],
            'site_id' => [$transaction->type === 'expense' ? 'required' : 'nullable', 'nullable', 'string', 'max:255'],
            'receipt_url' => ['nullable', 'string'],
            'item_images' => ['nullable', 'array', 'max:4'],
            'item_images.*.url' => ['required_with:item_images', 'string'],
            'item_images.*.name' => ['required_with:item_images', 'string'],
            'date' => ['required', 'date'],
        ]);

        $transaction = DB::transaction(function () use ($transaction, $validated, $balanceService) {
            $transaction = Transaction::whereKey($transaction->id)->lockForUpdate()->firstOrFail();
            $supervisor = User::whereKey($transaction->user_id)->lockForUpdate()->firstOrFail();

            $transaction->update([
                'amount' => $validated['amount'],
                'payment_to' => $validated['payment_to'] ?? null,
                'details' => $validated['details'] ?? null,
                'description' => $validated['description'] ?? null,
                'site_id' => $validated['site_id'] ?? null,
                'date' => $validated['date'],
            ]);

            if (array_key_exists('receipt_url', $validated)) {
                $transaction->receipt_url = $validated['receipt_url'];
            }

            if (array_key_exists('item_images', $validated)) {
                $metadata = $transaction->metadata ?? [];
                $metadata['item_images'] = $validated['item_images'];
                $transaction->metadata = $metadata;
            }

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

        if ($transaction->type === 'return_to_admin') {
            abort(403, 'Receive-back transactions cannot be deleted.');
        }

        $supervisorId = $transaction->user_id;

        DB::transaction(function () use ($transaction, $balanceService, $supervisorId) {
            $transaction = Transaction::whereKey($transaction->id)->lockForUpdate()->firstOrFail();
            $supervisor = User::whereKey($transaction->user_id)->lockForUpdate()->firstOrFail();

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
            'money_out' => in_array($transaction->type, ['expense', 'return_to_admin'], true) ? $transaction->amount : 0,
            'payment_to' => $transaction->payment_to,
            'details' => $transaction->details,
            'description' => $transaction->description,
            'site_id' => $transaction->site_id,
            'receipt_url' => $transaction->receipt_url,
            'metadata' => $transaction->metadata,
            'date' => optional($transaction->date)->toDateString(),
            'created_at' => optional($transaction->created_at)->toISOString(),
            'user' => [
                'id' => $transaction->user?->id,
                'name' => $transaction->user?->name,
                'role' => $transaction->user?->role,
                'department' => $transaction->user?->department,
            ],
        ];
    }
}
