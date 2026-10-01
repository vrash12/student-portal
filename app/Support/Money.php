<?php

namespace App\Support;

/**
 * Money is calculated in whole cents (integers), never floats, and stored in
 * DECIMAL(12,2) columns. The currency comes from configuration.
 */
final class Money
{
    public static function toCents(string|int|float|null $amount): int
    {
        if ($amount === null || $amount === '') {
            return 0;
        }

        // Decimal strings (from the database or validated input) are read exactly.
        if (is_string($amount) && preg_match('/^(-)?(\d+)(?:\.(\d{1,2})\d*)?$/', trim($amount), $parts) === 1) {
            $cents = (int) $parts[2] * 100 + (int) str_pad($parts[3] ?? '0', 2, '0');

            return $parts[1] === '-' ? -$cents : $cents;
        }

        return (int) round(((float) $amount) * 100);
    }

    /** Plain two-decimal string for storage and the interface, e.g. "1234.50" or "-80.00". */
    public static function decimal(int $cents): string
    {
        $sign = $cents < 0 ? '-' : '';
        $cents = abs($cents);

        return $sign.intdiv($cents, 100).'.'.str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT);
    }

    /** Two decimals with thousands separators, without a currency, e.g. "1,234.50" or "-80.00". */
    public static function format(int $cents): string
    {
        $sign = $cents < 0 ? '-' : '';
        $cents = abs($cents);

        return $sign.number_format(intdiv($cents, 100)).'.'.str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT);
    }

    /**
     * Printed form with the currency code (by default the configured one),
     * e.g. "PHP 1,234.50", or "(PHP 80.00)" for negatives (credit balances).
     */
    public static function display(int $cents, ?string $currency = null): string
    {
        $formatted = ($currency ?? (string) config('institution.currency')).' '.self::format(abs($cents));

        return $cents < 0 ? '('.$formatted.')' : $formatted;
    }
}
