<?php

namespace Tests\Unit;

use App\Support\Money;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Statement of Account amounts are whole cents, never floats (AGENTS.md §52:
 * deterministic, reproducible results).
 */
class MoneyTest extends TestCase
{
    /**
     * @return array<string, array{string|int|float|null, int}>
     */
    public static function amounts(): array
    {
        return [
            'decimal string' => ['1250.50', 125050],
            'one decimal' => ['1250.5', 125050],
            'whole number string' => ['1250', 125000],
            'negative database sum' => ['-80.00', -8000],
            'negative cents only' => ['-0.05', -5],
            'zero' => ['0.00', 0],
            'surrounding spaces' => [' 12.30 ', 1230],
            'largest column value' => ['9999999999.99', 999999999999],
            'empty' => ['', 0],
            'null' => [null, 0],
            'integer' => [42, 4200],
            // Floats are rounded to the nearest cent, never truncated.
            'float' => [0.1 + 0.2, 30],
            'float just below' => [19.999999, 2000],
        ];
    }

    #[DataProvider('amounts')]
    public function test_amounts_are_read_as_whole_cents(string|int|float|null $amount, int $cents): void
    {
        $this->assertSame($cents, Money::toCents($amount));
    }

    public function test_sums_of_cents_are_exact(): void
    {
        $total = 0;
        foreach (array_fill(0, 10, '0.10') as $amount) {
            $total += Money::toCents($amount);
        }

        $this->assertSame(100, $total);
        $this->assertSame('1.00', Money::decimal($total));
    }

    public function test_decimal_strings_have_two_decimals_and_a_sign(): void
    {
        $this->assertSame('1234.50', Money::decimal(123450));
        $this->assertSame('0.05', Money::decimal(5));
        $this->assertSame('0.00', Money::decimal(0));
        $this->assertSame('-80.00', Money::decimal(-8000));
        $this->assertSame('-0.07', Money::decimal(-7));
        $this->assertSame('9999999999.99', Money::decimal(999999999999));
    }

    public function test_formatted_amounts_have_thousands_separators(): void
    {
        $this->assertSame('1,234,567.89', Money::format(123456789));
        $this->assertSame('-1,000.00', Money::format(-100000));
        $this->assertSame('0.50', Money::format(50));
    }

    public function test_printed_amounts_show_the_currency_and_credit_balances_in_parentheses(): void
    {
        $this->assertSame('PHP 1,250.00', Money::display(125000, 'PHP'));
        $this->assertSame('(PHP 80.00)', Money::display(-8000, 'PHP'));
        $this->assertSame('USD 0.00', Money::display(0, 'USD'));
    }

    public function test_round_trip_keeps_the_value(): void
    {
        foreach ([1, 99, 100, 123456, -123456, 999999999999] as $cents) {
            $this->assertSame($cents, Money::toCents(Money::decimal($cents)));
        }
    }
}
