<?php

namespace App\Http\Controllers;

use App\Services\ExpenseItemImageService;
use App\Services\ReceiptProcessingService;
use App\Services\SupervisorBalanceService;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SupervisorController extends Controller
{
    public function ledger(Request $request, SupervisorBalanceService $balanceService)
    {
        $transactions = Transaction::where('user_id', $request->user()->id)
            ->orderBy('date', 'desc')
            ->get();

        return response()->json([
            'balance' => $balanceService->calculate($request->user()->id),
            'transactions' => $transactions->map(function (Transaction $transaction) {
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
            })
        ]);
    }

    public function expense(Request $request, SupervisorBalanceService $balanceService)
    {
        $request->validate([
            'amount' => 'required|numeric|min:0.01',
            'payment_to' => 'nullable|string',
            'details' => 'required|string',
            'description' => 'required|string',
            'site_id' => 'required|string',
            'date' => 'required|date',
            'receipt_url' => 'nullable|string',
            'item_images' => 'nullable|array|max:4',
            'item_images.*.url' => 'required_with:item_images|string',
            'item_images.*.name' => 'required_with:item_images|string',
        ]);

        DB::transaction(function () use ($request, $balanceService) {
            $user = User::whereKey($request->user()->id)->lockForUpdate()->firstOrFail();

            if ($balanceService->calculate($user->id) < (float) $request->amount) {
                throw ValidationException::withMessages([
                    'amount' => 'Baki tidak mencukupi.',
                ]);
            }

            $balanceService->assertLedgerWillNotBeNegative(
                $user->id,
                null,
                [
                    'type' => 'expense',
                    'amount' => $request->amount,
                    'date' => $request->date,
                ],
                'Perbelanjaan tidak boleh direkodkan kerana akan menyebabkan running balance negatif.'
            );

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

            $balanceService->recalculate($user);
        });

        return response()->json(['message' => 'Perbelanjaan berjaya direkodkan.']);
    }

    public function processItemImage(Request $request, ExpenseItemImageService $expenseItemImageService)
    {
        $request->validate([
            'item_image' => 'required|image|max:20480',
            'site_id' => 'required|string',
        ]);

        $imageData = $expenseItemImageService->upload(
            $request->file('item_image'),
            $request->input('site_id')
        );

        return response()->json($imageData->toArray());
    }

    public function processReceipt(Request $request, ReceiptProcessingService $receiptProcessingService)
    {
        $request->validate([
            'receipt' => 'required|image|max:20480',
            'site_id' => 'required|string',
        ]);

        $receiptData = $receiptProcessingService->process(
            $request->file('receipt'),
            $request->input('site_id')
        );

        return response()->json($receiptData->toArray());
    }
}
