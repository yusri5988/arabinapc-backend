<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class ImageCompressionService
{
    private const MAX_DIMENSION = 1920;

    private const JPEG_QUALITY = 75;

    private const MIN_SIZE_TO_COMPRESS = 500000; // 500KB

    private const TIMEOUT_SECONDS = 3;

    private const SUPPORTED_MIME_TYPES = [
        'image/jpeg',
        'image/png',
        'image/gif',
        'image/webp',
    ];

    public function compress(string $storedPath, string $disk = 'public'): array
    {
        $startTime = microtime(true);

        if (! $this->shouldCompress($storedPath, $disk)) {
            return [
                'success' => false,
                'action' => 'skipped',
                'reason' => 'File does not meet compression criteria',
            ];
        }

        try {
            $fileContent = Storage::disk($disk)->get($storedPath);
            $mimeType = Storage::disk($disk)->mimeType($storedPath);

            $image = $this->createImageFromContent($fileContent, $mimeType);

            if ($image === null) {
                Log::warning('Image compression: cannot create image resource.', [
                    'path' => $storedPath,
                ]);

                return [
                    'success' => false,
                    'action' => 'skipped',
                    'reason' => 'Cannot create image resource',
                ];
            }

            $width = imagesx($image);
            $height = imagesy($image);
            $originalSize = Storage::disk($disk)->size($storedPath);

            [$newWidth, $newHeight] = $this->calculateDimensions($width, $height);

            $resized = imagecreatetruecolor($newWidth, $newHeight);

            if ($resized === false) {
                imagedestroy($image);

                return [
                    'success' => false,
                    'action' => 'skipped',
                    'reason' => 'Cannot create resized image resource',
                ];
            }

            imagecopyresampled($resized, $image, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);
            imagedestroy($image);

            $tempPath = tempnam(sys_get_temp_dir(), 'receipt_compress_');

            $this->outputImage($resized, $tempPath);
            imagedestroy($resized);

            $newSize = filesize($tempPath);

            if ($newSize >= $originalSize) {
                @unlink($tempPath);

                return [
                    'success' => false,
                    'action' => 'skipped',
                    'reason' => 'Compressed file is not smaller',
                ];
            }

            $compressedContent = file_get_contents($tempPath);
            @unlink($tempPath);

            Storage::disk($disk)->put($storedPath, $compressedContent);

            $elapsed = microtime(true) - $startTime;

            if ($elapsed > self::TIMEOUT_SECONDS) {
                Log::warning('Image compression: took too long, but completed.', [
                    'path' => $storedPath,
                    'elapsed' => round($elapsed, 2),
                ]);
            }

            $savedPercent = round((1 - ($newSize / $originalSize)) * 100, 1);

            Log::info('Image compression: success.', [
                'path' => $storedPath,
                'original_size' => $this->formatBytes($originalSize),
                'new_size' => $this->formatBytes($newSize),
                'saved' => "{$savedPercent}%",
                'elapsed' => round($elapsed, 2),
            ]);

            return [
                'success' => true,
                'action' => 'compressed',
                'original_size' => $originalSize,
                'new_size' => $newSize,
                'saved_percent' => $savedPercent,
            ];
        } catch (\Throwable $e) {
            Log::error('Image compression: failed.', [
                'path' => $storedPath,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'action' => 'failed',
                'reason' => $e->getMessage(),
            ];
        }
    }

    public function shouldCompress(string $storedPath, string $disk = 'public'): bool
    {
        if (! Storage::disk($disk)->exists($storedPath)) {
            return false;
        }

        $mimeType = Storage::disk($disk)->mimeType($storedPath);

        if (! in_array($mimeType, self::SUPPORTED_MIME_TYPES, true)) {
            return false;
        }

        $size = Storage::disk($disk)->size($storedPath);

        return $size > self::MIN_SIZE_TO_COMPRESS;
    }

    private function createImageFromContent(string $content, string $mimeType): ?\GdImage
    {
        return @imagecreatefromstring($content);
    }

    private function calculateDimensions(int $width, int $height): array
    {
        if ($width <= self::MAX_DIMENSION && $height <= self::MAX_DIMENSION) {
            return [$width, $height];
        }

        $ratio = min(self::MAX_DIMENSION / $width, self::MAX_DIMENSION / $height);

        return [
            (int) round($width * $ratio),
            (int) round($height * $ratio),
        ];
    }

    private function outputImage(\GdImage $image, string $path): void
    {
        imagejpeg($image, $path, self::JPEG_QUALITY);
    }

    private function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $i = 0;
        $size = (float) $bytes;

        while ($size >= 1024 && $i < count($units) - 1) {
            $size /= 1024;
            $i++;
        }

        return round($size, 1).' '.$units[$i];
    }
}
