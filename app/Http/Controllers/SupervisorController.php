<?php

namespace App\Http\Controllers;

use App\Services\ExpenseItemImageService;
use App\Services\ReceiptProcessingService;
use App\Models\Transaction;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SupervisorController extends Controller
{
    public function ledger(Request $request)
    {
        $transactions = Transaction::where('user_id', $request->user()->id)
            ->orderBy('date', 'desc')
            ->get();

        return response()->json([
            'balance' => $request->user()->balance,
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

    public function expense(Request $request)
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

        $user = $request->user();

        if ($user->balance < $request->amount) {
            return response()->json(['message' => 'Baki tidak mencukupi.'], 400);
        }

        DB::transaction(function () use ($user, $request) {
            $user->decrement('balance', $request->amount);

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
        });

        return response()->json(['message' => 'Perbelanjaan berjaya direkodkan.']);
    }

    public function processItemImage(Request $request, ExpenseItemImageService $expenseItemImageService)
    {
        $request->validate([
            'item_image' => 'required|image|max:20480',
        ]);

        $imageData = $expenseItemImageService->upload($request->file('item_image'));

        return response()->json($imageData->toArray());
    }

    public function processReceipt(Request $request, ReceiptProcessingService $receiptProcessingService)
    {
        $request->validate([
            'receipt' => 'required|image|max:20480',
        ]);

        $receiptData = $receiptProcessingService->process($request->file('receipt'));

        return response()->json($receiptData->toArray());
    }
}
