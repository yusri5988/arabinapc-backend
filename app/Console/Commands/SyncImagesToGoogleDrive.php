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
            'Google Drive image sync completed. Scanned: %d Uploaded: %d Skipped: %d Failed: %d',
            $result->scanned,
            $result->uploaded,
            $result->skipped,
            $result->failed(),
        ));

        foreach ($result->failures as $path => $message) {
            $this->warn("Failed: {$path} ({$message})");
        }

        return $result->hasFailures() ? self::FAILURE : self::SUCCESS;
    }
}
