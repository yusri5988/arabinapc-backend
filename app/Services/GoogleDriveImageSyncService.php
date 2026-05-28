<?php

namespace App\Services;

use App\DTOs\GoogleDriveImageSyncResultDTO;
use App\Jobs\UploadToGoogleDriveJob;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class GoogleDriveImageSyncService
{
    private const ROOTS = [
        'receipts',
        'expense-items',
    ];

    public function __construct(
        private GoogleDriveService $googleDriveService,
    ) {}

    public function sync(): GoogleDriveImageSyncResultDTO
    {
        $scanned = 0;
        $uploaded = 0;
        $skipped = 0;
        $failures = [];

        foreach (self::ROOTS as $root) {
            foreach ($this->localFiles($root) as $path) {
                if (! $this->isSiteImagePath($path)) {
                    continue;
                }

                $scanned++;

                try {
                    if ($this->googleDriveService->exists($path)) {
                        $skipped++;

                        continue;
                    }

                    if ($this->googleDriveService->uploadPublicFile($path, $path)) {
                        $uploaded++;

                        continue;
                    }

                    $failures[$path] = 'Upload returned false.';
                } catch (\Throwable $exception) {
                    $failures[$path] = $exception->getMessage();
                }

                Log::warning('Google Drive image sync failed for local file.', [
                    'path' => $path,
                    'message' => $failures[$path],
                ]);
            }
        }

        return new GoogleDriveImageSyncResultDTO(
            scanned: $scanned,
            uploaded: $uploaded,
            skipped: $skipped,
            failures: $failures,
        );
    }

    public function dispatchAll(): int
    {
        $dispatched = 0;

        foreach (self::ROOTS as $root) {
            foreach ($this->localFiles($root) as $path) {
                if (! $this->isSiteImagePath($path)) {
                    continue;
                }

                try {
                    if ($this->googleDriveService->exists($path)) {
                        continue;
                    }

                    UploadToGoogleDriveJob::dispatch($path);
                    $dispatched++;
                } catch (\Throwable $exception) {
                    Log::warning('Google Drive image sync dispatch failed.', [
                        'path' => $path,
                        'message' => $exception->getMessage(),
                    ]);
                }
            }
        }

        return $dispatched;
    }

    /**
     * @return array<int, string>
     */
    private function localFiles(string $root): array
    {
        try {
            return Storage::disk('public')->allFiles($root);
        } catch (\Throwable $exception) {
            Log::warning('Google Drive image sync could not scan local folder.', [
                'root' => $root,
                'message' => $exception->getMessage(),
            ]);

            return [];
        }
    }

    private function isSiteImagePath(string $path): bool
    {
        $path = trim(str_replace('\\', '/', $path), '/');
        $parts = explode('/', $path);

        return count($parts) >= 3
            && in_array($parts[0], self::ROOTS, true)
            && $parts[1] !== ''
            && $parts[2] !== '';
    }
}
