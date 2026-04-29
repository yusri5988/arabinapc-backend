<?php

namespace App\Http\Controllers;

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
            'transactions' => $transactions
        ]);
    }

    public function expense(Request $request)
    {
        $request->validate([
            'amount' => 'required|numeric|min:0.01',
            'description' => 'required|string',
            'site_id' => 'required|string',
            'date' => 'required|date',
            'receipt_url' => 'nullable|string',
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
                'description' => $request->description,
                'site_id' => $request->site_id,
                'receipt_url' => $request->receipt_url,
                'date' => $request->date,
            ]);
        });

        return response()->json(['message' => 'Perbelanjaan berjaya direkodkan.']);
    }

    public function processReceipt(Request $request, ReceiptProcessingService $receiptProcessingService)
    {
        $request->validate([
            'receipt' => 'required|image|max:5120',
        ]);

        $receiptData = $receiptProcessingService->process($request->file('receipt'));

        return response()->json($receiptData->toArray());
    }
}
