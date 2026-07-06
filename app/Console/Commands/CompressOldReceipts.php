<?php

namespace App\Console\Commands;

use App\Services\ImageCompressionService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class CompressOldReceipts extends Command
{
    protected $signature = 'receipt:compress-old
                            {--min-size=500 : Minimum size in KB to compress}
                            {--dry-run : Preview without actually compressing}
                            {--disk=public : Storage disk to use}';

    protected $description = 'Compress old receipt images to save storage space';

    public function handle(ImageCompressionService $compressionService): int
    {
        $minSizeKB = (int) $this->option('min-size');
        $dryRun = $this->option('dry-run');
        $disk = $this->option('disk');
        $minSizeBytes = $minSizeKB * 1024;

        $this->info('Scanning receipt images...');
        $this->newLine();

        $allFiles = Storage::disk($disk)->allFiles('receipts');

        $imageFiles = array_filter($allFiles, function ($file) {
            $extension = strtolower(pathinfo($file, PATHINFO_EXTENSION));

            return in_array($extension, ['jpg', 'jpeg', 'png', 'gif', 'webp']);
        });

        $filesToCompress = array_filter($imageFiles, function ($file) use ($disk, $minSizeBytes) {
            return Storage::disk($disk)->size($file) > $minSizeBytes;
        });

        $totalFiles = count($filesToCompress);

        if ($totalFiles === 0) {
            $this->info('No images found above '.($minSizeKB).'KB threshold.');

            return self::SUCCESS;
        }

        $this->info("Found {$totalFiles} images above {$minSizeKB}KB.");

        if ($dryRun) {
            $this->info('DRY RUN - No files will be modified.');
            $this->newLine();

            $totalSize = 0;
            foreach ($filesToCompress as $file) {
                $size = Storage::disk($disk)->size($file);
                $totalSize += $size;
                $this->line("  {$file} (".$this->formatBytes($size).')');
            }

            $this->newLine();
            $this->info('Total: '.$this->formatBytes($totalSize));

            return self::SUCCESS;
        }

        $this->info('Compressing...');
        $this->newLine();

        $bar = $this->output->createProgressBar($totalFiles);
        $bar->start();

        $successCount = 0;
        $skippedCount = 0;
        $failedCount = 0;
        $totalSaved = 0;

        foreach ($filesToCompress as $file) {
            $originalSize = Storage::disk($disk)->size($file);

            $result = $compressionService->compress($file, $disk);

            if ($result['success']) {
                $successCount++;
                $totalSaved += $result['original_size'] - $result['new_size'];
            } elseif ($result['action'] === 'skipped') {
                $skippedCount++;
            } else {
                $failedCount++;
                $this->newLine();
                $this->error("  Failed: {$file} - {$result['reason']}");
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        $this->info('=== Summary ===');
        $this->info("Compressed: {$successCount}");
        $this->info("Skipped: {$skippedCount}");

        if ($failedCount > 0) {
            $this->error("Failed: {$failedCount}");
        }

        $this->info("Total saved: ".$this->formatBytes($totalSaved));
        $this->newLine();

        return self::SUCCESS;
    }

    private function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $i = 0;
        $size = (float) $bytes;

        while ($size >= 1024 && $i < count($units) - 1) {
            $size /= 1024;
            $i++;
        }

        return round($size, 1).' '.$units[$i];
    }
}
