<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProcessReceiptTest extends TestCase
{
    use RefreshDatabase;

    public function test_supervisor_can_upload_receipt_and_get_ai_response(): void
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

        $file = UploadedFile::fake()->image('receipt.jpg');

        $response = $this->actingAs($supervisor)
            ->postJson('/api/supervisor/process-receipt', [
                'receipt' => $file,
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('date', '2024-03-15')
            ->assertJsonPath('amount', 45.90)
            ->assertJsonPath('description', 'Makan minum site')
            ->assertJsonStructure(['date', 'amount', 'description', 'receipt_url']);

        $files = Storage::disk('public')->files('receipts');
        $this->assertNotEmpty($files);
    }

    public function test_process_receipt_requires_authentication(): void
    {
        $file = UploadedFile::fake()->image('receipt.jpg');

        $response = $this->postJson('/api/supervisor/process-receipt', [
            'receipt' => $file,
        ]);

        $response->assertStatus(401);
    }

    public function test_process_receipt_requires_supervisor_role(): void
    {
        $admin = User::factory()->admin()->create();
        $file = UploadedFile::fake()->image('receipt.jpg');

        $response = $this->actingAs($admin)
            ->postJson('/api/supervisor/process-receipt', [
                'receipt' => $file,
            ]);

        $response->assertStatus(403);
    }

    public function test_process_receipt_requires_file(): void
    {
        $supervisor = User::factory()->supervisor()->create();

        $response = $this->actingAs($supervisor)
            ->postJson('/api/supervisor/process-receipt', []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors('receipt');
    }

    public function test_process_receipt_requires_image_file(): void
    {
        Storage::fake('public');
        $supervisor = User::factory()->supervisor()->create();

        $file = UploadedFile::fake()->create('document.pdf', 100);

        $response = $this->actingAs($supervisor)
            ->postJson('/api/supervisor/process-receipt', [
                'receipt' => $file,
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors('receipt');
    }

    public function test_process_receipt_handles_gemini_failure_gracefully(): void
    {
        Storage::fake('public');
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response(['error' => 'Rate limit'], 429),
        ]);

        $supervisor = User::factory()->supervisor()->create();
        $file = UploadedFile::fake()->image('receipt.jpg');

        $response = $this->actingAs($supervisor)
            ->postJson('/api/supervisor/process-receipt', [
                'receipt' => $file,
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('description', 'Gagal baca resit. Sila isi borang secara manual.');

        $this->assertEquals(0, $response->json('amount'));
    }
}
