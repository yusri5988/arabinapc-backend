<?php

namespace App\Console\Commands;

use App\Services\GoogleSheetsTransactionSyncService;
use Illuminate\Console\Command;

class SyncTransactionsToGoogleSheets extends Command
{
    protected $signature = 'transactions:sync-google-sheets';

    protected $description = 'Sync supervisor transactions to the configured Google Sheet.';

    public function handle(GoogleSheetsTransactionSyncService $syncService): int
    {
        try {
            $result = $syncService->sync();
        } catch (\Throwable $exception) {
            $this->error('Google Sheets sync gagal: '.$exception->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf(
            'Scanned %d: appended %d, updated %d, skipped %d, marked deleted %d.',
            $result['scanned'],
            $result['appended'],
            $result['updated'],
            $result['skipped'],
            $result['marked_deleted'],
        ));

        return self::SUCCESS;
    }
}
