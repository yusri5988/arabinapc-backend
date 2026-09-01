<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AdminMediaTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_upload_receipt(): void
    {
        Storage::fake('public');
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

        $admin = User::factory()->admin()->create();
        $supervisor = User::factory()->supervisor()->withBalance(500)->create();

        $file = UploadedFile::fake()->image('receipt.jpg');

        $response = $this->actingAs($admin)
            ->postJson('/api/admin/process-receipt', [
                'receipt' => $file,
                'site_id' => 'A102',
                'supervisor_id' => $supervisor->id,
            ]);

        $response->assertStatus(200)
            ->assertJsonStructure(['job_id', 'receipt_url']);

        $this->assertNotEmpty($response->json('job_id'));
        $this->assertNotEmpty($response->json('receipt_url'));

        $files = Storage::disk('public')->allFiles('receipts/A102');
        $this->assertNotEmpty($files);

        $result = Cache::get("job_result:{$response->json('job_id')}");
        $this->assertSame('completed', $result['status']);
    }

    public function test_admin_receipt_upload_requires_supervisor_role(): void
    {
        $admin = User::factory()->admin()->create();
        $regularUser = User::factory()->developer()->create();
        $file = UploadedFile::fake()->image('receipt.jpg');

        $response = $this->actingAs($admin)
            ->postJson('/api/admin/process-receipt', [
                'receipt' => $file,
                'site_id' => 'A102',
                'supervisor_id' => $regularUser->id,
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors('supervisor_id');
    }

    public function test_admin_item_image_upload(): void
    {
        Storage::fake('public');

        $admin = User::factory()->admin()->create();
        $supervisor = User::factory()->supervisor()->create();

        $response = $this->actingAs($admin)
            ->postJson('/api/admin/process-item-image', [
                'item_image' => UploadedFile::fake()->image('item.jpg'),
                'site_id' => 'A102',
                'supervisor_id' => $supervisor->id,
            ]);

        $response->assertOk()
            ->assertJsonStructure(['image_url', 'file_name']);
    }

    public function test_supervisor_cannot_access_admin_receipt_endpoint(): void
    {
        $supervisor = User::factory()->supervisor()->create();
        $file = UploadedFile::fake()->image('receipt.jpg');

        $response = $this->actingAs($supervisor)
            ->postJson('/api/admin/process-receipt', [
                'receipt' => $file,
                'site_id' => 'A102',
                'supervisor_id' => $supervisor->id,
            ]);

        $response->assertStatus(403);
    }

    public function test_admin_receipt_rejects_large_images(): void
    {
        $admin = User::factory()->admin()->create();
        $supervisor = User::factory()->supervisor()->create();
        $file = UploadedFile::fake()->create('large-receipt.jpg', 20481, 'image/jpeg');

        $response = $this->actingAs($admin)
            ->postJson('/api/admin/process-receipt', [
                'receipt' => $file,
                'site_id' => 'A102',
                'supervisor_id' => $supervisor->id,
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors('receipt');
    }

    public function test_admin_can_create_expense_with_receipt_and_item_images(): void
    {
        $admin = User::factory()->admin()->create();
        $supervisor = User::factory()->supervisor()->withBalance(500)->create();

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
                'item_images' => [
                    ['url' => '/storage/expense-items/A101/item1.jpg', 'name' => 'item1.jpg'],
                ],
                'date' => '2024-01-02',
            ])
            ->assertCreated()
            ->assertJsonPath('transaction.type', 'expense');

        $this->assertDatabaseHas('transactions', [
            'user_id' => $supervisor->id,
            'type' => 'expense',
            'amount' => 50,
            'receipt_url' => '/storage/receipts/A101/receipt.jpg',
        ]);

        $transaction = \App\Models\Transaction::where('user_id', $supervisor->id)->first();
        $this->assertEquals('admin_transaction_crud', $transaction->metadata['source']);
        $this->assertEquals($admin->id, $transaction->metadata['created_by_user_id']);
        $this->assertCount(1, $transaction->metadata['item_images']);
    }
}
