<?php

namespace App\DTOs;

readonly class ExpenseItemImageDTO
{
    public function __construct(
        public string $imageUrl,
        public string $fileName,
        public ?string $error = null,
    ) {
    }

    public static function success(string $imageUrl, string $fileName): self
    {
        return new self(
            imageUrl: $imageUrl,
            fileName: $fileName,
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
