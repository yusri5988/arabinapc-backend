<?php

namespace App\Services;

use App\Models\Transaction;

class TransactionSheetRowMapper
{
    public function __construct(
        private GoogleSheetsDateFormatter $dateFormatter,
    ) {}

    /**
     * @return list<string>
     */
    public function headers(): array
    {
        return [
            'Staff',
            'Department',
            'Date Receipt',
            'Date Created',
            'Payment To',
            'Description',
            'Remark',
            'Money Out (RM)',
            'Money In (RM)',
            'Initial Balance (RM)',
            'Inflow Type',
            'Outflow Type',
            'Details',
            'Month',
            'Site ID',
            'Receipt Links',
            'Item Photo Links',
            'App Transaction ID',
            'Sync Status',
        ];
    }

    /**
     * @return list<mixed>
     */
    public function map(Transaction $transaction, float $runningBalance): array
    {
        $amount = (float) $transaction->amount;
        $isTopup = $transaction->type === 'topup';
        $isReturn = $transaction->type === 'return_to_admin';

        $moneyIn = $isTopup ? $amount : '';
        $moneyOut = $isTopup ? '' : $amount;
        $inflowType = $isTopup ? 'Topup' : '';
        $outflowType = $isReturn
            ? 'Returned to Admin'
            : ($isTopup ? '' : ((string) ($transaction->details ?: 'Expense')));
        $paymentTo = $transaction->payment_to
            ?: ($isTopup ? 'Admin Transfer' : ($isReturn ? 'Admin' : '-'));
        $details = $isTopup
            ? ($transaction->details ?: 'Topup')
            : ($isReturn ? ($transaction->description ?: 'Returned to Admin') : ($transaction->details ?: $transaction->description));
        $remark = (string) (
            data_get($transaction->metadata, 'remark')
            ?: ($isTopup ? ($transaction->details ?? '') : (data_get($transaction->metadata, 'notes') ?? ''))
        );

        return [
            (string) ($transaction->user?->name ?? 'Unknown'),
            (string) ($transaction->user?->department ?? '-'),
            $this->dateFormatter->asText($this->dateFormatter->formatDate($transaction->date)),
            $this->dateFormatter->asText($this->dateFormatter->formatDateTime($transaction->created_at)),
            (string) $paymentTo,
            (string) ($transaction->description ?? ''),
            $remark,
            $moneyOut,
            $moneyIn,
            $runningBalance,
            $inflowType,
            $outflowType,
            (string) ($details ?? ''),
            optional($transaction->date)->format('F') ? strtoupper($transaction->date->format('F')) : '',
            (string) ($transaction->site_id ?? ''),
            implode("\n", $this->receiptLinks($transaction)),
            implode("\n", $this->itemPhotoLinks($transaction)),
            (string) $transaction->id,
            'Synced',
        ];
    }

    /**
     * @return list<string>
     */
    private function receiptLinks(Transaction $transaction): array
    {
        $links = data_get($transaction->metadata, 'receipt_urls', []);
        $links = is_array($links) ? $links : [];

        if ($links === [] && $transaction->receipt_url) {
            $links = [$transaction->receipt_url];
        }

        return $this->normalizeLinks($links);
    }

    /**
     * @return list<string>
     */
    private function itemPhotoLinks(Transaction $transaction): array
    {
        $images = data_get($transaction->metadata, 'item_images', []);
        $urls = is_array($images)
            ? array_map(fn ($image) => is_array($image) ? ($image['url'] ?? '') : '', $images)
            : [];

        return $this->normalizeLinks($urls);
    }

    /**
     * @param array<int, mixed> $links
     * @return list<string>
     */
    private function normalizeLinks(array $links): array
    {
        $apiUrl = rtrim((string) config('app.api_url', config('app.url')), '/');

        return array_values(array_unique(array_filter(array_map(function ($link) use ($apiUrl) {
            if (! is_string($link) || trim($link) === '') {
                return null;
            }

            $link = trim($link);
            $link = str_replace('/storage/receipts/', '/receipts/', $link);
            $link = str_replace('/storage/expense-items/', '/expense-items/', $link);

            return preg_match('/^https?:\\/\\//i', $link)
                ? $link
                : $apiUrl.'/'.ltrim($link, '/');
        }, $links))));
    }
}
