<?php

namespace App\DTOs;

final readonly class GoogleDriveImageSyncResultDTO
{
    public function __construct(
        public int $scanned,
        public int $uploaded,
        public int $skipped,
        /** @var array<string, string> */
        public array $failures,
    ) {}

    public function failed(): int
    {
        return count($this->failures);
    }

    public function hasFailures(): bool
    {
        return $this->failed() > 0;
    }

    public function toArray(): array
    {
        return [
            'scanned' => $this->scanned,
            'uploaded' => $this->uploaded,
            'skipped' => $this->skipped,
            'failed' => $this->failed(),
            'failures' => $this->failures,
        ];
    }
}
