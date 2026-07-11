<?php

namespace Tests\Feature;

use App\Jobs\ProcessReceiptJob;
use App\Models\User;
use App\Services\ImageCompressionService;
use App\Services\ReceiptProcessingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ProcessReceiptTest extends TestCase
{
    use RefreshDatabase;

    public function test_supervisor_can_upload_receipt_and_get_ai_response(): void
    {
        Storage::fake('public');
        Queue::fake([ProcessReceiptJob::class]);

        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'id' => 'msg_abc123',
                'type' => 'message',
                'role' => 'assistant',
                'content' => [
                    [
                        'type' => 'text',
                        'text' => '{"date": "2024-03-15", "amount": 45.90, "description": "Makan minum site"}',
                    ],
                ],
                'stop_reason' => 'end_turn',
            ], 200),
        ]);

        $supervisor = User::factory()->supervisor()->withBalance(500)->create();

        $file = UploadedFile::fake()->image('receipt.jpg');

        $response = $this->actingAs($supervisor)
            ->postJson('/api/supervisor/process-receipt', [
                'receipt' => $file,
                'site_id' => 'A102',
            ]);

        $response->assertStatus(200)
            ->assertJsonStructure(['job_id', 'receipt_url']);

        $this->assertNotEmpty($response->json('job_id'));
        $this->assertNotEmpty($response->json('receipt_url'));

        $files = Storage::disk('public')->allFiles('receipts/A102');
        $this->assertNotEmpty($files);

        Queue::assertPushed(ProcessReceiptJob::class);
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
                'site_id' => 'A102',
            ]);

        $response->assertStatus(403);
    }

    public function test_process_receipt_requires_file(): void
    {
        $supervisor = User::factory()->supervisor()->create();

        $response = $this->actingAs($supervisor)
            ->postJson('/api/supervisor/process-receipt', [
                'site_id' => 'A102',
            ]);

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
                'site_id' => 'A102',
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors('receipt');
    }

    public function test_process_receipt_rejects_images_over_15_mib_and_logs_validation_failure(): void
    {
        $supervisor = User::factory()->supervisor()->create();
        $file = UploadedFile::fake()->create('large-receipt.jpg', 15361, 'image/jpeg');

        $response = $this->actingAs($supervisor)
            ->postJson('/api/supervisor/process-receipt', [
                'receipt' => $file,
                'site_id' => 'A102',
            ]);

        $response->assertStatus(422)->assertJsonValidationErrors('receipt');
        $this->assertDatabaseHas('activity_logs', [
            'user_id' => $supervisor->id,
            'action' => 'receipt.validation',
            'status' => 'fail',
        ]);
    }

    public function test_process_receipt_handles_claude_failure_gracefully(): void
    {
        Storage::fake('public');

        Http::fake([
            'api.anthropic.com/*' => Http::response(['error' => ['message' => 'Rate limit']], 429),
        ]);

        $supervisor = User::factory()->supervisor()->create();
        $file = UploadedFile::fake()->image('receipt.jpg');

        $response = $this->actingAs($supervisor)
            ->postJson('/api/supervisor/process-receipt', [
                'receipt' => $file,
                'site_id' => 'A102',
            ]);

        $response->assertStatus(200)
            ->assertJsonStructure(['job_id', 'receipt_url']);

        $jobId = $response->json('job_id');
        $receiptUrl = $response->json('receipt_url');

        $storedFiles = Storage::disk('public')->allFiles('receipts/A102');
        $this->assertNotEmpty($storedFiles);

        $job = new ProcessReceiptJob($storedFiles[0], $receiptUrl, $jobId);
        $job->handle(app(ReceiptProcessingService::class), app(ImageCompressionService::class));

        $result = Cache::get("job_result:{$jobId}");
        $this->assertEquals('completed', $result['status']);
        $this->assertEquals('Gagal baca resit. Sila isi borang secara manual.', $result['data']['description']);
        $this->assertEquals(0, $result['data']['amount']);
    }

    public function test_receipt_status_polling_returns_processing(): void
    {
        Storage::fake('public');
        Queue::fake([ProcessReceiptJob::class]);

        $supervisor = User::factory()->supervisor()->create();
        $file = UploadedFile::fake()->image('receipt.jpg');

        $response = $this->actingAs($supervisor)
            ->postJson('/api/supervisor/process-receipt', [
                'receipt' => $file,
                'site_id' => 'A102',
            ]);

        $jobId = $response->json('job_id');

        $statusResponse = $this->actingAs($supervisor)
            ->getJson("/api/supervisor/receipt-status/{$jobId}");

        $statusResponse->assertStatus(200)
            ->assertJsonPath('status', 'processing');
    }

    public function test_receipt_status_returns_not_found_for_invalid_job(): void
    {
        $supervisor = User::factory()->supervisor()->create();

        $response = $this->actingAs($supervisor)
            ->getJson('/api/supervisor/receipt-status/nonexistent-id');

        $response->assertStatus(404);
    }
}
