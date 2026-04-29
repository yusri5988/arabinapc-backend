<?php

namespace App\DTOs;

readonly class ReceiptExtractionDTO
{
    public function __construct(
        public string $date,
        public float $amount,
        public string $description,
        public string $receiptUrl,
        public ?string $error = null,
    ) {
    }

    public static function fromArray(array $data, string $receiptUrl): self
    {
        return new self(
            date: (string) ($data['date'] ?? now()->format('Y-m-d')),
            amount: (float) ($data['amount'] ?? 0),
            description: (string) ($data['description'] ?? 'Perbelanjaan resit'),
            receiptUrl: $receiptUrl,
        );
    }

    public static function failed(string $receiptUrl, string $description, string $error): self
    {
        return new self(
            date: now()->format('Y-m-d'),
            amount: 0.00,
            description: $description,
            receiptUrl: $receiptUrl,
            error: $error,
        );
    }

    public function toArray(): array
    {
        return [
            'date' => $this->date,
            'amount' => $this->amount,
            'description' => $this->description,
            'receipt_url' => $this->receiptUrl,
            'error' => $this->error,
        ];
    }
}
