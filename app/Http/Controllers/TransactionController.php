<?php

namespace App\Http\Controllers;

use App\Models\Transaction;
use App\Models\User;
use Illuminate\Http\Request;

class TransactionController extends Controller
{
    public function index(Request $request)
    {
        $transactions = Transaction::with('user')
            ->orderBy('date', 'desc')
            ->orderBy('id', 'desc')
            ->get()
            ->map(function (Transaction $transaction) {
                return [
                    'id' => $transaction->id,
                    'type' => $transaction->type,
                    'amount' => $transaction->amount,
                    'description' => $transaction->description,
                    'site_id' => $transaction->site_id,
                    'receipt_url' => $transaction->receipt_url,
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

    public function supervisorHistory(User $supervisor)
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
                    'description' => $transaction->description,
                    'site_id' => $transaction->site_id,
                    'receipt_url' => $transaction->receipt_url,
                    'date' => optional($transaction->date)->toDateString(),
                ];
            });

        return response()->json([
            'supervisor' => [
                'id' => $supervisor->id,
                'name' => $supervisor->name,
                'email' => $supervisor->email,
                'balance' => $supervisor->balance,
            ],
            'transactions' => $transactions,
        ]);
    }
}
