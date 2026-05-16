<?php

namespace App\Services;

use App\DTOs\ExpenseItemImageDTO;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

class ExpenseItemImageService
{
    public function __construct(
        protected GoogleDriveService $googleDriveService
    ) {}

    public function upload(UploadedFile $image, ?string $siteId = null): ExpenseItemImageDTO
    {
        $storedPath = $this->googleDriveService->upload($image, 'expense-items', $siteId);

        if ($storedPath) {
            return ExpenseItemImageDTO::success(
                imageUrl: $this->googleDriveService->getUrl($storedPath),
                fileName: $image->getClientOriginalName(),
            );
        }

        $localPath = $siteId !== null && $siteId !== ''
            ? 'expense-items/' . preg_replace('/[^a-zA-Z0-9_\-]/', '', $siteId)
            : 'expense-items';
        $storedPath = $image->store($localPath, 'public');

        if (! $storedPath) {
            return ExpenseItemImageDTO::failed('Gagal simpan gambar barang.');
        }

        return ExpenseItemImageDTO::success(
            imageUrl: Storage::disk('public')->url($storedPath),
            fileName: $image->getClientOriginalName(),
        );
    }
}
