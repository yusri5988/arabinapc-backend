<?php

namespace Tests\Feature;

use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ReceiptToLedgerFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_full_flow_upload_receipt_to_ledger_shows_expense(): void
    {
        Storage::fake('public');

        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [
                    [
                        'content' => [
                            'parts' => [
                                [
                                    'text' => '{"date": "2024-03-15", "amount": 45.90, "description": "Makan minum site"}',
                                ],
                            ],
                        ],
                    ],
                ],
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

        $aiData = $processResponse->json();

        $this->assertEquals('2024-03-15', $aiData['date']);
        $this->assertEquals(45.90, $aiData['amount']);
        $this->assertEquals('Makan minum site', $aiData['description']);
        $this->assertNotEmpty($aiData['receipt_url']);

        $expenseResponse = $this->actingAs($supervisor)
            ->postJson('/api/supervisor/expense', [
                'amount' => $aiData['amount'],
                'payment_to' => $aiData['payment_to'] ?? 'Vendor',
                'details' => 'Site Meal',
                'description' => $aiData['description'],
                'site_id' => 'A102',
                'date' => $aiData['date'],
                'receipt_url' => $aiData['receipt_url'],
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
                'candidates' => [
                    [
                        'content' => [
                            'parts' => [
                                [
                                    'text' => json_encode($data),
                                ],
                            ],
                        ],
                    ],
                ],
            ], 200);
        });

        $files = ['receipt1.jpg', 'receipt2.jpg', 'receipt3.jpg'];

        foreach ($files as $file) {
            $uploadedFile = UploadedFile::fake()->image($file);

            $processResponse = $this->actingAs($supervisor)
                ->postJson('/api/supervisor/process-receipt', [
                    'receipt' => $uploadedFile,
                    'site_id' => 'A102',
                ]);

            $processResponse->assertStatus(200);

            $aiData = $processResponse->json();

            $this->actingAs($supervisor)
                ->postJson('/api/supervisor/expense', [
                    'amount' => $aiData['amount'],
                    'payment_to' => $aiData['payment_to'] ?? 'Vendor',
                    'details' => 'Others',
                    'description' => $aiData['description'],
                    'site_id' => 'A102',
                    'date' => $aiData['date'],
                    'receipt_url' => $aiData['receipt_url'],
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
