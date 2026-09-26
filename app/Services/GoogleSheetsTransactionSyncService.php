<?php

namespace App\Services;

use App\Models\Transaction;

class GoogleSheetsTransactionSyncService
{
    private const DATE_COLUMN_INDICES = [2, 3];

    private const SYNC_ID_INDEX = 17;

    private const SYNC_STATUS_INDEX = 18;

    public function __construct(
        private GoogleSheetsService $googleSheets,
        private TransactionSheetRowMapper $rowMapper,
    ) {}

    /**
     * @return array{scanned: int, appended: int, updated: int, skipped: int, marked_deleted: int}
     */
    public function sync(): array
    {
        if (! config('services.google_sheets.enabled', true)) {
            return [
                'scanned' => 0,
                'appended' => 0,
                'updated' => 0,
                'skipped' => 0,
                'marked_deleted' => 0,
            ];
        }

        $tabName = (string) config('services.google_sheets.tab_name', 'MainData-PC System');
        $maxRows = max(2, (int) config('services.google_sheets.max_rows', 5000));
        $values = $this->googleSheets->read($this->googleSheets->a1Range($tabName, "A1:S{$maxRows}"));
        $this->validateHeaders($values[0] ?? []);

        $existingRows = $this->indexExistingRows($values);
        $transactions = Transaction::with('user')
            ->whereHas('user', fn ($query) => $query->where('role', 'supervisor'))
            ->orderBy('date')
            ->orderBy('id')
            ->get();

        $runningBalances = [];
        $updates = [];
        $toAppend = [];
        $sourceIds = [];
        $updated = 0;
        $skipped = 0;

        foreach ($transactions as $transaction) {
            $userId = (int) $transaction->user_id;
            $amount = (float) $transaction->amount;
            $runningBalances[$userId] = round(
                ($runningBalances[$userId] ?? 0)
                + ($transaction->type === 'topup' ? $amount : -$amount),
                2,
            );

            $row = $this->rowMapper->map($transaction, $runningBalances[$userId]);
            $transactionId = (string) $transaction->id;
            $sourceIds[$transactionId] = true;

            if (! isset($existingRows[$transactionId])) {
                $toAppend[] = $row;
                continue;
            }

            $rowNumber = $existingRows[$transactionId]['row'];
            $existingRow = $existingRows[$transactionId]['values'];

            if ($this->sameRow($existingRow, $row)) {
                $skipped++;
                continue;
            }

            $updates[] = [
                'range' => $this->googleSheets->a1Range($tabName, "A{$rowNumber}:S{$rowNumber}"),
                'values' => [$row],
            ];
            $updated++;
        }

        $markedDeleted = 0;
        foreach ($existingRows as $transactionId => $existing) {
            if (isset($sourceIds[$transactionId])) {
                continue;
            }

            if (($existing['values'][self::SYNC_STATUS_INDEX] ?? '') === 'Deleted') {
                continue;
            }

            $rowNumber = $existing['row'];
            $updates[] = [
                'range' => $this->googleSheets->a1Range($tabName, "S{$rowNumber}"),
                'values' => [['Deleted']],
            ];
            $markedDeleted++;
        }

        $this->googleSheets->updateRanges($updates);
        $this->googleSheets->appendRows($tabName, $toAppend);

        return [
            'scanned' => $transactions->count(),
            'appended' => count($toAppend),
            'updated' => $updated,
            'skipped' => $skipped,
            'marked_deleted' => $markedDeleted,
        ];
    }

    private function validateHeaders(array $headers): void
    {
        if (($headers[self::SYNC_ID_INDEX] ?? null) !== 'App Transaction ID'
            || ($headers[self::SYNC_STATUS_INDEX] ?? null) !== 'Sync Status') {
            throw new \RuntimeException(
                'Header MainData-PC System tidak sepadan. Pastikan App Transaction ID berada di R1 dan Sync Status di S1.'
            );
        }
    }

    /**
     * @param array<int, array<int, mixed>> $values
     * @return array<string, array{row: int, values: array<int, mixed>}>
     */
    private function indexExistingRows(array $values): array
    {
        $indexed = [];

        foreach (array_slice($values, 1) as $offset => $row) {
            $transactionId = trim((string) ($row[self::SYNC_ID_INDEX] ?? ''));
            if ($transactionId === '') {
                continue;
            }

            if (isset($indexed[$transactionId])) {
                throw new \RuntimeException("App Transaction ID duplicate dalam row ".($offset + 2).': '.$transactionId);
            }

            $indexed[$transactionId] = [
                'row' => $offset + 2,
                'values' => $row,
            ];
        }

        return $indexed;
    }

    /**
     * @param array<int, mixed> $existing
     * @param array<int, mixed> $expected
     */
    private function sameRow(array $existing, array $expected): bool
    {
        for ($index = 0; $index < count($expected); $index++) {
            $existingValue = (string) ($existing[$index] ?? '');
            $expectedValue = (string) ($expected[$index] ?? '');

            if (in_array($index, self::DATE_COLUMN_INDICES, true)) {
                $existingValue = ltrim($existingValue, "'");
                $expectedValue = ltrim($expectedValue, "'");
            }

            if ($existingValue !== $expectedValue) {
                return false;
            }
        }

        return true;
    }
}
