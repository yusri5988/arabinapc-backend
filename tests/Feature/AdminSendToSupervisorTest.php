<?php

namespace Tests\Feature;

use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminSendToSupervisorTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_send_cash_to_supervisor_without_admin_balance(): void
    {
        $admin = User::factory()->admin()->withBalance(0)->create(['name' => 'Admin Arabina']);
        $supervisor = User::factory()->supervisor()->withBalance(10)->create();

        $response = $this->actingAs($admin)
            ->postJson('/api/admin/topup', [
                'supervisor_id' => $supervisor->id,
                'amount' => 125.50,
            ]);

        $response->assertOk()
            ->assertJsonPath('message', 'Duit berjaya dihantar kepada supervisor.');

        $this->assertEquals(0, (float) $admin->fresh()->balance);
        $this->assertEquals(135.50, (float) $supervisor->fresh()->balance);

        $transaction = Transaction::first();

        $this->assertNotNull($transaction);
        $this->assertEquals($supervisor->id, $transaction->user_id);
        $this->assertEquals('topup', $transaction->type);
        $this->assertEquals(125.50, (float) $transaction->amount);
        $this->assertEquals('Duit diterima daripada Admin: Admin Arabina', $transaction->description);
        $this->assertEquals('admin_send_to_supervisor', $transaction->metadata['source']);
        $this->assertEquals($admin->id, $transaction->metadata['sent_by_user_id']);
    }

    public function test_admin_cannot_send_cash_to_non_supervisor_user(): void
    {
        $admin = User::factory()->admin()->create();
        $targetAdmin = User::factory()->admin()->create();

        $response = $this->actingAs($admin)
            ->postJson('/api/admin/topup', [
                'supervisor_id' => $targetAdmin->id,
                'amount' => 100,
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['supervisor_id']);

        $this->assertEquals(0, (float) $targetAdmin->fresh()->balance);
        $this->assertDatabaseCount('transactions', 0);
    }

    public function test_admin_dashboard_tracks_supervisor_cash_only(): void
    {
        $admin = User::factory()->admin()->withBalance(9999)->create();
        $supervisor = User::factory()->supervisor()->withBalance(250)->create();

        Transaction::create([
            'user_id' => $supervisor->id,
            'type' => 'topup',
            'amount' => 300,
            'description' => 'Duit masuk',
            'date' => now(),
        ]);

        Transaction::create([
            'user_id' => $supervisor->id,
            'type' => 'expense',
            'amount' => 50,
            'description' => 'Duit keluar',
            'date' => now(),
        ]);

        Transaction::create([
            'user_id' => $admin->id,
            'type' => 'topup',
            'amount' => 9999,
            'description' => 'Legacy admin topup',
            'date' => now(),
        ]);

        $response = $this->actingAs($admin)
            ->getJson('/api/admin/dashboard');

        $response->assertOk();

        $payload = $response->json();

        $this->assertArrayNotHasKey('total_admin_cash', $payload);
        $this->assertEquals(250, (float) $payload['total_supervisor_cash']);
        $this->assertEquals(300, (float) $payload['total_supervisor_in']);
        $this->assertEquals(50, (float) $payload['total_supervisor_out']);
    }

    public function test_admin_transaction_history_excludes_admin_transactions(): void
    {
        $admin = User::factory()->admin()->create();
        $supervisor = User::factory()->supervisor()->create();

        $supervisorTransaction = Transaction::create([
            'user_id' => $supervisor->id,
            'type' => 'topup',
            'amount' => 150,
            'description' => 'Duit masuk supervisor',
            'date' => now(),
        ]);

        Transaction::create([
            'user_id' => $admin->id,
            'type' => 'topup',
            'amount' => 5000,
            'description' => 'Legacy admin topup',
            'date' => now(),
        ]);

        $response = $this->actingAs($admin)
            ->getJson('/api/admin/transactions');

        $response->assertOk()
            ->assertJsonCount(1, 'transactions')
            ->assertJsonPath('transactions.0.id', $supervisorTransaction->id);
    }
}
