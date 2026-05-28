<?php

namespace Tests\Feature;

use App\Jobs\ProcessReceiptJob;
use App\Models\Transaction;
use App\Models\User;
use App\Services\ReceiptProcessingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ReceiptToLedgerFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_full_flow_upload_receipt_to_ledger_shows_expense(): void
    {
        Storage::fake('public');
        Queue::fake([ProcessReceiptJob::class]);

        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'id' => 'msg_abc123',
                'type' => 'message',
                'role' => 'assistant',
                'content' => [
                    [
                        'type' => 'text',
                        'text' => '{"date": "2024-03-15", "amount": 45.90, "description": "Makan minum site"}',
                    ],
                ],
                'stop_reason' => 'end_turn',
            ], 200),
        ]);

        $supervisor = User::factory()->supervisor()->withBalance(500)->create();

        Transaction::create([
            'user_id' => $supervisor->id,
            'type' => 'topup',
            'amount' => 500,
            'description' => 'Opening Balance',
            'date' => '2024-01-01',
        ]);

        $file = UploadedFile::fake()->image('receipt.jpg');

        $processResponse = $this->actingAs($supervisor)
            ->postJson('/api/supervisor/process-receipt', [
                'receipt' => $file,
                'site_id' => 'A102',
            ]);

        $processResponse->assertStatus(200);

        $jobId = $processResponse->json('job_id');
        $receiptUrl = $processResponse->json('receipt_url');

        $storedFiles = Storage::disk('public')->allFiles('receipts/A102');
        $this->assertNotEmpty($storedFiles);

        $service = app(ReceiptProcessingService::class);
        $dto = $service->processStored($storedFiles[0], $receiptUrl);

        $this->assertEquals('2024-03-15', $dto->date);
        $this->assertEquals(45.90, $dto->amount);
        $this->assertEquals('Makan minum site', $dto->description);
        $this->assertNotEmpty($dto->receiptUrl);

        $expenseResponse = $this->actingAs($supervisor)
            ->postJson('/api/supervisor/expense', [
                'amount' => $dto->amount,
                'payment_to' => $dto->paymentTo ?: 'Vendor',
                'details' => 'Site Meal',
                'description' => $dto->description,
                'site_id' => 'A102',
                'date' => $dto->date,
                'receipt_url' => $dto->receiptUrl,
            ]);

        $expenseResponse->assertStatus(200)
            ->assertJsonPath('message', 'Perbelanjaan berjaya direkodkan.');

        $this->assertDatabaseHas('transactions', [
            'user_id' => $supervisor->id,
            'type' => 'expense',
            'description' => 'Makan minum site',
            'site_id' => 'A102',
        ]);

        $transaction = Transaction::where('type', 'expense')->first();
        $this->assertEquals(45.90, (float) $transaction->amount);
        $this->assertEquals('2024-03-15', $transaction->date->format('Y-m-d'));

        $this->assertEquals(454.10, $supervisor->fresh()->balance);

        $ledgerResponse = $this->actingAs($supervisor)
            ->getJson('/api/supervisor/ledger');

        $ledgerResponse->assertStatus(200)
            ->assertJsonPath('balance', 454.10)
            ->assertJsonCount(2, 'transactions');

        $transactions = $ledgerResponse->json('transactions');

        $this->assertEquals('expense', $transactions[0]['type']);
        $this->assertEquals('45.90', $transactions[0]['amount']);
        $this->assertEquals('Makan minum site', $transactions[0]['description']);
        $this->assertEquals('A102', $transactions[0]['site_id']);
        $this->assertStringContainsString('2024-03-15', $transactions[0]['date']);
        $this->assertStringContainsString('receipts/', $transactions[0]['receipt_url']);
    }

    public function test_multiple_receipts_flow_reflects_in_ledger(): void
    {
        Storage::fake('public');
        Queue::fake([ProcessReceiptJob::class]);

        $supervisor = User::factory()->supervisor()->withBalance(1000)->create();

        Transaction::create([
            'user_id' => $supervisor->id,
            'type' => 'topup',
            'amount' => 1000,
            'description' => 'Opening Balance',
            'date' => '2024-01-01',
        ]);

        $receiptData = [
            ['date' => '2024-01-10', 'amount' => 30.00, 'description' => 'Beli kopi'],
            ['date' => '2024-02-15', 'amount' => 75.50, 'description' => 'Beli simen'],
            ['date' => '2024-03-20', 'amount' => 120.00, 'description' => 'Beli besi'],
        ];

        $callIndex = 0;
        Http::fake(function ($request) use (&$callIndex, $receiptData) {
            $data = $receiptData[$callIndex % count($receiptData)];
            $callIndex++;

            return Http::response([
                'id' => 'msg_abc123',
                'type' => 'message',
                'role' => 'assistant',
                'content' => [
                    [
                        'type' => 'text',
                        'text' => json_encode($data),
                    ],
                ],
                'stop_reason' => 'end_turn',
            ], 200);
        });

        $files = ['receipt1.jpg', 'receipt2.jpg', 'receipt3.jpg'];
        $service = app(ReceiptProcessingService::class);

        foreach ($files as $file) {
            $uploadedFile = UploadedFile::fake()->image($file);

            $processResponse = $this->actingAs($supervisor)
                ->postJson('/api/supervisor/process-receipt', [
                    'receipt' => $uploadedFile,
                    'site_id' => 'A102',
                ]);

            $processResponse->assertStatus(200);

            $receiptUrl = $processResponse->json('receipt_url');
            $storedFiles = Storage::disk('public')->allFiles('receipts/A102');
            $latestFile = end($storedFiles);

            $dto = $service->processStored($latestFile, $receiptUrl);

            $this->actingAs($supervisor)
                ->postJson('/api/supervisor/expense', [
                    'amount' => $dto->amount,
                    'payment_to' => $dto->paymentTo ?: 'Vendor',
                    'details' => 'Others',
                    'description' => $dto->description,
                    'site_id' => 'A102',
                    'date' => $dto->date,
                    'receipt_url' => $dto->receiptUrl,
                ])->assertStatus(200);
        }

        $ledgerResponse = $this->actingAs($supervisor)
            ->getJson('/api/supervisor/ledger');

        $ledgerResponse->assertStatus(200)
            ->assertJsonCount(4, 'transactions');

        $freshBalance = (float) $supervisor->fresh()->balance;
        $this->assertEqualsWithDelta(774.50, $freshBalance, 0.01);

        $transactions = $ledgerResponse->json('transactions');

        $this->assertStringContainsString('2024-03-20', $transactions[0]['date']);
        $this->assertStringContainsString('2024-02-15', $transactions[1]['date']);
        $this->assertStringContainsString('2024-01-10', $transactions[2]['date']);
    }
}
