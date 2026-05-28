<?php

namespace App\Jobs;

use App\Services\ReceiptProcessingService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class ProcessReceiptJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public array $backoff = [5, 15, 30];

    public function __construct(
        public string $storedPath,
        public string $receiptUrl,
        public string $jobId,
    ) {}

    public function handle(ReceiptProcessingService $service): void
    {
        try {
            $dto = $service->processStored($this->storedPath, $this->receiptUrl);

            Cache::put("job_result:{$this->jobId}", [
                'status' => 'completed',
                'data' => $dto->toArray(),
            ], 300);
        } catch (\Throwable $exception) {
            Log::error('ProcessReceiptJob failed.', [
                'job_id' => $this->jobId,
                'stored_path' => $this->storedPath,
                'message' => $exception->getMessage(),
            ]);

            Cache::put("job_result:{$this->jobId}", [
                'status' => 'failed',
                'error' => 'Gagal baca resit. Sila isi borang secara manual.',
            ], 300);
        }
    }

    public function failed(?\Throwable $exception): void
    {
        Cache::put("job_result:{$this->jobId}", [
            'status' => 'failed',
            'error' => 'Gagal baca resit selepas beberapa percubaan. Sila isi borang secara manual.',
        ], 300);

        Log::error('ProcessReceiptJob permanently failed.', [
            'job_id' => $this->jobId,
            'stored_path' => $this->storedPath,
            'message' => $exception?->getMessage(),
        ]);
    }
}
