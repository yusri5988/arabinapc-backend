<?php

namespace App\Services;

use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class ExcelExportService
{
    public function generate(int $supervisorId): array
    {
        $supervisor = User::findOrFail($supervisorId);

        $transactions = Transaction::where('user_id', $supervisor->id)
            ->orderBy('date')
            ->orderBy('id')
            ->get();

        $openingBalance = 0;

        $spreadsheet = new Spreadsheet;
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
                } elseif ($transaction->type === 'return_to_admin') {
                    $moneyIn = 0;
                    $moneyOut = $amount;
                    $inflowType = '';
                    $outflowType = 'Returned to Admin';
                    $paymentTo = $transaction->payment_to ?: 'Admin';
                    $displayDetails = $transaction->description ?: 'Returned to Admin';
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

        $fileName = 'petty-cash-'.$supervisor->name.'-'.now()->format('Ymd_His').'.xlsx';
        $relativePath = 'exports/'.$fileName;

        Storage::disk('local')->makeDirectory('exports');

        $fullPath = Storage::disk('local')->path($relativePath);

        $writer = new Xlsx($spreadsheet);
        $writer->save($fullPath);

        return [
            'file_path' => $fullPath,
            'file_name' => $fileName,
        ];
    }

    public function generateAll(): array
    {
        $transactions = Transaction::with('user')
            ->whereHas('user', fn ($q) => $q->where('role', 'supervisor'))
            ->orderBy('date')
            ->orderBy('id')
            ->get();

        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Petty Cash');

        $headers = [
            'Staff',
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

        $headerStyle = $sheet->getStyle('A1:M1');
        $headerStyle->getFont()->setBold(true);
        $headerStyle->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFDCFCE7');

        $row = 2;
        $runningBalance = 0;

        foreach ($transactions as $transaction) {
            $amount = (float) $transaction->amount;

            if ($transaction->type === 'topup') {
                $moneyIn = $amount;
                $moneyOut = 0;
                $inflowType = 'Topup';
                $outflowType = '';
                $paymentTo = $transaction->payment_to ?: 'Admin Transfer';
                $displayDetails = $transaction->details ?: 'Topup';
            } elseif ($transaction->type === 'return_to_admin') {
                $moneyIn = 0;
                $moneyOut = $amount;
                $inflowType = '';
                $outflowType = 'Returned to Admin';
                $paymentTo = $transaction->payment_to ?: 'Admin';
                $displayDetails = $transaction->description ?: 'Returned to Admin';
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

            $staffName = $transaction->user?->name ?? 'Unknown';

            $sheet->setCellValueExplicit("A{$row}", (string) $staffName, DataType::TYPE_STRING);
            $sheet->setCellValueExplicit("B{$row}", optional($transaction->date)->format('d/m/Y') ?? '', DataType::TYPE_STRING);
            $sheet->setCellValueExplicit("C{$row}", (string) $paymentTo, DataType::TYPE_STRING);
            $sheet->setCellValueExplicit("D{$row}", (string) ($transaction->description ?? ''), DataType::TYPE_STRING);
            $sheet->setCellValueExplicit("E{$row}", $moneyOut > 0 ? number_format($moneyOut, 2, '.', '') : '', DataType::TYPE_STRING);
            $sheet->setCellValueExplicit("F{$row}", $moneyIn > 0 ? number_format($moneyIn, 2, '.', '') : '', DataType::TYPE_STRING);
            $sheet->setCellValueExplicit("G{$row}", number_format($balance, 2, '.', ''), DataType::TYPE_STRING);
            $sheet->setCellValueExplicit("H{$row}", $inflowType, DataType::TYPE_STRING);
            $sheet->setCellValueExplicit("I{$row}", $outflowType, DataType::TYPE_STRING);
            $sheet->setCellValueExplicit("J{$row}", (string) $displayDetails, DataType::TYPE_STRING);
            $sheet->setCellValueExplicit("K{$row}", optional($transaction->date)->format('F') ? strtoupper(optional($transaction->date)->format('F')) : '', DataType::TYPE_STRING);
            $sheet->setCellValueExplicit("L{$row}", (string) ($transaction->site_id ?? ''), DataType::TYPE_STRING);
            $sheet->setCellValueExplicit("M{$row}", (string) $docLink, DataType::TYPE_STRING);

            $row++;
        }

        if ($transactions->isEmpty()) {
            $sheet->setCellValue("A2", 'No transactions');
            $sheet->setCellValueExplicit("G2", number_format(0, 2, '.', ''), DataType::TYPE_NUMERIC);
        }

        foreach (range('A', 'M') as $column) {
            $sheet->getColumnDimension($column)->setAutoSize(true);
        }

        $fileName = 'petty-cash-all-staff-'.now()->format('Ymd_His').'.xlsx';
        $relativePath = 'exports/'.$fileName;

        Storage::disk('local')->makeDirectory('exports');

        $fullPath = Storage::disk('local')->path($relativePath);

        $writer = new Xlsx($spreadsheet);
        $writer->save($fullPath);

        return [
            'file_path' => $fullPath,
            'file_name' => $fileName,
        ];
    }

    public function generateConsolidated(string $startDate, string $endDate): array
    {
        $transactions = Transaction::with('user')
            ->whereHas('user', fn ($q) => $q->where('role', 'supervisor'))
            ->whereBetween('date', [$startDate, $endDate])
            ->orderBy('date')
            ->orderBy('id')
            ->get();

        $totalTopup = $transactions->where('type', 'topup')->sum('amount');
        $totalExpense = $transactions->where('type', 'expense')->sum('amount');
        $totalReturnedToAdmin = $transactions->where('type', 'return_to_admin')->sum('amount');
        $distinctStaff = $transactions->pluck('user_id')->unique()->count();

        $spreadsheet = new Spreadsheet;

        // ─── Sheet 1: Summary ───
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Summary');

        $summaryData = [
            ['Report Period', $startDate.' - '.$endDate],
            ['Total Staff', $distinctStaff],
            ['Total Transactions', $transactions->count()],
            ['Total Topup (RM)', number_format((float) $totalTopup, 2)],
            ['Total Expense (RM)', number_format((float) $totalExpense, 2)],
            ['Returned to Admin (RM)', number_format((float) $totalReturnedToAdmin, 2)],
            ['Balance (RM)', number_format((float) ($totalTopup - $totalExpense - $totalReturnedToAdmin), 2)],
        ];

        $sheet->fromArray($summaryData, null, 'A1');
        $headerStyle = $sheet->getStyle('A1:B1');
        $headerStyle->getFont()->setBold(true);
        $headerStyle->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFDCFCE7');
        $sheet->getColumnDimension('A')->setAutoSize(true);
        $sheet->getColumnDimension('B')->setAutoSize(true);

        // ─── Sheet 2: By Staff ───
        $byStaff = $transactions->groupBy('user_id');
        $sheet2 = $spreadsheet->createSheet();
        $sheet2->setTitle('By Staff');
        $sheet2->fromArray([
            'Staff', 'Department', 'Total Topup (RM)', 'Total Expense (RM)', 'Returned to Admin (RM)', 'Balance (RM)', '# Transactions',
        ], null, 'A1');
        $headerStyle2 = $sheet2->getStyle('A1:G1');
        $headerStyle2->getFont()->setBold(true);
        $headerStyle2->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFDCFCE7');

        $staffRow = 2;
        $grandStaffTopup = 0;
        $grandStaffExpense = 0;
        $grandStaffReturnedToAdmin = 0;
        $grandStaffTx = 0;

        foreach ($byStaff as $userId => $staffTx) {
            $user = $staffTx->first()->user;
            $staffTopup = $staffTx->where('type', 'topup')->sum('amount');
            $staffExpense = $staffTx->where('type', 'expense')->sum('amount');
            $staffReturnedToAdmin = $staffTx->where('type', 'return_to_admin')->sum('amount');
            $staffBalance = $staffTopup - $staffExpense - $staffReturnedToAdmin;
            $txCount = $staffTx->count();

            $sheet2->setCellValueExplicit("A{$staffRow}", $user?->name ?? 'Unknown', DataType::TYPE_STRING);
            $sheet2->setCellValueExplicit("B{$staffRow}", $user?->department ?? '-', DataType::TYPE_STRING);
            $sheet2->setCellValueExplicit("C{$staffRow}", number_format((float) $staffTopup, 2), DataType::TYPE_STRING);
            $sheet2->setCellValueExplicit("D{$staffRow}", number_format((float) $staffExpense, 2), DataType::TYPE_STRING);
            $sheet2->setCellValueExplicit("E{$staffRow}", number_format((float) $staffReturnedToAdmin, 2), DataType::TYPE_STRING);
            $sheet2->setCellValueExplicit("F{$staffRow}", number_format((float) $staffBalance, 2), DataType::TYPE_STRING);
            $sheet2->setCellValueExplicit("G{$staffRow}", (string) $txCount, DataType::TYPE_STRING);

            $grandStaffTopup += $staffTopup;
            $grandStaffExpense += $staffExpense;
            $grandStaffReturnedToAdmin += $staffReturnedToAdmin;
            $grandStaffTx += $txCount;
            $staffRow++;
        }

        $sheet2->setCellValueExplicit("A{$staffRow}", 'TOTAL', DataType::TYPE_STRING);
        $sheet2->getStyle("A{$staffRow}")->getFont()->setBold(true);
        $sheet2->setCellValueExplicit("C{$staffRow}", number_format((float) $grandStaffTopup, 2), DataType::TYPE_STRING);
        $sheet2->setCellValueExplicit("D{$staffRow}", number_format((float) $grandStaffExpense, 2), DataType::TYPE_STRING);
        $sheet2->setCellValueExplicit("E{$staffRow}", number_format((float) $grandStaffReturnedToAdmin, 2), DataType::TYPE_STRING);
        $sheet2->setCellValueExplicit("F{$staffRow}", number_format((float) ($grandStaffTopup - $grandStaffExpense - $grandStaffReturnedToAdmin), 2), DataType::TYPE_STRING);
        $sheet2->setCellValueExplicit("G{$staffRow}", (string) $grandStaffTx, DataType::TYPE_STRING);

        foreach (range('A', 'G') as $column) {
            $sheet2->getColumnDimension($column)->setAutoSize(true);
        }

        // ─── Sheet 3: By Department ───
        $byDepartment = $transactions->groupBy(fn ($tx) => $tx->user?->department ?? 'Unknown');
        $sheet3 = $spreadsheet->createSheet();
        $sheet3->setTitle('By Department');
        $sheet3->fromArray([
            'Department', 'Total Staff', 'Total Topup (RM)', 'Total Expense (RM)', 'Returned to Admin (RM)', 'Balance (RM)',
        ], null, 'A1');
        $headerStyle3 = $sheet3->getStyle('A1:F1');
        $headerStyle3->getFont()->setBold(true);
        $headerStyle3->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFDCFCE7');

        $deptRow = 2;
        $grandDeptStaff = 0;
        $grandDeptTopup = 0;
        $grandDeptExpense = 0;
        $grandDeptReturnedToAdmin = 0;

        foreach ($byDepartment as $department => $deptTx) {
            $deptStaffCount = $deptTx->pluck('user_id')->unique()->count();
            $deptTopup = $deptTx->where('type', 'topup')->sum('amount');
            $deptExpense = $deptTx->where('type', 'expense')->sum('amount');
            $deptReturnedToAdmin = $deptTx->where('type', 'return_to_admin')->sum('amount');
            $deptBalance = $deptTopup - $deptExpense - $deptReturnedToAdmin;

            $sheet3->setCellValueExplicit("A{$deptRow}", (string) $department, DataType::TYPE_STRING);
            $sheet3->setCellValueExplicit("B{$deptRow}", (string) $deptStaffCount, DataType::TYPE_STRING);
            $sheet3->setCellValueExplicit("C{$deptRow}", number_format((float) $deptTopup, 2), DataType::TYPE_STRING);
            $sheet3->setCellValueExplicit("D{$deptRow}", number_format((float) $deptExpense, 2), DataType::TYPE_STRING);
            $sheet3->setCellValueExplicit("E{$deptRow}", number_format((float) $deptReturnedToAdmin, 2), DataType::TYPE_STRING);
            $sheet3->setCellValueExplicit("F{$deptRow}", number_format((float) $deptBalance, 2), DataType::TYPE_STRING);

            $grandDeptStaff += $deptStaffCount;
            $grandDeptTopup += $deptTopup;
            $grandDeptExpense += $deptExpense;
            $grandDeptReturnedToAdmin += $deptReturnedToAdmin;
            $deptRow++;
        }

        $sheet3->setCellValueExplicit("A{$deptRow}", 'TOTAL', DataType::TYPE_STRING);
        $sheet3->getStyle("A{$deptRow}")->getFont()->setBold(true);
        $sheet3->setCellValueExplicit("B{$deptRow}", (string) $grandDeptStaff, DataType::TYPE_STRING);
        $sheet3->setCellValueExplicit("C{$deptRow}", number_format((float) $grandDeptTopup, 2), DataType::TYPE_STRING);
        $sheet3->setCellValueExplicit("D{$deptRow}", number_format((float) $grandDeptExpense, 2), DataType::TYPE_STRING);
        $sheet3->setCellValueExplicit("E{$deptRow}", number_format((float) $grandDeptReturnedToAdmin, 2), DataType::TYPE_STRING);
        $sheet3->setCellValueExplicit("F{$deptRow}", number_format((float) ($grandDeptTopup - $grandDeptExpense - $grandDeptReturnedToAdmin), 2), DataType::TYPE_STRING);

        foreach (range('A', 'F') as $column) {
            $sheet3->getColumnDimension($column)->setAutoSize(true);
        }

        // ─── Sheet 4: Top Expenses ───
        $topExpenses = $transactions->where('type', 'expense')->sortByDesc('amount')->take(10);
        $sheet4 = $spreadsheet->createSheet();
        $sheet4->setTitle('Top Expenses');
        $sheet4->fromArray([
            'Date', 'Staff', 'Payment To', 'Details', 'Amount (RM)', 'Site ID',
        ], null, 'A1');
        $headerStyle4 = $sheet4->getStyle('A1:F1');
        $headerStyle4->getFont()->setBold(true);
        $headerStyle4->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFDCFCE7');

        $expenseRow = 2;
        foreach ($topExpenses as $tx) {
            $sheet4->setCellValueExplicit("A{$expenseRow}", optional($tx->date)->format('d/m/Y') ?? '', DataType::TYPE_STRING);
            $sheet4->setCellValueExplicit("B{$expenseRow}", $tx->user?->name ?? 'Unknown', DataType::TYPE_STRING);
            $sheet4->setCellValueExplicit("C{$expenseRow}", (string) ($tx->payment_to ?? '-'), DataType::TYPE_STRING);
            $sheet4->setCellValueExplicit("D{$expenseRow}", (string) ($tx->details ?: $tx->description ?? ''), DataType::TYPE_STRING);
            $sheet4->setCellValueExplicit("E{$expenseRow}", number_format((float) $tx->amount, 2), DataType::TYPE_STRING);
            $sheet4->setCellValueExplicit("F{$expenseRow}", (string) ($tx->site_id ?? ''), DataType::TYPE_STRING);
            $expenseRow++;
        }

        foreach (range('A', 'F') as $column) {
            $sheet4->getColumnDimension($column)->setAutoSize(true);
        }

        $fileName = 'petty-cash-consolidated-'.now()->format('Ymd_His').'.xlsx';
        $relativePath = 'exports/'.$fileName;

        Storage::disk('local')->makeDirectory('exports');

        $fullPath = Storage::disk('local')->path($relativePath);

        $writer = new Xlsx($spreadsheet);
        $writer->save($fullPath);

        return [
            'file_path' => $fullPath,
            'file_name' => $fileName,
        ];
    }
}
