<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use DateTimeInterface;

class GoogleSheetsDateFormatter
{
    public function formatDate(?DateTimeInterface $date): string
    {
        return $date?->format('j F Y') ?? '';
    }

    public function formatDateTime(?DateTimeInterface $date): string
    {
        return $date?->format('j F Y H:i') ?? '';
    }

    /**
     * Convert a value read from Google Sheets into the requested display format.
     * Returns null when the value is not a recognisable date.
     */
    public function formatSheetValue(mixed $value, bool $includeTime = false): ?string
    {
        if ($value === null || trim((string) $value) === '') {
            return '';
        }

        $date = $this->parseSheetValue($value);

        if (! $date) {
            return null;
        }

        return $includeTime
            ? $this->formatDateTime($date)
            : $this->formatDate($date);
    }

    public function asText(string $value): string
    {
        return $value === '' ? '' : "'{$value}";
    }

    private function parseSheetValue(mixed $value): ?CarbonImmutable
    {
        if ($value instanceof DateTimeInterface) {
            return CarbonImmutable::instance($value);
        }

        $value = ltrim(trim((string) $value), "'");
        $timezone = config('app.timezone', 'UTC');

        foreach ([
            'j F Y H:i',
            'j F Y',
            'd/m/Y H:i:s',
            'd/m/Y H:i',
            'd/m/Y',
            'd-m-Y H:i:s',
            'd-m-Y H:i',
            'd-m-Y',
            'Y-m-d H:i:s',
            'Y-m-d H:i',
            'Y-m-d',
            'F j, Y H:i',
            'F j, Y',
        ] as $format) {
            try {
                $date = CarbonImmutable::createFromFormat($format, $value, $timezone);
            } catch (\Throwable) {
                continue;
            }

            $errors = CarbonImmutable::getLastErrors();

            if ($date && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))) {
                return $date;
            }
        }

        if (is_numeric($value) && (float) $value >= 1) {
            return CarbonImmutable::create(1899, 12, 30, 0, 0, 0, $timezone)
                ->addDays((int) $value)
                ->addSeconds((int) round(((float) $value - (int) $value) * 86400));
        }

        return null;
    }
}
