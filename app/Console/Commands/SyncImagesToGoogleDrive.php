<?php

namespace App\Console\Commands;

use App\Services\GoogleDriveImageSyncService;
use Illuminate\Console\Command;

class SyncImagesToGoogleDrive extends Command
{
    protected $signature = 'images:sync-google-drive';

    protected $description = 'Sync local receipt and expense item images to Google Drive.';

    public function handle(GoogleDriveImageSyncService $syncService): int
    {
        $result = $syncService->sync();

        $this->info(sprintf(
            'Scanned %d image(s): uploaded %d, skipped %d, failed %d.',
            $result->scanned,
            $result->uploaded,
            $result->skipped,
            count($result->failures),
        ));

        return self::SUCCESS;
    }
}
