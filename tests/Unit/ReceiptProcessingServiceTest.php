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
    private function service(): ReceiptProcessingService
    {
        return new ReceiptProcessingService;
    }

    public function test_process_returns_dto_when_api_key_missing(): void
    {
        config(['services.claude.api_key' => '']);

        Storage::fake('public');
        $file = UploadedFile::fake()->image('receipt.jpg');

        $stored = $this->service()->storeReceipt($file);
        $result = $this->service()->processStored($stored['storedPath'], $stored['receiptUrl']);

        $this->assertInstanceOf(ReceiptExtractionDTO::class, $result);
        $this->assertEquals(0.00, $result->amount);
        $this->assertEquals('Sila tetapkan API Key Claude', $result->description);
        $this->assertNotEmpty($result->receiptUrl);
    }

    public function test_process_returns_dto_with_claude_response(): void
    {
        config(['services.claude.api_key' => 'test-api-key']);

        Storage::fake('public');
        $file = UploadedFile::fake()->image('receipt.jpg');

        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'id' => 'msg_abc123',
                'type' => 'message',
                'role' => 'assistant',
                'content' => [
                    [
                        'type' => 'text',
                        'text' => '{"date": "2024-02-10", "amount": 35.50, "payment_to": "Kedai Ali", "description": "Makan tengah hari"}',
                    ],
                ],
                'stop_reason' => 'end_turn',
            ], 200),
        ]);

        $stored = $this->service()->storeReceipt($file);
        $result = $this->service()->processStored($stored['storedPath'], $stored['receiptUrl']);

        $this->assertInstanceOf(ReceiptExtractionDTO::class, $result);
        $this->assertEquals('2024-02-10', $result->date);
        $this->assertEquals(35.50, $result->amount);
        $this->assertEquals('Kedai Ali', $result->paymentTo);
        $this->assertEquals('Makan tengah hari', $result->description);
    }

    public function test_process_handles_json_with_code_block(): void
    {
        config(['services.claude.api_key' => 'test-api-key']);

        Storage::fake('public');
        $file = UploadedFile::fake()->image('receipt.jpg');

        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'id' => 'msg_abc123',
                'type' => 'message',
                'role' => 'assistant',
                'content' => [
                    [
                        'type' => 'text',
                        'text' => "```json\n{\"date\": \"2024-05-01\", \"amount\": 120.00, \"payment_to\": \"Hardware ABC\", \"description\": \"Beli barang elektrik\"}\n```",
                    ],
                ],
                'stop_reason' => 'end_turn',
            ], 200),
        ]);

        $stored = $this->service()->storeReceipt($file);
        $result = $this->service()->processStored($stored['storedPath'], $stored['receiptUrl']);

        $this->assertEquals('2024-05-01', $result->date);
        $this->assertEquals(120.00, $result->amount);
        $this->assertEquals('Hardware ABC', $result->paymentTo);
        $this->assertEquals('Beli barang elektrik', $result->description);
    }

    public function test_process_returns_fallback_on_api_failure(): void
    {
        config(['services.claude.api_key' => 'test-api-key']);

        Storage::fake('public');
        $file = UploadedFile::fake()->image('receipt.jpg');

        Http::fake([
            'api.anthropic.com/*' => Http::response(['error' => ['message' => 'Service unavailable']], 500),
        ]);

        $stored = $this->service()->storeReceipt($file);
        $result = $this->service()->processStored($stored['storedPath'], $stored['receiptUrl']);

        $this->assertInstanceOf(ReceiptExtractionDTO::class, $result);
        $this->assertEquals(0.00, $result->amount);
        $this->assertEquals('Gagal baca resit. Sila isi borang secara manual.', $result->description);
        $this->assertNotEmpty($result->receiptUrl);
        $this->assertNotEmpty($result->error);
    }

    public function test_process_returns_fallback_on_invalid_json(): void
    {
        config(['services.claude.api_key' => 'test-api-key']);

        Storage::fake('public');
        $file = UploadedFile::fake()->image('receipt.jpg');

        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'id' => 'msg_abc123',
                'type' => 'message',
                'role' => 'assistant',
                'content' => [
                    [
                        'type' => 'text',
                        'text' => 'This is not valid JSON at all',
                    ],
                ],
                'stop_reason' => 'end_turn',
            ], 200),
        ]);

        $stored = $this->service()->storeReceipt($file);
        $result = $this->service()->processStored($stored['storedPath'], $stored['receiptUrl']);

        $this->assertInstanceOf(ReceiptExtractionDTO::class, $result);
        $this->assertEquals(0.00, $result->amount);
        $this->assertEquals('Gagal baca resit. Sila isi borang secara manual.', $result->description);
        $this->assertNotEmpty($result->receiptUrl);
        $this->assertNotEmpty($result->error);
    }

    public function test_process_stores_receipt_file(): void
    {
        config(['services.claude.api_key' => 'test-api-key']);

        Storage::fake('public');
        $file = UploadedFile::fake()->image('receipt.jpg');

        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'id' => 'msg_abc123',
                'type' => 'message',
                'role' => 'assistant',
                'content' => [
                    [
                        'type' => 'text',
                        'text' => '{"date": "2024-01-01", "amount": 10.00, "description": "Test"}',
                    ],
                ],
                'stop_reason' => 'end_turn',
            ], 200),
        ]);

        $stored = $this->service()->storeReceipt($file);
        $result = $this->service()->processStored($stored['storedPath'], $stored['receiptUrl']);

        $files = Storage::disk('public')->files('receipts');
        $this->assertNotEmpty($files);
        $this->assertStringContainsString('receipts/', $result->receiptUrl);
    }
}
