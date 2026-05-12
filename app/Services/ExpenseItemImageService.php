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

    public function upload(UploadedFile $image): ExpenseItemImageDTO
    {
        $storedPath = $this->googleDriveService->upload($image, 'expense-items');

        if ($storedPath) {
            return ExpenseItemImageDTO::success(
                imageUrl: $this->googleDriveService->getUrl($storedPath),
                fileName: $image->getClientOriginalName(),
            );
        }

        $storedPath = $image->store('expense-items', 'public');

        if (! $storedPath) {
            return ExpenseItemImageDTO::failed('Gagal simpan gambar barang.');
        }

        return ExpenseItemImageDTO::success(
            imageUrl: rtrim(config('app.url', 'http://localhost'), '/') . '/expense-items/' . basename($storedPath),
            fileName: $image->getClientOriginalName(),
        );
    }
}
