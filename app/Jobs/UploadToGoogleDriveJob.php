<?php

namespace App\Jobs;

use App\Services\GoogleDriveService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class UploadToGoogleDriveJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public array $backoff = [10, 30, 60];

    public function __construct(
        public string $sourcePath,
    ) {}

    public function handle(GoogleDriveService $service): void
    {
        $success = $service->uploadPublicFile($this->sourcePath);

        if (! $success) {
            Log::warning('UploadToGoogleDriveJob: upload returned false.', [
                'source_path' => $this->sourcePath,
            ]);
        }
    }

    public function failed(?\Throwable $exception): void
    {
        Log::error('UploadToGoogleDriveJob permanently failed.', [
            'source_path' => $this->sourcePath,
            'message' => $exception?->getMessage(),
        ]);
    }
}
