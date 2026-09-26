<?php

namespace Tests\Unit;

use App\Services\GoogleSheetsDateFormatter;
use Tests\TestCase;

class GoogleSheetsDateFormatterTest extends TestCase
{
    public function test_it_formats_date_values_as_english_text(): void
    {
        $formatter = new GoogleSheetsDateFormatter;

        $this->assertSame('2 July 2026', $formatter->formatSheetValue('02/07/2026'));
        $this->assertSame('2 July 2026 14:30', $formatter->formatSheetValue('02/07/2026 14:30', true));
        $this->assertSame("'2 July 2026", $formatter->asText('2 July 2026'));
    }

    public function test_it_leaves_blank_values_and_skips_unrecognised_values(): void
    {
        $formatter = new GoogleSheetsDateFormatter;

        $this->assertSame('', $formatter->formatSheetValue(''));
        $this->assertNull($formatter->formatSheetValue('not a date'));
    }
}
