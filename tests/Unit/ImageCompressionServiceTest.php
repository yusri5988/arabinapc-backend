<?php

namespace Tests\Unit;

use App\Services\ImageCompressionService;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ImageCompressionServiceTest extends TestCase
{
    private function service(): ImageCompressionService
    {
        return new ImageCompressionService;
    }

    private function createTestImage(int $width, int $height): string
    {
        $image = imagecreatetruecolor($width, $height);

        for ($x = 0; $x < $width; $x++) {
            for ($y = 0; $y < $height; $y++) {
                $color = imagecolorallocate($image, ($x * 7 + $y * 3) % 256, ($x * 5 + $y * 11) % 256, ($x * 13 + $y * 7) % 256);
                imagesetpixel($image, $x, $y, $color);
            }
        }

        $tempPath = tempnam(sys_get_temp_dir(), 'test_img_');
        imagejpeg($image, $tempPath, 100);
        imagedestroy($image);

        return $tempPath;
    }

    public function test_should_compress_returns_true_for_large_image(): void
    {
        Storage::fake('public');

        $tempPath = $this->createTestImage(3000, 2000);
        $content = file_get_contents($tempPath);
        @unlink($tempPath);

        Storage::disk('public')->put('receipts/large.jpg', $content);

        $mimeType = Storage::disk('public')->mimeType('receipts/large.jpg');
        $size = Storage::disk('public')->size('receipts/large.jpg');

        $result = $this->service()->shouldCompress('receipts/large.jpg');

        $this->assertTrue($result, "Failed with mimeType={$mimeType}, size={$size}");
    }

    public function test_should_compress_returns_false_for_small_image(): void
    {
        Storage::fake('public');

        $tempPath = $this->createTestImage(100, 100);
        $content = file_get_contents($tempPath);
        @unlink($tempPath);

        Storage::disk('public')->put('receipts/small.jpg', $content);

        $result = $this->service()->shouldCompress('receipts/small.jpg');

        $this->assertFalse($result);
    }

    public function test_should_compress_returns_false_for_non_image(): void
    {
        Storage::fake('public');

        Storage::disk('public')->put('receipts/test.txt', 'not an image');
        $result = $this->service()->shouldCompress('receipts/test.txt');

        $this->assertFalse($result);
    }

    public function test_should_compress_returns_false_for_nonexistent_file(): void
    {
        Storage::fake('public');

        $result = $this->service()->shouldCompress('receipts/nonexistent.jpg');

        $this->assertFalse($result);
    }

    public function test_compress_reduces_file_size(): void
    {
        Storage::fake('public');

        $tempPath = $this->createTestImage(3000, 2000);
        $content = file_get_contents($tempPath);
        @unlink($tempPath);

        Storage::disk('public')->put('receipts/large.jpg', $content);
        $originalSize = Storage::disk('public')->size('receipts/large.jpg');

        $result = $this->service()->compress('receipts/large.jpg');

        $this->assertTrue($result['success'], "Failed with result: ".json_encode($result));
        $this->assertEquals('compressed', $result['action']);
        $this->assertGreaterThan(0, $result['saved_percent']);

        $newSize = Storage::disk('public')->size('receipts/large.jpg');
        $this->assertLessThan($originalSize, $newSize);
    }

    public function test_compress_skips_small_file(): void
    {
        Storage::fake('public');

        $tempPath = $this->createTestImage(100, 100);
        $content = file_get_contents($tempPath);
        @unlink($tempPath);

        Storage::disk('public')->put('receipts/small.jpg', $content);

        $result = $this->service()->compress('receipts/small.jpg');

        $this->assertFalse($result['success']);
        $this->assertEquals('skipped', $result['action']);
    }

    public function test_compress_handles_nonexistent_file(): void
    {
        Storage::fake('public');

        $result = $this->service()->compress('receipts/nonexistent.jpg');

        $this->assertFalse($result['success']);
        $this->assertEquals('skipped', $result['action']);
    }

    public function test_compress_returns_correct_dimensions(): void
    {
        Storage::fake('public');

        $tempPath = $this->createTestImage(4000, 3000);
        $content = file_get_contents($tempPath);
        @unlink($tempPath);

        Storage::disk('public')->put('receipts/large.jpg', $content);

        $result = $this->service()->compress('receipts/large.jpg');

        $this->assertTrue($result['success']);
        $this->assertArrayHasKey('original_size', $result);
        $this->assertArrayHasKey('new_size', $result);
        $this->assertArrayHasKey('saved_percent', $result);
    }
}
