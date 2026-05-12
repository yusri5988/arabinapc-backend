<?php

namespace App\Services;

use Illuminate\Support\Facades\Storage;
use Illuminate\Http\UploadedFile;

class GoogleDriveService
{
    /**
     * Upload a file to Google Drive.
     *
     * @param UploadedFile $file
     * @param string $path
     * @return string|bool The file path on Google Drive or false on failure.
     */
    public function upload(UploadedFile $file, string $path = 'receipts')
    {
        try {
            $filename = time() . '_' . $file->getClientOriginalName();
            $fullPath = $path . '/' . $filename;

            // Use the 'google' disk configured in filesystems.php
            $stored = Storage::disk('google')->put($fullPath, file_get_contents($file));

            if ($stored) {
                return $fullPath;
            }

            return false;
        } catch (\Exception $e) {
            \Log::error('Google Drive Upload Error: ' . $e->getMessage());
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
}
