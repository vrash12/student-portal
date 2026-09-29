<?php

namespace App\Support;

/**
 * Helpers for two-decimal values (scores, maximum scores, weights) as they
 * are stored in DECIMAL columns. Comparisons use whole hundredths so
 * "45", "45.0", and "45.00" are the same value.
 */
final class DecimalValue
{
    /**
     * Canonical two-decimal string ("45" becomes "45.00"), or null.
     */
    public static function normalize(string|int|float|null $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return number_format(self::toHundredths($value) / 100, 2, '.', '');
    }

    public static function toHundredths(string|int|float $value): int
    {
        return (int) round((float) $value * 100);
    }

    /**
     * Compact display form without trailing zeros ("45.50" becomes "45.5").
     */
    public static function display(string|int|float $value): string
    {
        $formatted = rtrim(rtrim(number_format(self::toHundredths($value) / 100, 2, '.', ''), '0'), '.');

        return $formatted === '-0' ? '0' : $formatted;
    }
}
