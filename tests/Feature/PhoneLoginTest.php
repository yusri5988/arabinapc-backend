<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class PhoneLoginTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_login_with_phone_number(): void
    {
        $user = User::factory()->admin()->create([
            'phone' => '0123456789',
            'password' => Hash::make('secret123'),
        ]);

        $response = $this->postJson('/api/login', [
            'phone' => '012-345 6789',
            'password' => 'secret123',
        ]);

        $response->assertOk()
            ->assertJsonPath('token_type', 'Bearer')
            ->assertJsonPath('user.id', $user->id)
            ->assertJsonPath('user.phone', '0123456789');

        $this->assertArrayHasKey('access_token', $response->json());
    }

    public function test_email_is_not_accepted_for_login(): void
    {
        User::factory()->admin()->create([
            'phone' => '0123456789',
            'password' => Hash::make('secret123'),
        ]);

        $response = $this->postJson('/api/login', [
            'email' => 'admin@arabina.com',
            'password' => 'secret123',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['phone']);
    }

    public function test_admin_creates_supervisor_with_phone_number(): void
    {
        $admin = User::factory()->admin()->create();

        $response = $this->actingAs($admin)
            ->postJson('/api/admin/supervisors', [
                'name' => 'Ahmad',
                'phone' => '+60 12-345 6789',
                'password' => 'secret123',
            ]);

        $response->assertCreated()
            ->assertJsonPath('name', 'Ahmad')
            ->assertJsonPath('phone', '+60123456789');

        $this->assertDatabaseHas('users', [
            'name' => 'Ahmad',
            'phone' => '+60123456789',
            'role' => 'supervisor',
        ]);
    }
}
