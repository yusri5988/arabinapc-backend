<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\Transaction;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AdminController extends Controller
{
    public function dashboard()
    {
        $supervisors = User::where('role', 'supervisor')->get();
        $total_balance = User::where('role', 'admin')->sum('balance');
        $supervisor_total = $supervisors->sum('balance');

        return response()->json([
            'supervisors' => $supervisors,
            'total_admin_cash' => $total_balance,
            'total_supervisor_cash' => $supervisor_total,
        ]);
    }

    public function listSupervisors()
    {
        $supervisors = User::where('role', 'supervisor')->get();
        return response()->json(['supervisors' => $supervisors]);
    }

    public function createSupervisor(Request $request)
    {
        $request->validate([
            'name' => 'required|string',
            'email' => 'required|email|unique:users',
            'password' => 'required|min:6',
        ]);

        $user = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'password' => bcrypt($request->password),
            'role' => 'supervisor',
            'balance' => 0,
        ]);

        return response()->json($user, 201);
    }

    public function topupMainCash(Request $request)
    {
        $request->validate([
            'amount' => 'required|numeric|min:0.01',
            'description' => 'nullable|string|max:255',
        ]);

        $admin = $request->user();

        DB::transaction(function () use ($admin, $request) {
            $admin->increment('balance', $request->amount);

            Transaction::create([
                'user_id' => $admin->id,
                'type' => 'topup',
                'amount' => $request->amount,
                'description' => $request->description ?: 'Top up baki tunai utama',
                'date' => now(),
                'metadata' => [
                    'source' => 'main_cash_topup',
                ],
            ]);
        });

        return response()->json([
            'message' => 'Baki tunai utama berjaya ditambah.',
            'balance' => $admin->fresh()->balance,
        ]);
    }

    public function topup(Request $request)
    {
        $request->validate([
            'supervisor_id' => 'required|exists:users,id',
            'amount' => 'required|numeric|min:0.01',
        ]);

        $admin = $request->user();
        $supervisor = User::findOrFail($request->supervisor_id);

        if ($admin->balance < $request->amount) {
            return response()->json(['message' => 'Baki tunai utama tidak mencukupi.'], 400);
        }

        DB::transaction(function () use ($admin, $supervisor, $request) {
            $admin->decrement('balance', $request->amount);
            $supervisor->increment('balance', $request->amount);

            Transaction::create([
                'user_id' => $supervisor->id,
                'type' => 'topup',
                'amount' => $request->amount,
                'description' => 'Topup dari Admin: ' . $admin->name,
                'date' => now(),
            ]);
        });

        return response()->json(['message' => 'Topup berjaya dikreditkan.']);
    }
}
