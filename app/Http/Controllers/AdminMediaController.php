<?php

namespace App\Http\Controllers;

use App\Services\ActivityLogService;
use App\Services\ExpenseItemImageService;
use App\Services\ImageCompressionService;
use App\Services\ReceiptProcessingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class AdminMediaController extends Controller
{
    public function processReceipt(
        Request $request,
        ReceiptProcessingService $receiptProcessingService,
        ImageCompressionService $compressionService,
        ActivityLogService $log,
    ) {
        try {
            $request->validate([
                'receipt' => 'nullable|file|mimes:jpeg,png,jpg,webp,gif,pdf|max:15360',
                'receipts' => 'nullable|array',
                'receipts.*' => 'file|mimes:jpeg,png,jpg,webp,gif,pdf|max:15360',
                'site_id' => ['required', 'string', 'max:100', 'regex:/^[A-Za-z0-9_-]+$/'],
                'supervisor_id' => [
                    'required',
                    Rule::exists('users', 'id')->where('role', 'supervisor'),
                ],
            ]);

            if (! $request->hasFile('receipt') && ! $request->hasFile('receipts')) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'receipt' => ['Fail resit diperlukan.'],
                ]);
            }

            $log->log('admin_receipt.validation', 'success', [
                'admin_id' => $request->user()->id,
                'supervisor_id' => $request->input('supervisor_id'),
                'site_id' => $request->input('site_id'),
            ]);
        } catch (\Exception $e) {
            $log->log('admin_receipt.validation', 'fail', [
                'admin_id' => $request->user()->id,
                'supervisor_id' => $request->input('supervisor_id'),
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }

        try {
            $files = [];
            if ($request->hasFile('receipts')) {
                $receiptsInput = $request->file('receipts');
                $files = is_array($receiptsInput) ? $receiptsInput : [$receiptsInput];
            } elseif ($request->hasFile('receipt')) {
                $receiptInput = $request->file('receipt');
                $files = is_array($receiptInput) ? $receiptInput : [$receiptInput];
            }

            $storedItems = $receiptProcessingService->storeReceipts(
                $files,
                $request->input('site_id')
            );

            $receiptUrls = array_values(array_filter(array_column($storedItems, 'receiptUrl')));
            $primaryReceiptUrl = $receiptUrls[0] ?? '';

            $log->log('admin_receipt.store', 'success', [
                'admin_id' => $request->user()->id,
                'supervisor_id' => $request->input('supervisor_id'),
                'site_id' => $request->input('site_id'),
                'count' => count($storedItems),
            ]);
        } catch (\Exception $e) {
            $log->log('admin_receipt.store', 'fail', [
                'admin_id' => $request->user()->id,
                'supervisor_id' => $request->input('supervisor_id'),
                'site_id' => $request->input('site_id'),
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }

        try {
            $jobId = (string) Str::uuid();

            Cache::put("job_result:{$jobId}", ['status' => 'processing'], 300);

            $dto = $receiptProcessingService->processStored($storedItems);

            Cache::put("job_result:{$jobId}", [
                'status' => 'completed',
                'data' => $dto->toArray(),
            ], 300);

            foreach ($storedItems as $storedItem) {
                if (! ($storedItem['isPdf'] ?? false)) {
                    $compressionService->compress($storedItem['storedPath']);
                }
            }

            $log->log('admin_receipt.processed', 'success', [
                'admin_id' => $request->user()->id,
                'supervisor_id' => $request->input('supervisor_id'),
                'job_id' => $jobId,
            ]);
        } catch (\Throwable $e) {
            Cache::put("job_result:{$jobId}", [
                'status' => 'failed',
                'error' => 'Gagal baca resit. Sila isi borang secara manual.',
            ], 300);

            $log->log('admin_receipt.processing', 'fail', [
                'admin_id' => $request->user()->id,
                'supervisor_id' => $request->input('supervisor_id'),
                'job_id' => $jobId,
                'error' => $e->getMessage(),
            ]);
        }

        $log->log('admin_receipt.completed', 'success', [
            'admin_id' => $request->user()->id,
            'supervisor_id' => $request->input('supervisor_id'),
            'site_id' => $request->input('site_id'),
            'job_id' => $jobId,
        ]);

        return response()->json([
            'job_id' => $jobId,
            'receipt_url' => $primaryReceiptUrl,
            'receipt_urls' => $receiptUrls,
        ]);
    }

    public function processItemImage(
        Request $request,
        ExpenseItemImageService $expenseItemImageService,
        ActivityLogService $log,
    ) {
        try {
            $request->validate([
                'item_image' => 'required|image|max:15360',
                'site_id' => ['required', 'string', 'max:100', 'regex:/^[A-Za-z0-9_-]+$/'],
                'supervisor_id' => [
                    'required',
                    Rule::exists('users', 'id')->where('role', 'supervisor'),
                ],
            ]);
            $log->log('admin_item_image.validation', 'success', [
                'admin_id' => $request->user()->id,
                'supervisor_id' => $request->input('supervisor_id'),
                'site_id' => $request->input('site_id'),
            ]);
        } catch (\Exception $e) {
            $log->log('admin_item_image.validation', 'fail', [
                'admin_id' => $request->user()->id,
                'supervisor_id' => $request->input('supervisor_id'),
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }

        try {
            $imageData = $expenseItemImageService->upload(
                $request->file('item_image'),
                $request->input('site_id')
            );
            $log->log('admin_item_image.upload', 'success', [
                'admin_id' => $request->user()->id,
                'supervisor_id' => $request->input('supervisor_id'),
                'site_id' => $request->input('site_id'),
                'file_name' => $imageData->fileName ?? null,
            ]);
        } catch (\Exception $e) {
            $log->log('admin_item_image.upload', 'fail', [
                'admin_id' => $request->user()->id,
                'supervisor_id' => $request->input('supervisor_id'),
                'site_id' => $request->input('site_id'),
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }

        $log->log('admin_item_image.completed', 'success', [
            'admin_id' => $request->user()->id,
            'supervisor_id' => $request->input('supervisor_id'),
            'site_id' => $request->input('site_id'),
        ]);

        return response()->json($imageData->toArray());
    }

    public function receiptStatus(string $jobId)
    {
        $result = Cache::get("job_result:{$jobId}");

        if (! $result) {
            return response()->json(['status' => 'not_found'], 404);
        }

        return response()->json($result);
    }
}
