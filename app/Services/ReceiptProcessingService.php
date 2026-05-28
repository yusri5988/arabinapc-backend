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
    public function storeReceipt(UploadedFile $receipt, ?string $siteId = null): array
    {
        $localPath = $siteId !== null && $siteId !== ''
            ? 'receipts/'.preg_replace('/[^a-zA-Z0-9_\-]/', '', $siteId)
            : 'receipts';

        $storedPath = $receipt->store($localPath, 'public');
        $receiptUrl = Storage::disk('public')->url($storedPath);

        return [
            'storedPath' => $storedPath,
            'receiptUrl' => $receiptUrl,
        ];
    }

    public function processStored(string $storedPath, string $receiptUrl): ReceiptExtractionDTO
    {
        $apiKey = (string) config('services.claude.api_key');

        if ($apiKey === '') {
            return ReceiptExtractionDTO::failed(
                receiptUrl: $receiptUrl,
                description: 'Sila tetapkan API Key Claude',
                error: 'CLAUDE_API_KEY tidak ditetapkan dalam .env.',
            );
        }

        $fileContent = Storage::disk('public')->get($storedPath);
        $mimeType = Storage::disk('public')->mimeType($storedPath);

        if ($fileContent === null || $fileContent === false) {
            return ReceiptExtractionDTO::failed(
                receiptUrl: $receiptUrl,
                description: 'Gagal baca resit. Fail tidak wujud.',
                error: 'Fail resit tidak ditemui di storage.',
            );
        }

        $response = $this->sendExtractionRequest($fileContent, $mimeType, $apiKey);

        if ($response === null) {
            Log::warning('Receipt extraction failed after retries.');

            return ReceiptExtractionDTO::failed(
                receiptUrl: $receiptUrl,
                description: 'Gagal baca resit. Sila isi borang secara manual.',
                error: 'Tidak dapat hubungi Claude API (connection error selepas 3 percubaan).',
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
                    error: "Rate limit exceeded (429) dari Claude API. {$errorMessage}",
                );
            }

            if ($status === 400) {
                return ReceiptExtractionDTO::failed(
                    receiptUrl: $receiptUrl,
                    description: 'Gagal baca resit. Sila isi borang secara manual.',
                    error: "Bad Request (400): {$errorMessage}. Model mungkin tidak wujud atau payload salah.",
                );
            }

            if ($status === 403 || $status === 401) {
                return ReceiptExtractionDTO::failed(
                    receiptUrl: $receiptUrl,
                    description: 'Gagal baca resit. Sila isi borang secara manual.',
                    error: "API Key tidak sah atau tiada kebenaran ({$status}): {$errorMessage}",
                );
            }

            return ReceiptExtractionDTO::failed(
                receiptUrl: $receiptUrl,
                description: 'Gagal baca resit. Sila isi borang secara manual.',
                error: "Claude API error (HTTP {$status}): {$errorMessage}",
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
                receiptUrl: $receiptUrl,
                description: 'Gagal baca resit. Sila isi borang secara manual.',
                error: 'AI tidak return JSON yang valid. Response: '.substr($content, 0, 200),
            );
        }

        return ReceiptExtractionDTO::fromArray($decoded, $receiptUrl);
    }

    private function sendExtractionRequest(string $fileContent, string $mimeType, string $apiKey): ?Response
    {
        $model = (string) config('services.claude.model', 'claude-3-haiku-20240307');

        $payload = [
            'model' => $model,
            'max_tokens' => 1024,
            'messages' => [
                [
                    'role' => 'user',
                    'content' => [
                        [
                            'type' => 'image',
                            'source' => [
                                'type' => 'base64',
                                'media_type' => $mimeType,
                                'data' => base64_encode($fileContent),
                            ],
                        ],
                        [
                            'type' => 'text',
                            'text' => "Extract the Date, Total Amount, Payment To / Payee, and a short Description (in Malay) from this receipt. Return ONLY a valid JSON object with keys: date (YYYY-MM-DD), amount (float), payment_to (string), description (string). If you can't find something, use today's date, 0.00, or an empty string.",
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
                        'x-api-key' => $apiKey,
                        'anthropic-version' => '2023-06-01',
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
