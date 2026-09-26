<?php

namespace App\Services;

use Google\Client as GoogleClient;
use Google\Service\Drive as GoogleDrive;
use Google\Service\Sheets as GoogleSheets;
use Google\Service\Sheets\BatchUpdateValuesRequest;
use Google\Service\Sheets\ValueRange;

class GoogleSheetsService
{
    private ?GoogleSheets $sheets = null;

    public function read(string $range): array
    {
        $response = $this->client()->spreadsheets_values->get(
            $this->spreadsheetId(),
            $range,
            ['majorDimension' => 'ROWS'],
        );

        return $response->getValues() ?? [];
    }

    /**
     * @param array<int, array<int, mixed>> $rows
     */
    public function appendRows(string $tabName, array $rows): void
    {
        if ($rows === []) {
            return;
        }

        $body = new ValueRange;
        $body->setMajorDimension('ROWS');
        $body->setValues($rows);

        $this->client()->spreadsheets_values->append(
            $this->spreadsheetId(),
            $this->a1Range($tabName, 'A:S'),
            $body,
            [
                'valueInputOption' => 'USER_ENTERED',
                'insertDataOption' => 'INSERT_ROWS',
            ],
        );
    }

    /**
     * @param array<int, array{range: string, values: array<int, array<int, mixed>>}> $updates
     */
    public function updateRanges(array $updates): void
    {
        if ($updates === []) {
            return;
        }

        $data = [];
        foreach ($updates as $update) {
            $valueRange = new ValueRange;
            $valueRange->setRange($update['range']);
            $valueRange->setMajorDimension('ROWS');
            $valueRange->setValues($update['values']);
            $data[] = $valueRange;
        }

        $body = new BatchUpdateValuesRequest;
        $body->setValueInputOption('USER_ENTERED');
        $body->setData($data);

        $this->client()->spreadsheets_values->batchUpdate(
            $this->spreadsheetId(),
            $body,
        );
    }

    public function a1Range(string $tabName, string $range): string
    {
        return "'".str_replace("'", "''", $tabName)."'!{$range}";
    }

    private function spreadsheetId(): string
    {
        $spreadsheetId = trim((string) config('services.google_sheets.spreadsheet_id'));

        if ($spreadsheetId === '') {
            throw new \RuntimeException('GOOGLE_SHEETS_SPREADSHEET_ID belum ditetapkan.');
        }

        return $spreadsheetId;
    }

    private function client(): GoogleSheets
    {
        if ($this->sheets instanceof GoogleSheets) {
            return $this->sheets;
        }

        $client = new GoogleClient;
        $client->setClientId((string) config('filesystems.disks.google.clientId'));
        $client->setClientSecret((string) config('filesystems.disks.google.clientSecret'));
        $client->setAccessType('offline');
        $client->setScopes([
            GoogleDrive::DRIVE_FILE,
            GoogleSheets::SPREADSHEETS,
        ]);

        $refreshToken = trim((string) config('filesystems.disks.google.refreshToken'));
        if ($refreshToken === '') {
            throw new \RuntimeException('GOOGLE_DRIVE_REFRESH_TOKEN belum ditetapkan.');
        }

        $token = $client->fetchAccessTokenWithRefreshToken($refreshToken);
        if (isset($token['error'])) {
            throw new \RuntimeException(
                'Google OAuth gagal: '.($token['error_description'] ?? $token['error'])
            );
        }

        return $this->sheets = new GoogleSheets($client);
    }
}
