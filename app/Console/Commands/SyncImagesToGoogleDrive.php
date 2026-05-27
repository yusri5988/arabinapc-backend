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
        $dispatched = $syncService->dispatchAll();

        $this->info("Dispatched {$dispatched} Google Drive upload job(s).");

        return self::SUCCESS;
    }
}
