<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessReceiptJob;
use App\Models\Transaction;
use App\Models\User;
use App\Services\ActivityLogService;
use App\Services\ExpenseItemImageService;
use App\Services\ReceiptProcessingService;
use App\Services\SupervisorBalanceService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SupervisorController extends Controller
{
    public function ledger(Request $request, SupervisorBalanceService $balanceService)
    {
        $transactions = Transaction::where('user_id', $request->user()->id)
            ->orderBy('date', 'desc')
            ->get();

        return response()->json([
            'balance' => $balanceService->calculate($request->user()->id),
            'department' => $request->user()->department ?? 'Site',
            'transactions' => $transactions->map(function (Transaction $transaction) {
                return [
                    'id' => $transaction->id,
                    'type' => $transaction->type,
                    'amount' => $transaction->amount,
                    'money_in' => $transaction->type === 'topup' ? $transaction->amount : 0,
                    'money_out' => in_array($transaction->type, ['expense', 'return_to_admin'], true) ? $transaction->amount : 0,
                    'payment_to' => $transaction->payment_to,
                    'description' => $transaction->description,
                    'site_id' => $transaction->site_id,
                    'receipt_url' => $transaction->receipt_url,
                    'metadata' => $transaction->metadata,
                    'date' => optional($transaction->date)->toDateString(),
                ];
            }),
        ]);
    }

    public function expense(Request $request, SupervisorBalanceService $balanceService, ActivityLogService $log)
    {
        try {
            $request->validate([
                'amount' => 'required|numeric|min:0.01',
                'payment_to' => 'nullable|string',
                'details' => 'required|string',
                'description' => 'required|string',
                'site_id' => ['required', 'string', 'max:100', 'regex:/^[A-Za-z0-9_-]+$/'],
                'date' => 'required|date',
                'receipt_url' => 'nullable|string',
                'item_images' => 'nullable|array|max:4',
                'item_images.*.url' => 'required_with:item_images|string',
                'item_images.*.name' => 'required_with:item_images|string',
            ]);
            $log->log('expense.validation', 'success', [
                'supervisor_id' => $request->user()->id,
                'amount' => $request->amount,
                'site_id' => $request->site_id,
                'details' => $request->details,
            ]);
        } catch (\Exception $e) {
            $log->log('expense.validation', 'fail', [
                'supervisor_id' => $request->user()->id,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }

        $supervisorId = $request->user()->id;
        $previousBalance = $request->user()->balance;

        try {
            DB::transaction(function () use ($request, $balanceService, $supervisorId, $log, $previousBalance) {
                $user = User::whereKey($supervisorId)->lockForUpdate()->firstOrFail();
                $log->log('expense.lock_supervisor', 'success', [
                    'supervisor_id' => $user->id,
                ]);

                Transaction::create([
                    'user_id' => $user->id,
                    'type' => 'expense',
                    'amount' => $request->amount,
                    'payment_to' => $request->payment_to,
                    'details' => $request->details,
                    'description' => $request->description,
                    'site_id' => $request->site_id,
                    'receipt_url' => $request->receipt_url,
                    'date' => $request->date,
                    'metadata' => [
                        'item_images' => $request->item_images ?? [],
                    ],
                ]);
                $log->log('expense.create_transaction', 'success', [
                    'supervisor_id' => $user->id,
                    'amount' => $request->amount,
                    'type' => 'expense',
                    'site_id' => $request->site_id,
                ]);

                $newBalance = $balanceService->recalculate($user);
                $log->log('expense.recalculate_balance', 'success', [
                    'supervisor_id' => $user->id,
                    'previous_balance' => $previousBalance,
                    'new_balance' => $newBalance,
                ]);
            });
            $log->log('expense.transaction', 'success', [
                'supervisor_id' => $supervisorId,
                'amount' => $request->amount,
            ]);
        } catch (\Exception $e) {
            $log->log('expense.transaction', 'fail', [
                'supervisor_id' => $supervisorId,
                'amount' => $request->amount,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }

        try {
            Cache::forget('ui:admin:dashboard');
            Cache::forget("ui:supervisor:ledger:{$supervisorId}");
            $log->log('expense.cache_clear', 'success', [
                'supervisor_id' => $supervisorId,
            ]);
        } catch (\Exception $e) {
            $log->log('expense.cache_clear', 'fail', [
                'supervisor_id' => $supervisorId,
                'error' => $e->getMessage(),
            ]);
        }

        $log->log('expense.completed', 'success', [
            'supervisor_id' => $supervisorId,
            'amount' => (float) $request->amount,
            'site_id' => $request->site_id,
            'details' => $request->details,
        ]);

        return response()->json(['message' => 'Perbelanjaan berjaya direkodkan.']);
    }

    public function processItemImage(Request $request, ExpenseItemImageService $expenseItemImageService, ActivityLogService $log)
    {
        try {
            $request->validate([
                'item_image' => 'required|image|max:20480',
                'site_id' => ['required', 'string', 'max:100', 'regex:/^[A-Za-z0-9_-]+$/'],
            ]);
            $log->log('item_image.validation', 'success', [
                'supervisor_id' => $request->user()->id,
                'site_id' => $request->input('site_id'),
            ]);
        } catch (\Exception $e) {
            $log->log('item_image.validation', 'fail', [
                'supervisor_id' => $request->user()->id,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }

        try {
            $imageData = $expenseItemImageService->upload(
                $request->file('item_image'),
                $request->input('site_id')
            );
            $log->log('item_image.upload', 'success', [
                'supervisor_id' => $request->user()->id,
                'site_id' => $request->input('site_id'),
                'file_name' => $imageData->fileName ?? null,
            ]);
        } catch (\Exception $e) {
            $log->log('item_image.upload', 'fail', [
                'supervisor_id' => $request->user()->id,
                'site_id' => $request->input('site_id'),
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }

        $log->log('item_image.completed', 'success', [
            'supervisor_id' => $request->user()->id,
            'site_id' => $request->input('site_id'),
        ]);

        return response()->json($imageData->toArray());
    }

    public function processReceipt(Request $request, ReceiptProcessingService $receiptProcessingService, ActivityLogService $log)
    {
        try {
            $request->validate([
                'receipt' => 'required|image|max:20480',
                'site_id' => ['required', 'string', 'max:100', 'regex:/^[A-Za-z0-9_-]+$/'],
            ]);
            $log->log('receipt.validation', 'success', [
                'supervisor_id' => $request->user()->id,
                'site_id' => $request->input('site_id'),
            ]);
        } catch (\Exception $e) {
            $log->log('receipt.validation', 'fail', [
                'supervisor_id' => $request->user()->id,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }

        try {
            $stored = $receiptProcessingService->storeReceipt(
                $request->file('receipt'),
                $request->input('site_id')
            );
            $log->log('receipt.store', 'success', [
                'supervisor_id' => $request->user()->id,
                'site_id' => $request->input('site_id'),
                'stored_path' => $stored['storedPath'],
            ]);
        } catch (\Exception $e) {
            $log->log('receipt.store', 'fail', [
                'supervisor_id' => $request->user()->id,
                'site_id' => $request->input('site_id'),
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }

        try {
            $jobId = (string) Str::uuid();

            Cache::put("job_result:{$jobId}", ['status' => 'processing'], 300);

            ProcessReceiptJob::dispatch($stored['storedPath'], $stored['receiptUrl'], $jobId);
            $log->log('receipt.job_dispatched', 'success', [
                'supervisor_id' => $request->user()->id,
                'job_id' => $jobId,
            ]);
        } catch (\Exception $e) {
            $log->log('receipt.job_dispatched', 'fail', [
                'supervisor_id' => $request->user()->id,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }

        $log->log('receipt.completed', 'success', [
            'supervisor_id' => $request->user()->id,
            'site_id' => $request->input('site_id'),
            'job_id' => $jobId,
        ]);

        return response()->json([
            'job_id' => $jobId,
            'receipt_url' => $stored['receiptUrl'],
        ]);
    }

    public function receiptStatus(string $jobId)
    {
        $result = Cache::get("job_result:{$jobId}");

        if (! $result) {
            return response()->json(['status' => 'not_found'], 404);
        }

        return response()->json($result);
    }
}
