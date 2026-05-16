<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class GoogleDriveService
{
    /**
     * Upload a file to Google Drive.
     *
     * @return string|bool The file path on Google Drive or false on failure.
     */
    public function upload(UploadedFile $file, string $path = 'receipts', ?string $siteId = null)
    {
        try {
            $filename = time().'_'.$file->getClientOriginalName();

            if ($siteId !== null && $siteId !== '') {
                $siteId = preg_replace('/[^a-zA-Z0-9_\-]/', '', $siteId);
                $fullPath = $path.'/'.$siteId.'/'.$filename;
            } else {
                $fullPath = $path.'/'.$filename;
            }

            // Use the 'google' disk configured in filesystems.php
            $stored = Storage::disk('google')->put($fullPath, file_get_contents($file));

            if ($stored) {
                return $fullPath;
            }

            return false;
        } catch (\Exception $e) {
            \Log::error('Google Drive Upload Error: '.$e->getMessage());

            return false;
        }
    }

    /**
     * Get the public URL for a file on Google Drive.
     * Note: This requires the file to be shared publicly or using a specific URL format.
     */
    public function getUrl(string $path)
    {
        return Storage::disk('google')->url($path);
    }

    public function exists(string $path): bool
    {
        try {
            return Storage::disk('google')->exists($this->normalizePath($path));
        } catch (\Throwable $exception) {
            Log::warning('Google Drive file exists check failed.', [
                'path' => $this->normalizePath($path),
                'message' => $exception->getMessage(),
            ]);

            return false;
        }
    }

    public function uploadPublicFile(string $sourcePath, ?string $drivePath = null): bool
    {
        return $this->uploadFromDisk('public', $sourcePath, $drivePath ?? $sourcePath);
    }

    private function uploadFromDisk(string $sourceDisk, string $sourcePath, string $drivePath): bool
    {
        $sourcePath = $this->normalizePath($sourcePath);
        $drivePath = $this->normalizePath($drivePath);

        if (! Storage::disk($sourceDisk)->exists($sourcePath)) {
            Log::warning('Google Drive sync source file is missing.', [
                'disk' => $sourceDisk,
                'path' => $sourcePath,
            ]);

            return false;
        }

        for ($attempt = 1; $attempt <= 3; $attempt++) {
            $stream = null;

            try {
                $stream = Storage::disk($sourceDisk)->readStream($sourcePath);

                if ($stream === false) {
                    throw new \RuntimeException('Unable to open source file stream.');
                }

                $stored = Storage::disk('google')->put($drivePath, $stream);

                if ($stored) {
                    return true;
                }

                throw new \RuntimeException('Google Drive storage returned false.');
            } catch (\Throwable $exception) {
                Log::warning('Google Drive sync upload failed.', [
                    'attempt' => $attempt,
                    'source_disk' => $sourceDisk,
                    'source_path' => $sourcePath,
                    'drive_path' => $drivePath,
                    'message' => $exception->getMessage(),
                ]);

                if ($attempt === 3) {
                    return false;
                }

                sleep(2 ** ($attempt - 1));
            } finally {
                if (is_resource($stream)) {
                    fclose($stream);
                }
            }
        }

        return false;
    }

    private function normalizePath(string $path): string
    {
        return trim(str_replace('\\', '/', $path), '/');
    }
}
