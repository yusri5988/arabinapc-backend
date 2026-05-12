<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AdminResetStaffPasswordsTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_reset_one_staff_password_to_default(): void
    {
        $admin = User::factory()->admin()->create(['password' => Hash::make('admin-secret')]);
        $supervisorOne = User::factory()->supervisor()->create(['password' => Hash::make('old-password-1')]);
        $supervisorTwo = User::factory()->supervisor()->create(['password' => Hash::make('old-password-2')]);

        $response = $this->actingAs($admin)
            ->postJson("/api/admin/supervisors/{$supervisorOne->id}/reset-password");

        $response->assertOk()
            ->assertJsonPath('message', 'Password staff berjaya direset kepada 123456.')
            ->assertJsonPath('supervisor.id', $supervisorOne->id);

        $this->assertTrue(Hash::check('123456', $supervisorOne->fresh()->password));
        $this->assertTrue(Hash::check('old-password-2', $supervisorTwo->fresh()->password));
        $this->assertTrue(Hash::check('admin-secret', $admin->fresh()->password));
    }

    public function test_admin_cannot_reset_admin_password_through_staff_endpoint(): void
    {
        $admin = User::factory()->admin()->create(['password' => Hash::make('admin-secret')]);
        $targetAdmin = User::factory()->admin()->create(['password' => Hash::make('target-secret')]);

        $response = $this->actingAs($admin)
            ->postJson("/api/admin/supervisors/{$targetAdmin->id}/reset-password");

        $response->assertStatus(404);

        $this->assertTrue(Hash::check('target-secret', $targetAdmin->fresh()->password));
    }

    public function test_supervisor_cannot_reset_staff_password(): void
    {
        $supervisor = User::factory()->supervisor()->create();
        $targetSupervisor = User::factory()->supervisor()->create();

        $response = $this->actingAs($supervisor)
            ->postJson("/api/admin/supervisors/{$targetSupervisor->id}/reset-password");

        $response->assertStatus(403);
    }
}
