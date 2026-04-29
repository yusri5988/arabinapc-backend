<?php

namespace Tests\Unit;

use App\DTOs\ReceiptExtractionDTO;
use PHPUnit\Framework\TestCase;

class ReceiptExtractionDTOTest extends TestCase
{
    public function test_from_array_with_complete_data(): void
    {
        $data = [
            'date' => '2024-01-15',
            'amount' => 25.50,
            'description' => 'Makanan tapak',
        ];

        $dto = ReceiptExtractionDTO::fromArray($data, 'https://example.com/receipt.jpg');

        $this->assertEquals('2024-01-15', $dto->date);
        $this->assertEquals(25.50, $dto->amount);
        $this->assertEquals('Makanan tapak', $dto->description);
        $this->assertEquals('https://example.com/receipt.jpg', $dto->receiptUrl);
    }

    public function test_from_array_with_missing_data_uses_defaults(): void
    {
        $dto = ReceiptExtractionDTO::fromArray([], 'https://example.com/receipt.jpg');

        $this->assertNotEmpty($dto->date);
        $this->assertEquals(0.0, $dto->amount);
        $this->assertEquals('Perbelanjaan resit', $dto->description);
        $this->assertEquals('https://example.com/receipt.jpg', $dto->receiptUrl);
    }

    public function test_from_array_with_partial_data(): void
    {
        $data = [
            'amount' => 100.00,
        ];

        $dto = ReceiptExtractionDTO::fromArray($data, 'https://example.com/receipt.jpg');

        $this->assertNotEmpty($dto->date);
        $this->assertEquals(100.00, $dto->amount);
        $this->assertEquals('Perbelanjaan resit', $dto->description);
    }

    public function test_to_array_returns_correct_structure(): void
    {
        $dto = new ReceiptExtractionDTO(
            date: '2024-03-20',
            amount: 50.75,
            description: 'Beli barang dapur',
            receiptUrl: '/storage/receipts/test.jpg',
        );

        $array = $dto->toArray();

        $this->assertEquals([
            'date' => '2024-03-20',
            'amount' => 50.75,
            'description' => 'Beli barang dapur',
            'receipt_url' => '/storage/receipts/test.jpg',
        ], $array);
    }

    public function test_amount_cast_to_float(): void
    {
        $data = [
            'amount' => '75.30',
        ];

        $dto = ReceiptExtractionDTO::fromArray($data, 'https://example.com/receipt.jpg');

        $this->assertIsFloat($dto->amount);
        $this->assertEquals(75.30, $dto->amount);
    }
}
