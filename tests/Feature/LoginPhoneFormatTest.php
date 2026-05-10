<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class LoginPhoneFormatTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Create a test user with normalized phone format
        User::create([
            'name' => 'Test User',
            'phone' => '0123456789',
            'password' => Hash::make('password'),
            'role' => 'admin',
            'balance' => 0,
        ]);
    }

    /** @test */
    public function test_it_can_login_with_standard_format()
    {
        $response = $this->postJson('/api/login', [
            'phone' => '0123456789',
            'password' => 'password',
        ]);

        $response->assertStatus(200)
            ->assertJsonStructure(['access_token', 'user']);
    }

    /** @test */
    public function test_it_normalizes_and_logs_in_with_plus_60_prefix()
    {
        $response = $this->postJson('/api/login', [
            'phone' => '+60123456789',
            'password' => 'password',
        ]);

        $response->assertStatus(200);
    }

    /** @test */
    public function test_it_normalizes_and_logs_in_with_60_prefix()
    {
        $response = $this->postJson('/api/login', [
            'phone' => '60123456789',
            'password' => 'password',
        ]);

        $response->assertStatus(200);
    }

    /** @test */
    public function test_it_normalizes_and_logs_in_with_spaces_and_dashes()
    {
        $response = $this->postJson('/api/login', [
            'phone' => '012-345 6789',
            'password' => 'password',
        ]);

        $response->assertStatus(200);
    }

    /** @test */
    public function test_it_rejects_invalid_phone_formats()
    {
        $invalidPhones = [
            '123456789',   // No leading 0
            '0223456789',  // Not 01 format
            '012345',      // Too short
            '012345678901', // Too long
            'abcdefghij',  // Not numeric
        ];

        foreach ($invalidPhones as $phone) {
            $response = $this->postJson('/api/login', [
                'phone' => $phone,
                'password' => 'password',
            ]);

            $response->assertStatus(422)
                ->assertJsonValidationErrors(['phone']);
        }
    }
}
