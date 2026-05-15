<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Transaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExpenseTest extends TestCase
{
    use RefreshDatabase;

    public function test_supervisor_can_create_expense(): void
    {
        $supervisor = User::factory()->supervisor()->withBalance(500)->create();

        Transaction::create([
            'user_id' => $supervisor->id,
            'type' => 'topup',
            'amount' => 500,
            'description' => 'Opening Balance',
            'date' => '2024-01-01',
        ]);

        $response = $this->actingAs($supervisor)
            ->postJson('/api/supervisor/expense', [
                'amount' => 45.90,
                'payment_to' => 'Kedai Makan',
                'details' => 'Site Meal',
                'description' => 'Makan minum site',
                'site_id' => 'A102',
                'date' => '2024-03-15',
                'receipt_url' => '/storage/receipts/receipt.jpg',
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('message', 'Perbelanjaan berjaya direkodkan.');

        $this->assertDatabaseHas('transactions', [
            'user_id' => $supervisor->id,
            'type' => 'expense',
            'description' => 'Makan minum site',
            'site_id' => 'A102',
            'receipt_url' => '/storage/receipts/receipt.jpg',
        ]);

        $this->assertDatabaseCount('transactions', 2);

        $transaction = Transaction::where('type', 'expense')->first();
        $this->assertEquals(45.90, (float) $transaction->amount);
        $this->assertEquals('2024-03-15', $transaction->date->format('Y-m-d'));

        $this->assertEquals(454.10, $supervisor->fresh()->balance);
    }

    public function test_expense_requires_authentication(): void
    {
        $response = $this->postJson('/api/supervisor/expense', [
            'amount' => 50,
            'description' => 'Test',
            'site_id' => 'A101',
            'date' => '2024-01-01',
        ]);

        $response->assertStatus(401);
    }

    public function test_expense_requires_supervisor_role(): void
    {
        $admin = User::factory()->admin()->create();

        $response = $this->actingAs($admin)
            ->postJson('/api/supervisor/expense', [
                'amount' => 50,
                'description' => 'Test',
                'site_id' => 'A101',
                'date' => '2024-01-01',
            ]);

        $response->assertStatus(403);
    }

    public function test_expense_fails_when_insufficient_balance(): void
    {
        $supervisor = User::factory()->supervisor()->withBalance(20)->create();

        Transaction::create([
            'user_id' => $supervisor->id,
            'type' => 'topup',
            'amount' => 20,
            'description' => 'Opening Balance',
            'date' => '2024-01-01',
        ]);

        $response = $this->actingAs($supervisor)
            ->postJson('/api/supervisor/expense', [
                'amount' => 50,
                'payment_to' => 'Vendor',
                'details' => 'Others',
                'description' => 'Test',
                'site_id' => 'A101',
                'date' => '2024-01-01',
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['amount'])
            ->assertJsonPath('message', 'Baki tidak mencukupi.');

        $this->assertEquals(20, $supervisor->fresh()->balance);
    }

    public function test_expense_requires_valid_input(): void
    {
        $supervisor = User::factory()->supervisor()->withBalance(500)->create();

        $response = $this->actingAs($supervisor)
            ->postJson('/api/supervisor/expense', [
                'amount' => -10,
                'details' => '',
                'description' => '',
                'site_id' => '',
                'date' => '',
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['amount', 'details', 'description', 'site_id', 'date']);
    }

    public function test_expense_receipt_url_is_optional(): void
    {
        $supervisor = User::factory()->supervisor()->withBalance(500)->create();

        Transaction::create([
            'user_id' => $supervisor->id,
            'type' => 'topup',
            'amount' => 500,
            'description' => 'Opening Balance',
            'date' => '2024-01-01',
        ]);

        $response = $this->actingAs($supervisor)
            ->postJson('/api/supervisor/expense', [
                'amount' => 30,
                'payment_to' => 'Vendor',
                'details' => 'Others',
                'description' => 'Test tanpa resit',
                'site_id' => 'B201',
                'date' => '2024-04-01',
            ]);

        $response->assertStatus(200);

        $this->assertDatabaseHas('transactions', [
            'user_id' => $supervisor->id,
            'type' => 'expense',
            'amount' => 30,
            'receipt_url' => null,
        ]);
    }
}
