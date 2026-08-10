<?php

namespace Tests\Feature;

use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SyncImagesToGoogleDriveCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_syncs_local_images_to_google_drive_without_rewriting_database_urls(): void
    {
        Storage::fake('public');
        Storage::fake('google');
        Storage::disk('public')->put('receipts/SITE1/receipt.jpg', 'receipt');
        Storage::disk('public')->put('expense-items/SITE2/item.jpg', 'item');
        Storage::disk('public')->put('receipts/root-receipt.jpg', 'ignored');
        Storage::disk('google')->put('receipts/SITE1/receipt.jpg', 'already synced');

        $user = User::factory()->supervisor()->create();
        Transaction::create([
            'user_id' => $user->id,
            'type' => 'expense',
            'amount' => 25,
            'description' => 'Test sync',
            'site_id' => 'SITE1',
            'receipt_url' => '/storage/receipts/SITE1/receipt.jpg',
            'date' => '2026-05-16',
        ]);

        $this->artisan('images:sync-google-drive')
            ->assertExitCode(0);

        Storage::disk('google')->assertExists('expense-items/SITE2/item.jpg');

        Storage::disk('public')->assertExists('receipts/SITE1/receipt.jpg');

        $this->assertDatabaseHas('transactions', [
            'id' => 1,
            'receipt_url' => '/storage/receipts/SITE1/receipt.jpg',
        ]);
    }
}
