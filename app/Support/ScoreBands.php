<?php

namespace App\Support;

/**
 * Fixed 0–100 ranges for describing how grades or examination percentages
 * are spread. Descriptive only: standing always comes from the configured
 * passing and warning grades of a period, never from these ranges.
 */
final class ScoreBands
{
    /** Lower bound => label, highest range first. */
    private const BANDS = [90 => '90–100', 80 => '80–89.99', 70 => '70–79.99', 60 => '60–69.99', 0 => 'Below 60'];

    public const NO_SCORE = 'No grade';

    /**
     * Count of values per range, highest range first; null values are counted
     * under "No grade" when `$withMissing` is true and skipped otherwise.
     *
     * @param  iterable<float|int|null>  $values
     * @return list<array{band: string, count: int}>
     */
    public static function count(iterable $values, bool $withMissing = true): array
    {
        $counts = array_fill_keys(array_values(self::BANDS), 0);
        $missing = 0;

        foreach ($values as $value) {
            if ($value === null) {
                $missing++;

                continue;
            }

            $counts[self::bandOf((float) $value)]++;
        }

        if ($withMissing) {
            $counts[self::NO_SCORE] = $missing;
        }

        return array_map(fn (string $band, int $count): array => ['band' => $band, 'count' => $count], array_keys($counts), $counts);
    }

    /**
     * The same counts as chart columns, lowest range first so the chart
     * reads left to right; "No grade" stays last and is muted.
     *
     * @param  list<array{band: string, count: int}>  $counted  output of count()
     * @return list<array{label: string, value: int, muted: bool}>
     */
    public static function columns(array $counted): array
    {
        $ranges = array_values(array_filter($counted, fn (array $row): bool => $row['band'] !== self::NO_SCORE));
        $missing = array_values(array_filter($counted, fn (array $row): bool => $row['band'] === self::NO_SCORE));

        return array_map(
            fn (array $row): array => ['label' => $row['band'], 'value' => $row['count'], 'muted' => $row['band'] === self::NO_SCORE],
            [...array_reverse($ranges), ...$missing],
        );
    }

    private static function bandOf(float $value): string
    {
        foreach (self::BANDS as $lowerBound => $label) {
            if ($value >= $lowerBound) {
                return $label;
            }
        }

        return self::BANDS[0];
    }
}
