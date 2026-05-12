<?php

namespace App\Http\Controllers;

use App\Models\Transaction;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

class AdminController extends Controller
{
    public function dashboard()
    {
        $supervisors = User::where('role', 'supervisor')->get();
        $supervisor_total = $supervisors->sum('balance');
        $supervisorTransactions = Transaction::whereHas('user', function ($query) {
            $query->where('role', 'supervisor');
        });

        return response()->json([
            'supervisors' => $supervisors,
            'total_supervisor_cash' => $supervisor_total,
            'total_supervisor_in' => (clone $supervisorTransactions)->where('type', 'topup')->sum('amount'),
            'total_supervisor_out' => (clone $supervisorTransactions)->where('type', 'expense')->sum('amount'),
        ]);
    }

    public function listSupervisors()
    {
        $supervisors = User::where('role', 'supervisor')->get();

        return response()->json(['supervisors' => $supervisors]);
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
        ], [
            'phone.regex' => 'No telefon tidak sah.',
        ]);

        $user = User::create([
            'name' => $request->name,
            'phone' => $request->phone,
            'password' => bcrypt($request->password),
            'role' => 'supervisor',
            'balance' => 0,
        ]);

        return response()->json($user, 201);
    }

    public function resetStaffPassword(User $supervisor)
    {
        abort_unless($supervisor->role === 'supervisor', 404);

        $supervisor->update([
            'password' => Hash::make('123456'),
        ]);

        return response()->json([
            'message' => 'Password staff berjaya direset kepada 123456.',
            'supervisor' => [
                'id' => $supervisor->id,
                'name' => $supervisor->name,
                'phone' => $supervisor->phone,
            ],
        ]);
    }

    public function topup(Request $request)
    {
        $request->validate([
            'supervisor_id' => [
                'required',
                Rule::exists('users', 'id')->where('role', 'supervisor'),
            ],
            'amount' => 'required|numeric|min:0.01',
        ]);

        $admin = $request->user();
        $supervisor = User::findOrFail($request->supervisor_id);

        DB::transaction(function () use ($admin, $supervisor, $request) {
            $supervisor->increment('balance', $request->amount);

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
        });

        return response()->json([
            'message' => 'Duit berjaya dihantar kepada supervisor.',
            'balance' => $supervisor->fresh()->balance,
        ]);
    }

    private function normalizePhone(string $phone): string
    {
        return preg_replace('/[\s-]+/', '', trim($phone)) ?? '';
    }
}
