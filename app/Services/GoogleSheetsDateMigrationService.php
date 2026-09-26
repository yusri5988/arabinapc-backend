<?php

namespace App\Services;

class GoogleSheetsDateMigrationService
{
    private const DATE_COLUMNS = [
        2 => ['letter' => 'C', 'include_time' => false],
        3 => ['letter' => 'D', 'include_time' => true],
    ];

    public function __construct(
        private GoogleSheetsService $googleSheets,
        private GoogleSheetsDateFormatter $dateFormatter,
    ) {}

    /**
     * @return array{scanned: int, updated_cells: int, skipped_unrecognised: int}
     */
    public function migrate(bool $dryRun = false): array
    {
        if (! config('services.google_sheets.enabled', true)) {
            return ['scanned' => 0, 'updated_cells' => 0, 'skipped_unrecognised' => 0];
        }

        $tabName = (string) config('services.google_sheets.tab_name', 'MainData-PC System');
        $maxRows = max(2, (int) config('services.google_sheets.max_rows', 5000));
        $values = $this->googleSheets->read($this->googleSheets->a1Range($tabName, "A1:S{$maxRows}"));
        $this->validateHeaders($values[0] ?? []);

        $updates = [];
        $scanned = 0;
        $skippedUnrecognised = 0;

        foreach (array_slice($values, 1) as $offset => $row) {
            $scanned++;
            $rowNumber = $offset + 2;

            foreach (self::DATE_COLUMNS as $columnIndex => $column) {
                $currentValue = $row[$columnIndex] ?? '';
                $formatted = $this->dateFormatter->formatSheetValue(
                    $currentValue,
                    $column['include_time'],
                );

                if ($formatted === '') {
                    continue;
                }

                if ($formatted === null) {
                    $skippedUnrecognised++;
                    continue;
                }

                if (ltrim((string) $currentValue, "'") === $formatted) {
                    continue;
                }

                $updates[] = [
                    'range' => $this->googleSheets->a1Range($tabName, "{$column['letter']}{$rowNumber}"),
                    'values' => [[$this->dateFormatter->asText($formatted)]],
                ];
            }
        }

        if (! $dryRun) {
            $this->googleSheets->updateRanges($updates);
        }

        return [
            'scanned' => $scanned,
            'updated_cells' => count($updates),
            'skipped_unrecognised' => $skippedUnrecognised,
        ];
    }

    private function validateHeaders(array $headers): void
    {
        if (($headers[2] ?? null) !== 'Date Receipt' || ($headers[3] ?? null) !== 'Date Created') {
            throw new \RuntimeException(
                'Header tarikh tidak sepadan. Pastikan Date Receipt berada di C1 dan Date Created di D1.'
            );
        }
    }
}
