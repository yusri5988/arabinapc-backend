<?php

namespace App\DTOs;

readonly class ExpenseItemImageDTO
{
    public function __construct(
        public string $imageUrl,
        public string $fileName,
        public string $storedPath = '',
        public ?string $error = null,
    ) {}

    public static function success(string $imageUrl, string $fileName, string $storedPath = ''): self
    {
        return new self(
            imageUrl: $imageUrl,
            fileName: $fileName,
            storedPath: $storedPath,
        );
    }

    public static function failed(string $error): self
    {
        return new self(
            imageUrl: '',
            fileName: '',
            error: $error,
        );
    }

    public function toArray(): array
    {
        return [
            'image_url' => $this->imageUrl,
            'file_name' => $this->fileName,
            'error' => $this->error,
        ];
    }
}
