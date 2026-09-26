<?php

namespace Tests\Feature;

use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class AdminReceiveBackPettyCashTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_receive_back_all_staff_petty_cash_without_admin_balance(): void
    {
        $admin = User::factory()->admin()->withBalance(0)->create(['name' => 'Admin Arabina']);
        $supervisor = User::factory()->supervisor()->withBalance(0)->create(['name' => 'Staff A']);

        Transaction::create([
            'user_id' => $supervisor->id,
            'type' => 'topup',
            'amount' => 500,
            'description' => 'Opening Balance',
            'date' => '2026-07-01',
        ]);

        Transaction::create([
            'user_id' => $supervisor->id,
            'type' => 'expense',
            'amount' => 125.50,
            'description' => 'Staff expense',
            'date' => '2026-07-01',
        ]);

        $response = $this->actingAs($admin)
            ->postJson("/api/admin/supervisors/{$supervisor->id}/receive-back");

        $response->assertOk()
            ->assertJsonPath('message', 'Petty cash successfully received back from staff.')
            ->assertJsonPath('amount', 374.50)
            ->assertJsonPath('balance', 0);

        $this->assertEquals(0, (float) $admin->fresh()->balance);
        $this->assertEquals(0, (float) $supervisor->fresh()->balance);

        $transaction = Transaction::where('type', 'return_to_admin')->first();

        $this->assertNotNull($transaction);
        $this->assertEquals($supervisor->id, $transaction->user_id);
        $this->assertEquals(374.50, (float) $transaction->amount);
        $this->assertEquals('Admin', $transaction->payment_to);
        $this->assertEquals('Petty cash returned to Admin: Admin Arabina', $transaction->description);
        $this->assertEquals('admin_receive_back', $transaction->metadata['source']);
        $this->assertEquals($admin->id, $transaction->metadata['received_by_user_id']);
    }

    public function test_admin_cannot_receive_back_from_non_supervisor_user(): void
    {
        $admin = User::factory()->admin()->create();
        $targetAdmin = User::factory()->admin()->create();

        $response = $this->actingAs($admin)
            ->postJson("/api/admin/supervisors/{$targetAdmin->id}/receive-back");

        $response->assertNotFound();

        $this->assertDatabaseCount('transactions', 0);
    }

    public function test_admin_cannot_receive_back_when_staff_has_no_positive_balance(): void
    {
        $admin = User::factory()->admin()->create();
        $supervisor = User::factory()->supervisor()->withBalance(0)->create();

        $response = $this->actingAs($admin)
            ->postJson("/api/admin/supervisors/{$supervisor->id}/receive-back");

        $response->assertStatus(422)
            ->assertJsonPath('message', 'Staff does not have any petty cash balance to receive back.');

        $this->assertEquals(0, (float) $supervisor->fresh()->balance);
        $this->assertDatabaseCount('transactions', 0);
    }

    public function test_receive_back_is_money_out_in_history_and_not_counted_as_expense_in_consolidated_report(): void
    {
        Carbon::setTestNow('2026-07-03');
        $admin = User::factory()->admin()->create();
        $supervisor = User::factory()->supervisor()->create(['department' => 'Site']);

        Transaction::create([
            'user_id' => $supervisor->id,
            'type' => 'topup',
            'amount' => 500,
            'description' => 'Cash sent',
            'date' => '2026-07-01',
        ]);

        Transaction::create([
            'user_id' => $supervisor->id,
            'type' => 'expense',
            'amount' => 100,
            'description' => 'Expense',
            'date' => '2026-07-02',
        ]);

        $this->actingAs($admin)
            ->postJson("/api/admin/supervisors/{$supervisor->id}/receive-back")
            ->assertOk();

        $historyResponse = $this->actingAs($admin)
            ->getJson("/api/admin/supervisors/{$supervisor->id}/transactions");

        $historyResponse->assertOk();

        $returnTransaction = collect($historyResponse->json('transactions'))
            ->firstWhere('type', 'return_to_admin');

        $this->assertNotNull($returnTransaction);
        $this->assertEquals(0, (float) $returnTransaction['money_in']);
        $this->assertEquals(400, (float) $returnTransaction['money_out']);

        $reportResponse = $this->actingAs($admin)
            ->getJson('/api/admin/transactions/consolidated-report?start_date=2026-07-01&end_date=2026-07-31');

        $reportResponse->assertOk()
            ->assertJsonPath('summary.total_topup', 500)
            ->assertJsonPath('summary.total_expense', 100)
            ->assertJsonPath('summary.total_returned_to_admin', 400)
            ->assertJsonPath('summary.balance', 0)
            ->assertJsonPath('by_staff.0.total_returned_to_admin', 400)
            ->assertJsonPath('by_department.0.total_returned_to_admin', 400);

        Carbon::setTestNow();
    }

    public function test_admin_cannot_edit_return_to_admin_transaction(): void
    {
        $admin = User::factory()->admin()->create();
        $supervisor = User::factory()->supervisor()->create();

        Transaction::create([
            'user_id' => $supervisor->id,
            'type' => 'topup',
            'amount' => 500,
            'description' => 'Cash',
            'date' => '2026-07-01',
        ]);

        $this->actingAs($admin)
            ->postJson("/api/admin/supervisors/{$supervisor->id}/receive-back")
            ->assertOk();

        $returnTx = Transaction::where('type', 'return_to_admin')->first();

        $response = $this->actingAs($admin)
            ->putJson("/api/admin/transactions/{$returnTx->id}", [
                'amount' => 100,
                'payment_to' => 'Changed',
                'details' => 'Changed',
                'description' => 'Changed',
                'site_id' => 'X001',
                'date' => '2026-07-02',
            ]);

        $response->assertStatus(403);
        $this->assertDatabaseHas('transactions', [
            'id' => $returnTx->id,
            'amount' => 500,
        ]);
    }

    public function test_admin_cannot_delete_return_to_admin_transaction(): void
    {
        $admin = User::factory()->admin()->create();
        $supervisor = User::factory()->supervisor()->create();

        Transaction::create([
            'user_id' => $supervisor->id,
            'type' => 'topup',
            'amount' => 500,
            'description' => 'Cash',
            'date' => '2026-07-01',
        ]);

        $this->actingAs($admin)
            ->postJson("/api/admin/supervisors/{$supervisor->id}/receive-back")
            ->assertOk();

        $returnTx = Transaction::where('type', 'return_to_admin')->first();

        $response = $this->actingAs($admin)
            ->deleteJson("/api/admin/transactions/{$returnTx->id}");

        $response->assertStatus(403);
        $this->assertDatabaseCount('transactions', 2); // topup + return_to_admin still exist
    }

    public function test_admin_can_receive_back_partial_petty_cash_by_specifying_amount(): void
    {
        $admin = User::factory()->admin()->create(['name' => 'Admin Arabina']);
        $supervisor = User::factory()->supervisor()->withBalance(0)->create(['name' => 'Staff B']);

        Transaction::create([
            'user_id' => $supervisor->id,
            'type' => 'topup',
            'amount' => 500,
            'description' => 'Opening Balance',
            'date' => '2026-07-01',
        ]);

        $response = $this->actingAs($admin)
            ->postJson("/api/admin/supervisors/{$supervisor->id}/receive-back", [
                'amount' => 200,
            ]);

        $response->assertOk()
            ->assertJsonPath('message', 'Petty cash successfully received back from staff.')
            ->assertJsonPath('amount', 200)
            ->assertJsonPath('balance', 300);

        $this->assertEquals(300, (float) $supervisor->fresh()->balance);

        $transaction = Transaction::where('type', 'return_to_admin')->first();
        $this->assertNotNull($transaction);
        $this->assertEquals(200, (float) $transaction->amount);
    }

    public function test_admin_cannot_receive_back_amount_exceeding_current_balance(): void
    {
        $admin = User::factory()->admin()->create();
        $supervisor = User::factory()->supervisor()->withBalance(0)->create();

        Transaction::create([
            'user_id' => $supervisor->id,
            'type' => 'topup',
            'amount' => 150,
            'description' => 'Opening Balance',
            'date' => '2026-07-01',
        ]);

        $response = $this->actingAs($admin)
            ->postJson("/api/admin/supervisors/{$supervisor->id}/receive-back", [
                'amount' => 200,
            ]);

        $response->assertStatus(422)
            ->assertJsonPath('message', 'Amount to receive back cannot exceed current petty cash balance.');

        $this->assertEquals(0, Transaction::where('type', 'return_to_admin')->count());
    }

    public function test_admin_cannot_receive_back_zero_or_negative_amount(): void
    {
        $admin = User::factory()->admin()->create();
        $supervisor = User::factory()->supervisor()->withBalance(0)->create();

        Transaction::create([
            'user_id' => $supervisor->id,
            'type' => 'topup',
            'amount' => 500,
            'description' => 'Opening Balance',
            'date' => '2026-07-01',
        ]);

        $responseZero = $this->actingAs($admin)
            ->postJson("/api/admin/supervisors/{$supervisor->id}/receive-back", [
                'amount' => 0,
            ]);
        $responseZero->assertStatus(422)
            ->assertJsonValidationErrors(['amount']);

        $responseNegative = $this->actingAs($admin)
            ->postJson("/api/admin/supervisors/{$supervisor->id}/receive-back", [
                'amount' => -50,
            ]);
        $responseNegative->assertStatus(422)
            ->assertJsonValidationErrors(['amount']);

        $this->assertEquals(0, Transaction::where('type', 'return_to_admin')->count());
    }

    public function test_admin_can_receive_back_with_explicit_null_amount(): void
    {
        $admin = User::factory()->admin()->create();
        $supervisor = User::factory()->supervisor()->withBalance(0)->create();

        Transaction::create([
            'user_id' => $supervisor->id,
            'type' => 'topup',
            'amount' => 350.75,
            'description' => 'Opening Balance',
            'date' => '2026-07-01',
        ]);

        $response = $this->actingAs($admin)
            ->postJson("/api/admin/supervisors/{$supervisor->id}/receive-back", [
                'amount' => null,
            ]);

        $response->assertOk()
            ->assertJsonPath('amount', 350.75)
            ->assertJsonPath('balance', 0);

        $this->assertEquals(0, (float) $supervisor->fresh()->balance);
    }

    public function test_admin_can_receive_back_exact_full_balance_as_amount(): void
    {
        $admin = User::factory()->admin()->create();
        $supervisor = User::factory()->supervisor()->withBalance(0)->create();

        Transaction::create([
            'user_id' => $supervisor->id,
            'type' => 'topup',
            'amount' => 250.50,
            'description' => 'Opening Balance',
            'date' => '2026-07-01',
        ]);

        $response = $this->actingAs($admin)
            ->postJson("/api/admin/supervisors/{$supervisor->id}/receive-back", [
                'amount' => 250.50,
            ]);

        $response->assertOk()
            ->assertJsonPath('amount', 250.50)
            ->assertJsonPath('balance', 0);

        $this->assertEquals(0, (float) $supervisor->fresh()->balance);
    }

    public function test_admin_can_receive_back_string_numeric_amount(): void
    {
        $admin = User::factory()->admin()->create();
        $supervisor = User::factory()->supervisor()->withBalance(0)->create();

        Transaction::create([
            'user_id' => $supervisor->id,
            'type' => 'topup',
            'amount' => 500,
            'description' => 'Opening Balance',
            'date' => '2026-07-01',
        ]);

        $response = $this->actingAs($admin)
            ->postJson("/api/admin/supervisors/{$supervisor->id}/receive-back", [
                'amount' => '125.50',
            ]);

        $response->assertOk()
            ->assertJsonPath('amount', 125.50)
            ->assertJsonPath('balance', 374.50);

        $this->assertEquals(374.50, (float) $supervisor->fresh()->balance);
    }
}