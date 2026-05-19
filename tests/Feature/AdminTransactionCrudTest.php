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

    public function test_admin_cannot_delete_topup_if_running_balance_would_be_negative(): void
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
            ->assertStatus(422)
            ->assertJsonValidationErrors(['amount']);

        $this->assertDatabaseHas('transactions', ['id' => $topup->id]);
        $this->assertEquals(50, (float) $supervisor->fresh()->balance);
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
}
