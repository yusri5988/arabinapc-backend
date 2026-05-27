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
}
