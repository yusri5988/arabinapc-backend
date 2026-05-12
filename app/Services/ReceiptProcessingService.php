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
use App\Services\GoogleDriveService;

class ReceiptProcessingService
{
    public function __construct(
        protected GoogleDriveService $googleDriveService
    ) {}

    public function process(UploadedFile $receipt): ReceiptExtractionDTO
    {
        $storedPath = $this->googleDriveService->upload($receipt, 'receipts');
        
        if ($storedPath) {
            $receiptUrl = $this->googleDriveService->getUrl($storedPath);
        } else {
            // Fallback to local if Google Drive fails
            $storedPath = $receipt->store('receipts', 'public');
            $receiptUrl = Storage::disk('public')->url($storedPath);
        }

        $apiKey = (string) config('services.gemini.api_key');

        if ($apiKey === '') {
            return ReceiptExtractionDTO::failed(
                receiptUrl: $receiptUrl,
                description: 'Sila tetapkan API Key Gemini',
                error: 'GEMINI_API_KEY tidak ditetapkan dalam .env.',
            );
        }

        $response = $this->sendExtractionRequest($receipt, $apiKey);

        if ($response === null) {
            Log::warning('Receipt extraction failed after retries.');

            return ReceiptExtractionDTO::failed(
                receiptUrl: $receiptUrl,
                description: 'Gagal baca resit. Sila isi borang secara manual.',
                error: 'Tidak dapat hubungi Gemini API (connection error selepas 3 percubaan).',
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
                    receiptUrl: $receiptUrl,
                    description: 'Gagal baca resit. Sila isi borang secara manual.',
                    error: "Rate limit exceeded (429) dari Gemini API. {$errorMessage}",
                );
            }

            if ($status === 400) {
                return ReceiptExtractionDTO::failed(
                    receiptUrl: $receiptUrl,
                    description: 'Gagal baca resit. Sila isi borang secara manual.',
                    error: "Bad Request (400): {$errorMessage}. Model mungkin tidak wujud atau payload salah.",
                );
            }

            if ($status === 403) {
                return ReceiptExtractionDTO::failed(
                    receiptUrl: $receiptUrl,
                    description: 'Gagal baca resit. Sila isi borang secara manual.',
                    error: "API Key tidak sah atau tiada kebenaran (403): {$errorMessage}",
                );
            }

            return ReceiptExtractionDTO::failed(
                receiptUrl: $receiptUrl,
                description: 'Gagal baca resit. Sila isi borang secara manual.',
                error: "Gemini API error (HTTP {$status}): {$errorMessage}",
            );
        }

        $content = (string) data_get($response->json(), 'candidates.0.content.parts.0.text', '{}');
        $sanitizedContent = preg_replace('/```json|```/', '', $content) ?? '{}';
        $decoded = json_decode(trim($sanitizedContent), true);

        if (! is_array($decoded)) {
            Log::warning('Receipt extraction returned invalid JSON.', [
                'content' => $content,
            ]);

            return ReceiptExtractionDTO::failed(
                receiptUrl: $receiptUrl,
                description: 'Gagal baca resit. Sila isi borang secara manual.',
                error: 'AI tidak return JSON yang valid. Response: ' . substr($content, 0, 200),
            );
        }

        return ReceiptExtractionDTO::fromArray($decoded, $receiptUrl);
    }

    private function sendExtractionRequest(UploadedFile $receipt, string $apiKey): ?Response
    {
        $payload = [
            'contents' => [
                [
                    'parts' => [
                        [
                            'text' => "Extract the Date, Total Amount, Payment To / Payee, and a short Description (in Malay) from this receipt. Return ONLY a valid JSON object with keys: date (YYYY-MM-DD), amount (float), payment_to (string), description (string). If you can't find something, use today's date, 0.00, or an empty string.",
                        ],
                        [
                            'inline_data' => [
                                'mime_type' => $receipt->getMimeType(),
                                'data' => base64_encode(file_get_contents($receipt->getPathname())),
                            ],
                        ],
                    ],
                ],
            ],
        ];

        for ($attempt = 1; $attempt <= 3; $attempt++) {
            try {
                $response = Http::acceptJson()
                    ->asJson()
                    ->withHeaders([
                        'X-goog-api-key' => $apiKey,
                    ])
                    ->post((string) config('services.gemini.endpoint'), $payload);
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
