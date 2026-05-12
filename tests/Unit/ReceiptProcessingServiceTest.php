<?php

namespace Tests\Unit;

use App\DTOs\ReceiptExtractionDTO;
use App\Services\ReceiptProcessingService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ReceiptProcessingServiceTest extends TestCase
{
    public function test_process_returns_dto_when_api_key_missing(): void
    {
        config(['services.gemini.api_key' => '']);

        Storage::fake('public');
        $file = UploadedFile::fake()->image('receipt.jpg');

        $service = new ReceiptProcessingService();
        $result = $service->process($file);

        $this->assertInstanceOf(ReceiptExtractionDTO::class, $result);
        $this->assertEquals(0.00, $result->amount);
        $this->assertEquals('Sila tetapkan API Key Gemini', $result->description);
        $this->assertNotEmpty($result->receiptUrl);
    }

    public function test_process_returns_dto_with_gemini_response(): void
    {
        config(['services.gemini.api_key' => 'test-api-key']);

        Storage::fake('public');
        $file = UploadedFile::fake()->image('receipt.jpg');

        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [
                    [
                        'content' => [
                            'parts' => [
                                [
                                    'text' => '{"date": "2024-02-10", "amount": 35.50, "description": "Makan tengah hari"}',
                                ],
                            ],
                        ],
                    ],
                ],
            ], 200),
        ]);

        $service = new ReceiptProcessingService();
        $result = $service->process($file);

        $this->assertInstanceOf(ReceiptExtractionDTO::class, $result);
        $this->assertEquals('2024-02-10', $result->date);
        $this->assertEquals(35.50, $result->amount);
        $this->assertEquals('Makan tengah hari', $result->description);
    }

    public function test_process_handles_json_with_code_block(): void
    {
        config(['services.gemini.api_key' => 'test-api-key']);

        Storage::fake('public');
        $file = UploadedFile::fake()->image('receipt.jpg');

        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [
                    [
                        'content' => [
                            'parts' => [
                                [
                                    'text' => "```json\n{\"date\": \"2024-05-01\", \"amount\": 120.00, \"description\": \"Beli barang elektrik\"}\n```",
                                ],
                            ],
                        ],
                    ],
                ],
            ], 200),
        ]);

        $service = new ReceiptProcessingService();
        $result = $service->process($file);

        $this->assertEquals('2024-05-01', $result->date);
        $this->assertEquals(120.00, $result->amount);
        $this->assertEquals('Beli barang elektrik', $result->description);
    }

    public function test_process_returns_fallback_on_api_failure(): void
    {
        config(['services.gemini.api_key' => 'test-api-key']);

        Storage::fake('public');
        $file = UploadedFile::fake()->image('receipt.jpg');

        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response(['error' => 'Service unavailable'], 500),
        ]);

        $service = new ReceiptProcessingService();
        $result = $service->process($file);

        $this->assertInstanceOf(ReceiptExtractionDTO::class, $result);
        $this->assertEquals(0.00, $result->amount);
        $this->assertEquals('Gagal baca resit. Sila isi borang secara manual.', $result->description);
        $this->assertNotEmpty($result->receiptUrl);
        $this->assertNotEmpty($result->error);
    }

    public function test_process_returns_fallback_on_invalid_json(): void
    {
        config(['services.gemini.api_key' => 'test-api-key']);

        Storage::fake('public');
        $file = UploadedFile::fake()->image('receipt.jpg');

        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [
                    [
                        'content' => [
                            'parts' => [
                                [
                                    'text' => 'This is not valid JSON at all',
                                ],
                            ],
                        ],
                    ],
                ],
            ], 200),
        ]);

        $service = new ReceiptProcessingService();
        $result = $service->process($file);

        $this->assertInstanceOf(ReceiptExtractionDTO::class, $result);
        $this->assertEquals(0.00, $result->amount);
        $this->assertEquals('Gagal baca resit. Sila isi borang secara manual.', $result->description);
        $this->assertNotEmpty($result->receiptUrl);
        $this->assertNotEmpty($result->error);
    }

    public function test_process_stores_receipt_file(): void
    {
        config(['services.gemini.api_key' => 'test-api-key']);

        Storage::fake('public');
        $file = UploadedFile::fake()->image('receipt.jpg');

        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [
                    [
                        'content' => [
                            'parts' => [
                                [
                                    'text' => '{"date": "2024-01-01", "amount": 10.00, "description": "Test"}',
                                ],
                            ],
                        ],
                    ],
                ],
            ], 200),
        ]);

        $service = new ReceiptProcessingService();
        $result = $service->process($file);

        $files = Storage::disk('public')->files('receipts');
        $this->assertNotEmpty($files);
        $this->assertStringContainsString('receipts/', $result->receiptUrl);
    }
}
