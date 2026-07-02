<?php

namespace App\Services;

use App\Models\Transaction;
use App\Models\User;
class SupervisorBalanceService
{
    public function calculate(int $userId): float
    {
        $totals = Transaction::where('user_id', $userId)
            ->selectRaw("COALESCE(SUM(CASE WHEN type = 'topup' THEN amount ELSE 0 END), 0) as money_in")
            ->selectRaw("COALESCE(SUM(CASE WHEN type IN ('expense', 'return_to_admin') THEN amount ELSE 0 END), 0) as money_out")
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


}
