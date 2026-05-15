<?php

namespace App\Http\Controllers;

use App\Models\Transaction;
use App\Models\User;
use App\Services\SupervisorBalanceService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class AdminController extends Controller
{
    public function dashboard(SupervisorBalanceService $balanceService)
    {
        $supervisors = User::where('role', 'supervisor')->get();
        $supervisors->each(fn (User $supervisor) => $supervisor->balance = $balanceService->calculate($supervisor->id));
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

    public function listSupervisors(SupervisorBalanceService $balanceService)
    {
        $supervisors = User::where('role', 'supervisor')->get();
        $supervisors->each(fn (User $supervisor) => $supervisor->balance = $balanceService->calculate($supervisor->id));

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

    public function topup(Request $request, SupervisorBalanceService $balanceService)
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

        $balance = DB::transaction(function () use ($admin, $supervisor, $request, $balanceService) {
            $supervisor = User::whereKey($supervisor->id)->lockForUpdate()->firstOrFail();

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

            return $balanceService->recalculate($supervisor);
        });

        return response()->json([
            'message' => 'Duit berjaya dihantar kepada supervisor.',
            'balance' => $balance,
        ]);
    }

    public function exportSupervisorExcel(User $supervisor)
    {
        abort_unless($supervisor->role === 'supervisor', 404);

        $transactions = Transaction::where('user_id', $supervisor->id)
            ->orderBy('date')
            ->orderBy('id')
            ->get();

        $openingBalance = 0;

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Petty Cash');

        $headers = [
            'Date',
            'Payment To',
            'Details',
            'Money Out',
            'Money In',
            'Initial balance',
            'Inflow type',
            'Outflow type',
            'Details',
            'Month',
            'Site ID',
            'Doc.Link',
        ];

        $sheet->fromArray($headers, null, 'A1');

        $headerStyle = $sheet->getStyle('A1:L1');
        $headerStyle->getFont()->setBold(true);
        $headerStyle->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFDCFCE7');

        $row = 2;
        $runningBalance = $openingBalance;

        if ($transactions->isEmpty()) {
            $sheet->setCellValue("A{$row}", now()->format('d/m/Y'));
            $sheet->setCellValue("B{$row}", 'Opening Balance');
            $sheet->setCellValueExplicit("F{$row}", number_format($openingBalance, 2, '.', ''), DataType::TYPE_NUMERIC);
        } else {
            foreach ($transactions as $transaction) {
                $amount = (float) $transaction->amount;

                if ($transaction->type === 'topup') {
                    $moneyIn = $amount;
                    $moneyOut = 0;
                    $inflowType = 'Topup';
                    $outflowType = '';
                    $paymentTo = $transaction->payment_to ?: 'Admin Transfer';
                    $displayDetails = $transaction->details ?: 'Topup';
                } else {
                    $moneyIn = 0;
                    $moneyOut = $amount;
                    $inflowType = '';
                    $outflowType = $transaction->details ?: 'Expense';
                    $paymentTo = $transaction->payment_to ?: '-';
                    $displayDetails = $transaction->details ?: $transaction->description;
                }

                $runningBalance = $runningBalance + $moneyIn - $moneyOut;
                $balance = $runningBalance;
                $docLink = $transaction->receipt_url;
                $itemImages = data_get($transaction->metadata, 'item_images', []);

                if (empty($docLink) && is_array($itemImages) && ! empty($itemImages)) {
                    $docLink = $itemImages[0]['url'] ?? '';
                }

                $sheet->setCellValueExplicit("A{$row}", optional($transaction->date)->format('d/m/Y') ?? '', DataType::TYPE_STRING);
                $sheet->setCellValueExplicit("B{$row}", (string) $paymentTo, DataType::TYPE_STRING);
                $sheet->setCellValueExplicit("C{$row}", (string) ($transaction->description ?? ''), DataType::TYPE_STRING);
                $sheet->setCellValueExplicit("D{$row}", $moneyOut > 0 ? number_format($moneyOut, 2, '.', '') : '', DataType::TYPE_STRING);
                $sheet->setCellValueExplicit("E{$row}", $moneyIn > 0 ? number_format($moneyIn, 2, '.', '') : '', DataType::TYPE_STRING);
                $sheet->setCellValueExplicit("F{$row}", number_format($balance, 2, '.', ''), DataType::TYPE_STRING);
                $sheet->setCellValueExplicit("G{$row}", $inflowType, DataType::TYPE_STRING);
                $sheet->setCellValueExplicit("H{$row}", $outflowType, DataType::TYPE_STRING);
                $sheet->setCellValueExplicit("I{$row}", (string) $displayDetails, DataType::TYPE_STRING);
                $sheet->setCellValueExplicit("J{$row}", optional($transaction->date)->format('F') ? strtoupper(optional($transaction->date)->format('F')) : '', DataType::TYPE_STRING);
                $sheet->setCellValueExplicit("K{$row}", (string) ($transaction->site_id ?? ''), DataType::TYPE_STRING);
                $sheet->setCellValueExplicit("L{$row}", (string) $docLink, DataType::TYPE_STRING);

                $row++;
            }
        }

        foreach (range('A', 'L') as $column) {
            $sheet->getColumnDimension($column)->setAutoSize(true);
        }

        $fileName = 'petty-cash-' . $supervisor->name . '-' . now()->format('Ymd_His') . '.xlsx';
        $tempPath = tempnam(sys_get_temp_dir(), 'pcx');

        $writer = new Xlsx($spreadsheet);
        $writer->save($tempPath);

        return response()->download($tempPath, $fileName)->deleteFileAfterSend(true);
    }

    private function normalizePhone(string $phone): string
    {
        return preg_replace('/[\s-]+/', '', trim($phone)) ?? '';
    }
}
