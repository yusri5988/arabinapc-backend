<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ProcessItemImageTest extends TestCase
{
    use RefreshDatabase;

    public function test_supervisor_can_upload_item_image_under_15_mib(): void
    {
        Storage::fake('public');
        $supervisor = User::factory()->supervisor()->create();

        $response = $this->actingAs($supervisor)
            ->postJson('/api/supervisor/process-item-image', [
                'item_image' => UploadedFile::fake()->image('item.jpg'),
                'site_id' => 'A102',
            ]);

        $response->assertOk()->assertJsonStructure(['image_url', 'file_name']);
        $this->assertDatabaseHas('activity_logs', [
            'user_id' => $supervisor->id,
            'action' => 'item_image.validation',
            'status' => 'success',
        ]);
    }

    public function test_item_image_over_15_mib_is_rejected_and_logged(): void
    {
        $supervisor = User::factory()->supervisor()->create();

        $response = $this->actingAs($supervisor)
            ->postJson('/api/supervisor/process-item-image', [
                'item_image' => UploadedFile::fake()->create('large-item.jpg', 15361, 'image/jpeg'),
                'site_id' => 'A102',
            ]);

        $response->assertStatus(422)->assertJsonValidationErrors('item_image');
        $this->assertDatabaseHas('activity_logs', [
            'user_id' => $supervisor->id,
            'action' => 'item_image.validation',
            'status' => 'fail',
        ]);
    }

    public function test_item_image_non_image_is_rejected(): void
    {
        $supervisor = User::factory()->supervisor()->create();

        $response = $this->actingAs($supervisor)
            ->postJson('/api/supervisor/process-item-image', [
                'item_image' => UploadedFile::fake()->create('document.pdf', 100, 'application/pdf'),
                'site_id' => 'A102',
            ]);

        $response->assertStatus(422)->assertJsonValidationErrors('item_image');
    }
}
