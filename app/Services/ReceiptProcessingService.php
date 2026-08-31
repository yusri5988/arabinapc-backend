<?php

namespace App\Services;

use App\DTOs\ReceiptExtractionDTO;
use Carbon\Carbon;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class ReceiptProcessingService
{
    /**
     * Store a single receipt file.
     *
     * @return array{storedPath: string, receiptUrl: string}
     */
    public function storeReceipt(UploadedFile $receipt, ?string $siteId = null): array
    {
        $stored = $this->storeReceipts([$receipt], $siteId);

        return $stored[0] ?? [
            'storedPath' => '',
            'receiptUrl' => '',
            'mimeType' => '',
            'isPdf' => false,
        ];
    }

    /**
     * Store multiple receipt files (images or PDFs).
     *
     * @param array<int, UploadedFile>|UploadedFile $receipts
     * @return array<int, array{storedPath: string, receiptUrl: string, mimeType: string, isPdf: bool}>
     */
    public function storeReceipts(array|UploadedFile $receipts, ?string $siteId = null): array
    {
        $files = is_array($receipts) ? $receipts : [$receipts];
        $results = [];

        $localPath = $siteId !== null && $siteId !== ''
            ? 'receipts/'.preg_replace('/[^a-zA-Z0-9_\-]/', '', $siteId)
            : 'receipts';

        foreach ($files as $file) {
            if (! $file instanceof UploadedFile) {
                continue;
            }

            $storedPath = $file->store($localPath, 'public');
            $receiptUrl = Storage::disk('public')->url($storedPath);
            $mimeType = Storage::disk('public')->mimeType($storedPath) ?: ($file->getMimeType() ?: 'application/octet-stream');
            $isPdf = strtolower($file->getClientOriginalExtension()) === 'pdf' || $mimeType === 'application/pdf';

            $results[] = [
                'storedPath' => $storedPath,
                'receiptUrl' => $receiptUrl,
                'mimeType' => $mimeType,
                'isPdf' => $isPdf,
            ];
        }

        return $results;
    }

    /**
     * Process stored receipt(s).
     *
     * @param array<int, array{storedPath: string, receiptUrl?: string}>|string $stored
     * @param string|null $receiptUrl
     */
    public function processStored(array|string $stored, ?string $receiptUrl = null): ReceiptExtractionDTO
    {
        $items = is_array($stored)
            ? $stored
            : [['storedPath' => $stored, 'receiptUrl' => $receiptUrl ?? '']];

        $receiptUrls = [];
        foreach ($items as $item) {
            if (! empty($item['receiptUrl'])) {
                $receiptUrls[] = $item['receiptUrl'];
            }
        }
        $primaryReceiptUrl = $receiptUrls[0] ?? ($receiptUrl ?? '');

        $apiKey = (string) config('services.claude.api_key');

        if ($apiKey === '') {
            return ReceiptExtractionDTO::failed(
                receiptUrl: $primaryReceiptUrl,
                description: 'Sila tetapkan API Key Claude',
                error: 'CLAUDE_API_KEY tidak ditetapkan dalam .env.',
                receiptUrls: $receiptUrls,
            );
        }

        $contentBlocks = [];

        foreach ($items as $item) {
            $path = $item['storedPath'] ?? '';
            if ($path === '' || ! Storage::disk('public')->exists($path)) {
                continue;
            }

            $fileContent = Storage::disk('public')->get($path);
            $mimeType = Storage::disk('public')->mimeType($path) ?: 'application/octet-stream';
            $isPdf = str_ends_with(strtolower($path), '.pdf') || $mimeType === 'application/pdf';

            if ($fileContent === null || $fileContent === false) {
                continue;
            }

            if ($isPdf) {
                $contentBlocks[] = [
                    'type' => 'document',
                    'source' => [
                        'type' => 'base64',
                        'media_type' => 'application/pdf',
                        'data' => base64_encode($fileContent),
                    ],
                ];
            } else {
                $contentBlocks[] = [
                    'type' => 'image',
                    'source' => [
                        'type' => 'base64',
                        'media_type' => $mimeType,
                        'data' => base64_encode($fileContent),
                    ],
                ];
            }
        }

        if (empty($contentBlocks)) {
            return ReceiptExtractionDTO::failed(
                receiptUrl: $primaryReceiptUrl,
                description: 'Gagal baca resit. Fail tidak wujud.',
                error: 'Fail resit tidak ditemui di storage.',
                receiptUrls: $receiptUrls,
            );
        }

        $promptText = count($contentBlocks) > 1
            ? "Extract the Date, Total Amount, Payment To / Payee, and a short Description (in Malay) from these receipt images/documents for this transaction. If multiple images or documents are provided (e.g. multi-page receipt or separate bills for this expense), consolidate them to find the true Date, final Grand Total Amount, Payment To, and summary Description. Return ONLY a valid JSON object with keys: date (YYYY-MM-DD), amount (float), payment_to (string), description (string). If you can't find something, use today's date, 0.00, or an empty string."
            : "Extract the Date, Total Amount, Payment To / Payee, and a short Description (in Malay) from this receipt. Return ONLY a valid JSON object with keys: date (YYYY-MM-DD), amount (float), payment_to (string), description (string). If you can't find something, use today's date, 0.00, or an empty string.";

        $contentBlocks[] = [
            'type' => 'text',
            'text' => $promptText,
        ];

        $response = $this->sendExtractionRequestWithBlocks($contentBlocks, $apiKey);

        if ($response === null) {
            Log::warning('Receipt extraction failed after retries.');

            return ReceiptExtractionDTO::failed(
                receiptUrl: $primaryReceiptUrl,
                description: 'Gagal baca resit. Sila isi borang secara manual.',
                error: 'Tidak dapat hubungi Claude API (connection error selepas 3 percubaan).',
                receiptUrls: $receiptUrls,
            );
        }

        if ($response->failed()) {
            $status = $response->status();
            $body = $response->json();
            $errorMessage = data_get($body, 'error.message', 'Unknown error');

            Log::warning('Receipt extraction request failed.', [
                'status' => $status,
                'body' => $body,
            ]);

            if ($status === 429) {
                return ReceiptExtractionDTO::failed(
                    receiptUrl: $primaryReceiptUrl,
                    description: 'Gagal baca resit. Sila isi borang secara manual.',
                    error: "Rate limit exceeded (429) dari Claude API. {$errorMessage}",
                    receiptUrls: $receiptUrls,
                );
            }

            if ($status === 400) {
                return ReceiptExtractionDTO::failed(
                    receiptUrl: $primaryReceiptUrl,
                    description: 'Gagal baca resit. Sila isi borang secara manual.',
                    error: "Bad Request (400): {$errorMessage}. Model mungkin tidak wujud atau payload salah.",
                    receiptUrls: $receiptUrls,
                );
            }

            if ($status === 403 || $status === 401) {
                return ReceiptExtractionDTO::failed(
                    receiptUrl: $primaryReceiptUrl,
                    description: 'Gagal baca resit. Sila isi borang secara manual.',
                    error: "API Key tidak sah atau tiada kebenaran ({$status}): {$errorMessage}",
                    receiptUrls: $receiptUrls,
                );
            }

            return ReceiptExtractionDTO::failed(
                receiptUrl: $primaryReceiptUrl,
                description: 'Gagal baca resit. Sila isi borang secara manual.',
                error: "Claude API error (HTTP {$status}): {$errorMessage}",
                receiptUrls: $receiptUrls,
            );
        }

        $content = (string) data_get($response->json(), 'content.0.text', '{}');
        $sanitizedContent = preg_replace('/```json|```/', '', $content) ?? '{}';
        $decoded = json_decode(trim($sanitizedContent), true);

        if (! is_array($decoded)) {
            Log::warning('Receipt extraction returned invalid JSON.', [
                'content' => $content,
            ]);

            return ReceiptExtractionDTO::failed(
                receiptUrl: $primaryReceiptUrl,
                description: 'Gagal baca resit. Sila isi borang secara manual.',
                error: 'AI tidak return JSON yang valid. Response: '.substr($content, 0, 200),
                receiptUrls: $receiptUrls,
            );
        }

        return ReceiptExtractionDTO::fromArray($decoded, $primaryReceiptUrl, $receiptUrls);
    }

    private function sendExtractionRequestWithBlocks(array $contentBlocks, string $apiKey): ?Response
    {
        $model = (string) config('services.claude.model', 'claude-3-haiku-20240307');

        $payload = [
            'model' => $model,
            'max_tokens' => 1024,
            'messages' => [
                [
                    'role' => 'user',
                    'content' => $contentBlocks,
                ],
            ],
        ];

        for ($attempt = 1; $attempt <= 3; $attempt++) {
            try {
                $response = Http::acceptJson()
                    ->asJson()
                    ->withHeaders([
                        'x-api-key' => $apiKey,
                        'anthropic-version' => '2023-06-01',
                        'anthropic-beta' => 'pdfs-2024-09-25',
                    ])
                    ->post((string) config('services.claude.endpoint'), $payload);
            } catch (ConnectionException $exception) {
                Log::warning('Receipt extraction connection error.', [
                    'attempt' => $attempt,
                    'message' => $exception->getMessage(),
                ]);

                if ($attempt === 3) {
                    return null;
                }

                $this->pauseForRetry($attempt);

                continue;
            }

            if ($response->successful()) {
                return $response;
            }

            $status = $response->status();

            if (($status === 429 || $status >= 500) && $attempt < 3) {
                $this->pauseForRetry($attempt, $response->header('Retry-After'));

                continue;
            }

            return $response;
        }

        return null;
    }

    private function pauseForRetry(int $attempt, ?string $retryAfter = null): void
    {
        $delaySeconds = null;

        if ($retryAfter !== null) {
            if (is_numeric($retryAfter)) {
                $delaySeconds = max(1, (int) $retryAfter);
            } else {
                try {
                    $retryAt = Carbon::parse($retryAfter);
                    $delaySeconds = max(1, now()->diffInSeconds($retryAt, false));
                } catch (\Throwable) {
                    $delaySeconds = null;
                }
            }
        }

        if ($delaySeconds === null) {
            $delaySeconds = 2 ** ($attempt - 1);
        }

        usleep($delaySeconds * 1_000_000);
    }
}
