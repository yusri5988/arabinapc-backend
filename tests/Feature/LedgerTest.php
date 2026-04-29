<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Transaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LedgerTest extends TestCase
{
    use RefreshDatabase;

    public function test_supervisor_can_view_ledger(): void
    {
        $supervisor = User::factory()->supervisor()->withBalance(454.10)->create();

        Transaction::factory()->create([
            'user_id' => $supervisor->id,
            'type' => 'expense',
            'amount' => 45.90,
            'description' => 'Makan minum site',
            'site_id' => 'A102',
            'receipt_url' => '/storage/receipts/receipt.jpg',
            'date' => '2024-03-15',
        ]);

        $response = $this->actingAs($supervisor)
            ->getJson('/api/supervisor/ledger');

        $response->assertStatus(200)
            ->assertJsonPath('balance', 454.10)
            ->assertJsonCount(1, 'transactions')
            ->assertJsonPath('transactions.0.description', 'Makan minum site')
            ->assertJsonPath('transactions.0.amount', '45.90')
            ->assertJsonPath('transactions.0.receipt_url', '/storage/receipts/receipt.jpg');
    }

    public function test_ledger_requires_authentication(): void
    {
        $response = $this->getJson('/api/supervisor/ledger');

        $response->assertStatus(401);
    }

    public function test_ledger_requires_supervisor_role(): void
    {
        $admin = User::factory()->admin()->create();

        $response = $this->actingAs($admin)
            ->getJson('/api/supervisor/ledger');

        $response->assertStatus(403);
    }

    public function test_ledger_returns_empty_when_no_transactions(): void
    {
        $supervisor = User::factory()->supervisor()->withBalance(500)->create();

        $response = $this->actingAs($supervisor)
            ->getJson('/api/supervisor/ledger');

        $response->assertStatus(200)
            ->assertJsonPath('balance', 500)
            ->assertJsonCount(0, 'transactions');
    }

    public function test_ledger_returns_transactions_ordered_by_date_desc(): void
    {
        $supervisor = User::factory()->supervisor()->withBalance(400)->create();

        Transaction::factory()->create([
            'user_id' => $supervisor->id,
            'type' => 'expense',
            'amount' => 30,
            'description' => 'First expense',
            'site_id' => 'A101',
            'date' => '2024-01-10',
        ]);

        Transaction::factory()->create([
            'user_id' => $supervisor->id,
            'type' => 'expense',
            'amount' => 50,
            'description' => 'Second expense',
            'site_id' => 'A102',
            'date' => '2024-03-20',
        ]);

        Transaction::factory()->create([
            'user_id' => $supervisor->id,
            'type' => 'expense',
            'amount' => 20,
            'description' => 'Third expense',
            'site_id' => 'A103',
            'date' => '2024-02-15',
        ]);

        $response = $this->actingAs($supervisor)
            ->getJson('/api/supervisor/ledger');

        $response->assertStatus(200)
            ->assertJsonCount(3, 'transactions')
            ->assertJsonPath('transactions.0.description', 'Second expense')
            ->assertJsonPath('transactions.1.description', 'Third expense')
            ->assertJsonPath('transactions.2.description', 'First expense');
    }

    public function test_ledger_only_shows_own_transactions(): void
    {
        $supervisor1 = User::factory()->supervisor()->withBalance(500)->create();
        $supervisor2 = User::factory()->supervisor()->withBalance(500)->create();

        Transaction::factory()->create([
            'user_id' => $supervisor1->id,
            'type' => 'expense',
            'amount' => 100,
            'description' => 'Supervisor 1 expense',
            'site_id' => 'A101',
            'date' => '2024-01-01',
        ]);

        Transaction::factory()->create([
            'user_id' => $supervisor2->id,
            'type' => 'expense',
            'amount' => 200,
            'description' => 'Supervisor 2 expense',
            'site_id' => 'B202',
            'date' => '2024-02-02',
        ]);

        $response = $this->actingAs($supervisor1)
            ->getJson('/api/supervisor/ledger');

        $response->assertStatus(200)
            ->assertJsonCount(1, 'transactions')
            ->assertJsonPath('transactions.0.description', 'Supervisor 1 expense');
    }
}
