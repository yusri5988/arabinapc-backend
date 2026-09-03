<?php

namespace Tests\Feature;

use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminTransactionCrudTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_create_topup_and_expense_transactions(): void
    {
        $admin = User::factory()->admin()->create();
        $supervisor = User::factory()->supervisor()->create();

        $this->actingAs($admin)
            ->postJson('/api/admin/transactions', [
                'supervisor_id' => $supervisor->id,
                'type' => 'topup',
                'amount' => 200,
                'date' => '2024-01-01',
            ])
            ->assertCreated()
            ->assertJsonPath('transaction.type', 'topup');

        $this->actingAs($admin)
            ->postJson('/api/admin/transactions', [
                'supervisor_id' => $supervisor->id,
                'type' => 'expense',
                'amount' => 50,
                'payment_to' => 'Vendor A',
                'details' => 'Site Meal',
                'description' => 'Lunch for site team',
                'site_id' => 'A101',
                'receipt_url' => '/storage/receipts/A101/receipt.jpg',
                'date' => '2024-01-02',
            ])
            ->assertCreated()
            ->assertJsonPath('transaction.type', 'expense');

        $this->assertDatabaseCount('transactions', 2);
        $this->assertEquals(150, (float) $supervisor->fresh()->balance);
    }

    public function test_admin_can_update_transaction_but_type_and_supervisor_are_locked(): void
    {
        $admin = User::factory()->admin()->create();
        $supervisor = User::factory()->supervisor()->withBalance(150)->create();
        $otherSupervisor = User::factory()->supervisor()->create();

        Transaction::create([
            'user_id' => $supervisor->id,
            'type' => 'topup',
            'amount' => 200,
            'description' => 'Opening Balance',
            'date' => '2024-01-01',
        ]);

        $expense = Transaction::create([
            'user_id' => $supervisor->id,
            'type' => 'expense',
            'amount' => 50,
            'payment_to' => 'Vendor A',
            'details' => 'Site Meal',
            'description' => 'Lunch',
            'site_id' => 'A101',
            'date' => '2024-01-02',
        ]);

        $this->actingAs($admin)
            ->putJson("/api/admin/transactions/{$expense->id}", [
                'supervisor_id' => $otherSupervisor->id,
                'type' => 'topup',
                'amount' => 80,
                'payment_to' => 'Vendor B',
                'details' => 'Hardware',
                'description' => 'Updated expense',
                'site_id' => 'B202',
                'date' => '2024-01-03',
            ])
            ->assertOk()
            ->assertJsonPath('transaction.type', 'expense')
            ->assertJsonPath('transaction.user.id', $supervisor->id);

        $expense->refresh();

        $this->assertEquals('expense', $expense->type);
        $this->assertEquals($supervisor->id, $expense->user_id);
        $this->assertEquals(80, (float) $expense->amount);
        $this->assertEquals(120, (float) $supervisor->fresh()->balance);
        $this->assertEquals(0, (float) $otherSupervisor->fresh()->balance);
    }

    public function test_admin_can_delete_expense_and_balance_is_recalculated(): void
    {
        $admin = User::factory()->admin()->create();
        $supervisor = User::factory()->supervisor()->withBalance(150)->create();

        Transaction::create([
            'user_id' => $supervisor->id,
            'type' => 'topup',
            'amount' => 200,
            'description' => 'Opening Balance',
            'date' => '2024-01-01',
        ]);

        $expense = Transaction::create([
            'user_id' => $supervisor->id,
            'type' => 'expense',
            'amount' => 50,
            'details' => 'Site Meal',
            'description' => 'Lunch',
            'site_id' => 'A101',
            'date' => '2024-01-02',
        ]);

        $this->actingAs($admin)
            ->deleteJson("/api/admin/transactions/{$expense->id}")
            ->assertOk()
            ->assertJsonPath('message', 'Transaction berjaya dipadam.');

        $this->assertDatabaseMissing('transactions', ['id' => $expense->id]);
        $this->assertEquals(200, (float) $supervisor->fresh()->balance);
    }

    public function test_admin_can_delete_topup_even_if_balance_becomes_negative(): void
    {
        $admin = User::factory()->admin()->create();
        $supervisor = User::factory()->supervisor()->withBalance(50)->create();

        $topup = Transaction::create([
            'user_id' => $supervisor->id,
            'type' => 'topup',
            'amount' => 100,
            'description' => 'Opening Balance',
            'date' => '2024-01-01',
        ]);

        Transaction::create([
            'user_id' => $supervisor->id,
            'type' => 'expense',
            'amount' => 50,
            'details' => 'Site Meal',
            'description' => 'Lunch',
            'site_id' => 'A101',
            'date' => '2024-01-02',
        ]);

        $this->actingAs($admin)
            ->deleteJson("/api/admin/transactions/{$topup->id}")
            ->assertOk()
            ->assertJsonPath('message', 'Transaction berjaya dipadam.');

        $this->assertDatabaseMissing('transactions', ['id' => $topup->id]);
        $this->assertEquals(-50, (float) $supervisor->fresh()->balance);
    }

    public function test_admin_can_delete_expense_even_if_existing_ledger_has_negative_running_balance(): void
    {
        $admin = User::factory()->admin()->create();
        $supervisor = User::factory()->supervisor()->withBalance(50)->create();

        Transaction::create([
            'user_id' => $supervisor->id,
            'type' => 'expense',
            'amount' => 20,
            'details' => 'Early expense',
            'description' => 'Before any topup',
            'site_id' => 'A101',
            'date' => '2024-01-01',
        ]);

        Transaction::create([
            'user_id' => $supervisor->id,
            'type' => 'topup',
            'amount' => 100,
            'description' => 'Opening Balance',
            'date' => '2024-01-02',
        ]);

        $expenseToDelete = Transaction::create([
            'user_id' => $supervisor->id,
            'type' => 'expense',
            'amount' => 30,
            'details' => 'Site Meal',
            'description' => 'Lunch',
            'site_id' => 'A101',
            'date' => '2024-01-03',
        ]);

        $this->actingAs($admin)
            ->deleteJson("/api/admin/transactions/{$expenseToDelete->id}")
            ->assertOk()
            ->assertJsonPath('message', 'Transaction berjaya dipadam.');

        $this->assertDatabaseMissing('transactions', ['id' => $expenseToDelete->id]);
        $this->assertEquals(80, (float) $supervisor->fresh()->balance);
    }

    public function test_admin_can_create_expense_that_starts_ledger_negative(): void
    {
        $admin = User::factory()->admin()->create();
        $supervisor = User::factory()->supervisor()->create();

        $this->actingAs($admin)
            ->postJson('/api/admin/transactions', [
                'supervisor_id' => $supervisor->id,
                'type' => 'expense',
                'amount' => 50,
                'payment_to' => 'Vendor A',
                'details' => 'Site Meal',
                'description' => 'Lunch for site team',
                'site_id' => 'A101',
                'receipt_url' => '/storage/receipts/A101/receipt.jpg',
                'date' => '2024-01-02',
            ])
            ->assertCreated()
            ->assertJsonPath('transaction.type', 'expense');

        $this->assertDatabaseCount('transactions', 1);
        $this->assertEquals(-50, (float) $supervisor->fresh()->balance);
    }

    public function test_admin_can_update_expense_to_negative_balance(): void
    {
        $admin = User::factory()->admin()->create();
        $supervisor = User::factory()->supervisor()->withBalance(100)->create();

        Transaction::create([
            'user_id' => $supervisor->id,
            'type' => 'topup',
            'amount' => 100,
            'description' => 'Opening Balance',
            'date' => '2024-01-01',
        ]);

        $expense = Transaction::create([
            'user_id' => $supervisor->id,
            'type' => 'expense',
            'amount' => 30,
            'payment_to' => 'Vendor A',
            'details' => 'Site Meal',
            'description' => 'Lunch',
            'site_id' => 'A101',
            'date' => '2024-01-02',
        ]);

        $this->actingAs($admin)
            ->putJson("/api/admin/transactions/{$expense->id}", [
                'amount' => 150,
                'payment_to' => 'Vendor A',
                'details' => 'Site Meal',
                'description' => 'Lunch',
                'site_id' => 'A101',
                'date' => '2024-01-02',
            ])
            ->assertOk()
            ->assertJsonPath('transaction.amount', '150.00');

        $this->assertEquals(-50, (float) $supervisor->fresh()->balance);
    }

    public function test_admin_can_filter_transactions_by_supervisor(): void
    {
        $admin = User::factory()->admin()->create();
        $supervisor1 = User::factory()->supervisor()->create();
        $supervisor2 = User::factory()->supervisor()->create();

        Transaction::create([
            'user_id' => $supervisor1->id,
            'type' => 'topup',
            'amount' => 100,
            'description' => 'Topup 1',
            'date' => '2024-01-01',
        ]);

        Transaction::create([
            'user_id' => $supervisor2->id,
            'type' => 'topup',
            'amount' => 200,
            'description' => 'Topup 2',
            'date' => '2024-01-02',
        ]);

        // List all
        $this->actingAs($admin)
            ->getJson('/api/admin/transactions')
            ->assertOk()
            ->assertJsonCount(2, 'transactions');

        // Filter by supervisor 1
        $this->actingAs($admin)
            ->getJson("/api/admin/transactions?user_id={$supervisor1->id}")
            ->assertOk()
            ->assertJsonCount(1, 'transactions')
            ->assertJsonPath('transactions.0.description', 'Topup 1');
    }

    public function test_admin_can_filter_transactions_by_date_range(): void
    {
        $admin = User::factory()->admin()->create();
        $supervisor = User::factory()->supervisor()->create();

        Transaction::create([
            'user_id' => $supervisor->id,
            'type' => 'topup',
            'amount' => 100,
            'description' => 'Tx Jan',
            'date' => '2026-01-15',
        ]);

        Transaction::create([
            'user_id' => $supervisor->id,
            'type' => 'topup',
            'amount' => 200,
            'description' => 'Tx Feb',
            'date' => '2026-02-15',
        ]);

        Transaction::create([
            'user_id' => $supervisor->id,
            'type' => 'topup',
            'amount' => 300,
            'description' => 'Tx Mar',
            'date' => '2026-03-15',
        ]);

        // Filter for Feb only
        $response = $this->actingAs($admin)
            ->getJson('/api/admin/transactions?start_date=2026-02-01&end_date=2026-02-28')
            ->assertOk()
            ->assertJsonCount(1, 'transactions')
            ->assertJsonPath('transactions.0.description', 'Tx Feb')
            ->assertJsonPath('total_amount', 200);

        // Filter from Feb onwards
        $this->actingAs($admin)
            ->getJson('/api/admin/transactions?start_date=2026-02-01')
            ->assertOk()
            ->assertJsonCount(2, 'transactions')
            ->assertJsonPath('total_amount', 500);

        // Filter up to Feb
        $this->actingAs($admin)
            ->getJson('/api/admin/transactions?end_date=2026-02-28')
            ->assertOk()
            ->assertJsonCount(2, 'transactions')
            ->assertJsonPath('total_amount', 300);
    }

    public function test_admin_cannot_filter_transactions_with_invalid_or_reversed_dates(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->getJson('/api/admin/transactions?start_date=not-a-date')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['start_date']);

        $this->actingAs($admin)
            ->getJson('/api/admin/transactions?end_date=not-a-date')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['end_date']);

        $this->actingAs($admin)
            ->getJson('/api/admin/transactions?start_date=2026-03-01&end_date=2026-02-28')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['end_date']);
    }
}
