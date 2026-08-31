<?php

namespace App\DTOs;

readonly class ReceiptExtractionDTO
{
    public function __construct(
        public string $date,
        public float $amount,
        public string $description,
        public string $paymentTo,
        public string $receiptUrl,
        public array $receiptUrls = [],
        public ?string $error = null,
    ) {
    }

    public static function fromArray(array $data, string $receiptUrl, array $receiptUrls = []): self
    {
        $urls = ! empty($receiptUrls) ? $receiptUrls : ($receiptUrl !== '' ? [$receiptUrl] : []);

        return new self(
            date: (string) ($data['date'] ?? now()->format('Y-m-d')),
            amount: (float) ($data['amount'] ?? 0),
            description: (string) ($data['description'] ?? 'Perbelanjaan resit'),
            paymentTo: (string) ($data['payment_to'] ?? $data['paymentTo'] ?? ''),
            receiptUrl: $receiptUrl,
            receiptUrls: $urls,
        );
    }

    public static function failed(string $receiptUrl, string $description, string $error, array $receiptUrls = []): self
    {
        $urls = ! empty($receiptUrls) ? $receiptUrls : ($receiptUrl !== '' ? [$receiptUrl] : []);

        return new self(
            date: now()->format('Y-m-d'),
            amount: 0.00,
            description: $description,
            paymentTo: '',
            receiptUrl: $receiptUrl,
            receiptUrls: $urls,
            error: $error,
        );
    }

    public function toArray(): array
    {
        $urls = ! empty($this->receiptUrls) ? $this->receiptUrls : ($this->receiptUrl !== '' ? [$this->receiptUrl] : []);

        return [
            'date' => $this->date,
            'amount' => $this->amount,
            'description' => $this->description,
            'payment_to' => $this->paymentTo,
            'receipt_url' => $this->receiptUrl,
            'receipt_urls' => $urls,
            'error' => $this->error,
        ];
    }
}
