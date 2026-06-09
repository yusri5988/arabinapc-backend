<?php

namespace App\Jobs;

use App\Services\ExcelExportService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class ExportConsolidatedReportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 180;

    public function __construct(
        public string $jobId,
        public string $startDate,
        public string $endDate,
    ) {}

    public function handle(ExcelExportService $service): void
    {
        try {
            $result = $service->generateConsolidated($this->startDate, $this->endDate);

            Cache::put("export_result:{$this->jobId}", [
                'status' => 'completed',
                'file_path' => $result['file_path'],
                'file_name' => $result['file_name'],
            ], 600);
        } catch (\Throwable $exception) {
            Log::error('ExportConsolidatedReportJob failed.', [
                'job_id' => $this->jobId,
                'start_date' => $this->startDate,
                'end_date' => $this->endDate,
                'message' => $exception->getMessage(),
            ]);

            Cache::put("export_result:{$this->jobId}", [
                'status' => 'failed',
                'error' => 'Gagal generate consolidated report Excel. Sila cuba lagi.',
            ], 600);
        }
    }

    public function failed(?\Throwable $exception): void
    {
        Cache::put("export_result:{$this->jobId}", [
            'status' => 'failed',
            'error' => 'Gagal generate consolidated report Excel selepas percubaan.',
        ], 600);

        Log::error('ExportConsolidatedReportJob permanently failed.', [
            'job_id' => $this->jobId,
            'start_date' => $this->startDate,
            'end_date' => $this->endDate,
            'message' => $exception?->getMessage(),
        ]);
    }
}
