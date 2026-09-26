<?php

namespace App\Console\Commands;

use App\Services\GoogleSheetsDateMigrationService;
use Illuminate\Console\Command;

class MigrateGoogleSheetDates extends Command
{
    protected $signature = 'transactions:migrate-google-sheet-dates {--dry-run : Semak perubahan tanpa menulis ke Google Sheets}';

    protected $description = 'Convert existing Google Sheet date fields to text values.';

    public function handle(GoogleSheetsDateMigrationService $migrationService): int
    {
        try {
            $result = $migrationService->migrate((bool) $this->option('dry-run'));
        } catch (\Throwable $exception) {
            $this->error('Migration Google Sheets gagal: '.$exception->getMessage());

            return self::FAILURE;
        }

        $mode = $this->option('dry-run') ? 'Dry run' : 'Migration';
        $this->info(sprintf(
            '%s selesai. Scanned %d row, updated %d date cell, skipped %d unrecognised value.',
            $mode,
            $result['scanned'],
            $result['updated_cells'],
            $result['skipped_unrecognised'],
        ));

        return self::SUCCESS;
    }
}
