<?php

namespace Tests\Unit;

use App\Services\GoogleDriveImageSyncService;
use App\Services\GoogleDriveService;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class GoogleDriveImageSyncServiceTest extends TestCase
{
    public function test_sync_uploads_site_receipt_and_expense_item_images(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('receipts/SITE1/receipt.jpg', 'receipt');
        Storage::disk('public')->put('expense-items/SITE2/item.jpg', 'item');
        Storage::disk('public')->put('receipts/root-receipt.jpg', 'ignored');

        [$service, $driveService] = $this->syncService();

        $result = $service->sync();

        $this->assertSame(2, $result->scanned);
        $this->assertSame(2, $result->uploaded);
        $this->assertSame(0, $result->skipped);
        $this->assertSame(0, $result->failed());
        $this->assertSame([
            ['receipts/SITE1/receipt.jpg', 'receipts/SITE1/receipt.jpg'],
            ['expense-items/SITE2/item.jpg', 'expense-items/SITE2/item.jpg'],
        ], $driveService->uploaded);
    }

    public function test_sync_skips_files_that_already_exist_on_google_drive(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('receipts/SITE1/receipt.jpg', 'receipt');

        [$service, $driveService] = $this->syncService([
            'receipts/SITE1/receipt.jpg',
        ]);

        $result = $service->sync();

        $this->assertSame(1, $result->scanned);
        $this->assertSame(0, $result->uploaded);
        $this->assertSame(1, $result->skipped);
        $this->assertSame(0, $result->failed());
        $this->assertSame([], $driveService->uploaded);
    }

    public function test_sync_records_failures_and_continues_with_other_files(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('receipts/SITE1/fail.jpg', 'receipt');
        Storage::disk('public')->put('expense-items/SITE2/item.jpg', 'item');

        [$service, $driveService] = $this->syncService([], [
            'receipts/SITE1/fail.jpg',
        ]);

        $result = $service->sync();

        $this->assertSame(2, $result->scanned);
        $this->assertSame(1, $result->uploaded);
        $this->assertSame(0, $result->skipped);
        $this->assertSame(1, $result->failed());
        $this->assertArrayHasKey('receipts/SITE1/fail.jpg', $result->failures);
        $this->assertSame([
            ['expense-items/SITE2/item.jpg', 'expense-items/SITE2/item.jpg'],
        ], $driveService->uploaded);
    }

    private function syncService(array $existing = [], array $failedUploads = []): array
    {
        $driveService = new class($existing, $failedUploads) extends GoogleDriveService
        {
            public array $uploaded = [];

            public function __construct(
                private array $existing,
                private array $failedUploads,
            ) {}

            public function exists(string $path): bool
            {
                return in_array($path, $this->existing, true);
            }

            public function uploadPublicFile(string $sourcePath, ?string $drivePath = null): bool
            {
                if (in_array($sourcePath, $this->failedUploads, true)) {
                    return false;
                }

                $this->uploaded[] = [$sourcePath, $drivePath];

                return true;
            }
        };

        return [new GoogleDriveImageSyncService($driveService), $driveService];
    }
}
