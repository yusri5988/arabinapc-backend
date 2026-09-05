<?php

namespace Tests\Feature;

use App\Models\Transaction;
use App\Models\User;
use App\Services\ExcelExportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

class ExcelExportRemarkTest extends TestCase
{
    use RefreshDatabase;

    public function test_excel_export_all_contains_remark_column(): void
    {
        $supervisor = User::factory()->supervisor()->create(['name' => 'John Doe']);
        $admin = User::factory()->admin()->create(['name' => 'Admin Arabina']);

        Transaction::create([
            'user_id' => $supervisor->id,
            'type' => 'topup',
            'amount' => 500.00,
            'payment_to' => 'Supervisor Topup',
            'details' => 'Topup tapak projek Cyberjaya',
            'description' => 'Duit diterima daripada Admin: Admin Arabina (Topup tapak projek Cyberjaya)',
            'date' => now(),
            'metadata' => [
                'source' => 'admin_send_to_supervisor',
                'sent_by_user_id' => $admin->id,
                'remark' => 'Topup tapak projek Cyberjaya',
            ],
        ]);

        $service = app(ExcelExportService::class);
        $exportResult = $service->generateAll();

        $this->assertFileExists($exportResult['file_path']);

        $spreadsheet = IOFactory::load($exportResult['file_path']);
        $sheet = $spreadsheet->getActiveSheet();

        // Check Header Row
        $this->assertEquals('Staff', $sheet->getCell('A1')->getValue());
        $this->assertEquals('Date Receipt', $sheet->getCell('B1')->getValue());
        $this->assertEquals('Date Created', $sheet->getCell('C1')->getValue());
        $this->assertEquals('Payment To', $sheet->getCell('D1')->getValue());
        $this->assertEquals('Details', $sheet->getCell('E1')->getValue());
        $this->assertEquals('Remark', $sheet->getCell('F1')->getValue());
        $this->assertEquals('Money Out', $sheet->getCell('G1')->getValue());
        $this->assertEquals('Money In', $sheet->getCell('H1')->getValue());

        // Check Row 2 Data
        $this->assertEquals('John Doe', $sheet->getCell('A2')->getValue());
        $this->assertEquals(now()->format('d/m/Y'), $sheet->getCell('B2')->getValue());
        $this->assertEquals(now()->format('d/m/Y H:i'), $sheet->getCell('C2')->getValue());
        $this->assertEquals('Supervisor Topup', $sheet->getCell('D2')->getValue());
        $this->assertEquals('Topup tapak projek Cyberjaya', $sheet->getCell('F2')->getValue());
        $this->assertEquals('500.00', $sheet->getCell('H2')->getValue());

        if (file_exists($exportResult['file_path'])) {
            unlink($exportResult['file_path']);
        }
    }

    public function test_excel_export_single_supervisor_contains_remark_column(): void
    {
        $supervisor = User::factory()->supervisor()->create(['name' => 'Jane Smith']);
        $admin = User::factory()->admin()->create(['name' => 'Admin Arabina']);

        Transaction::create([
            'user_id' => $supervisor->id,
            'type' => 'topup',
            'amount' => 350.00,
            'payment_to' => 'Supervisor Topup',
            'details' => 'Duit kecemasan',
            'description' => 'Duit diterima daripada Admin: Admin Arabina (Duit kecemasan)',
            'date' => now(),
            'metadata' => [
                'remark' => 'Duit kecemasan',
            ],
        ]);

        $service = app(ExcelExportService::class);
        $exportResult = $service->generate($supervisor->id);

        $this->assertFileExists($exportResult['file_path']);

        $spreadsheet = IOFactory::load($exportResult['file_path']);
        $sheet = $spreadsheet->getActiveSheet();

        // Check Header Row
        $this->assertEquals('Date Receipt', $sheet->getCell('A1')->getValue());
        $this->assertEquals('Date Created', $sheet->getCell('B1')->getValue());
        $this->assertEquals('Payment To', $sheet->getCell('C1')->getValue());
        $this->assertEquals('Details', $sheet->getCell('D1')->getValue());
        $this->assertEquals('Remark', $sheet->getCell('E1')->getValue());

        // Check Row 2 Data
        $this->assertEquals(now()->format('d/m/Y'), $sheet->getCell('A2')->getValue());
        $this->assertEquals(now()->format('d/m/Y H:i'), $sheet->getCell('B2')->getValue());
        $this->assertEquals('Duit kecemasan', $sheet->getCell('E2')->getValue());

        if (file_exists($exportResult['file_path'])) {
            unlink($exportResult['file_path']);
        }
    }
}
