<?php

namespace App\Services;

use App\Models\Transaction;
use App\Models\User;
use Illuminate\Validation\ValidationException;

class SupervisorBalanceService
{
    public function calculate(int $userId): float
    {
        $totals = Transaction::where('user_id', $userId)
            ->selectRaw("COALESCE(SUM(CASE WHEN type = 'topup' THEN amount ELSE 0 END), 0) as money_in")
            ->selectRaw("COALESCE(SUM(CASE WHEN type = 'expense' THEN amount ELSE 0 END), 0) as money_out")
            ->first();

        return round((float) $totals->money_in - (float) $totals->money_out, 2);
    }

    public function recalculate(User $supervisor): float
    {
        $balance = $this->calculate($supervisor->id);

        $supervisor->forceFill([
            'balance' => $balance,
        ])->save();

        return $balance;
    }

    public function assertLedgerWillNotBeNegative(
        int $userId,
        ?int $excludeTransactionId = null,
        ?array $replacementTransaction = null,
        string $message = 'Operasi tidak dibenarkan kerana akan menyebabkan running balance negatif.'
    ): void {
        if (! $this->ledgerWillStayNonNegative($userId, $excludeTransactionId, $replacementTransaction)) {
            throw ValidationException::withMessages([
                'amount' => $message,
            ]);
        }
    }

    public function ledgerWillStayNonNegative(
        int $userId,
        ?int $excludeTransactionId = null,
        ?array $replacementTransaction = null
    ): bool {
        $items = Transaction::where('user_id', $userId)
            ->when($excludeTransactionId, fn ($query) => $query->where('id', '!=', $excludeTransactionId))
            ->get(['id', 'type', 'amount', 'date'])
            ->map(fn (Transaction $transaction) => [
                'id' => (int) $transaction->id,
                'type' => $transaction->type,
                'amount' => (float) $transaction->amount,
                'date' => optional($transaction->date)->toDateString(),
            ])
            ->all();

        if ($replacementTransaction !== null) {
            $items[] = [
                'id' => (int) ($replacementTransaction['id'] ?? PHP_INT_MAX),
                'type' => $replacementTransaction['type'],
                'amount' => (float) $replacementTransaction['amount'],
                'date' => $replacementTransaction['date'],
            ];
        }

        usort($items, function (array $a, array $b) {
            $dateCompare = strcmp((string) $a['date'], (string) $b['date']);

            if ($dateCompare !== 0) {
                return $dateCompare;
            }

            return ((int) $a['id']) <=> ((int) $b['id']);
        });

        $runningBalance = 0.0;

        foreach ($items as $item) {
            $amount = round((float) $item['amount'], 2);
            $runningBalance += $item['type'] === 'topup' ? $amount : -$amount;
            $runningBalance = round($runningBalance, 2);

            if ($runningBalance < 0) {
                return false;
            }
        }

        return true;
    }
}
