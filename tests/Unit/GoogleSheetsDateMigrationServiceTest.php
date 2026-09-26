<?php

namespace Tests\Unit;

use App\Services\GoogleSheetsDateFormatter;
use App\Services\GoogleSheetsDateMigrationService;
use App\Services\GoogleSheetsService;
use Tests\TestCase;

class GoogleSheetsDateMigrationServiceTest extends TestCase
{
    public function test_it_migrates_existing_date_columns_to_text(): void
    {
        $headers = array_fill(0, 19, '');
        $headers[2] = 'Date Receipt';
        $headers[3] = 'Date Created';

        $row = array_fill(0, 19, '');
        $row[2] = '02/07/2026';
        $row[3] = '02/07/2026 14:30';

        $sheets = new class([$headers, $row]) extends GoogleSheetsService
        {
            public array $updates = [];

            public function __construct(private array $values) {}

            public function read(string $range): array
            {
                return $this->values;
            }

            public function updateRanges(array $updates): void
            {
                $this->updates = $updates;
            }
        };

        config()->set('services.google_sheets.enabled', true);
        config()->set('services.google_sheets.tab_name', 'MainData-PC System');
        config()->set('services.google_sheets.max_rows', 5000);

        $service = new GoogleSheetsDateMigrationService($sheets, new GoogleSheetsDateFormatter);
        $result = $service->migrate();

        $this->assertSame(1, $result['scanned']);
        $this->assertSame(2, $result['updated_cells']);
        $this->assertSame(0, $result['skipped_unrecognised']);
        $this->assertSame([
            [
                'range' => "'MainData-PC System'!C2",
                'values' => [["'2 July 2026"]],
            ],
            [
                'range' => "'MainData-PC System'!D2",
                'values' => [["'2 July 2026 14:30"]],
            ],
        ], $sheets->updates);
    }

    public function test_dry_run_does_not_write_updates(): void
    {
        $headers = array_fill(0, 19, '');
        $headers[2] = 'Date Receipt';
        $headers[3] = 'Date Created';
        $row = array_fill(0, 19, '');
        $row[2] = '02/07/2026';

        $sheets = new class([$headers, $row]) extends GoogleSheetsService
        {
            public int $updateCalls = 0;

            public function __construct(private array $values) {}

            public function read(string $range): array
            {
                return $this->values;
            }

            public function updateRanges(array $updates): void
            {
                $this->updateCalls++;
            }
        };

        $service = new GoogleSheetsDateMigrationService($sheets, new GoogleSheetsDateFormatter);
        $result = $service->migrate(true);

        $this->assertSame(1, $result['updated_cells']);
        $this->assertSame(0, $sheets->updateCalls);
    }
}
