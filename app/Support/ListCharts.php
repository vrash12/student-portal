<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * Small charts shown above list pages, computed by the server from the same
 * filtered query as the list (so they respect filters and the user's scope).
 * The browser only draws them (resources/js/components/charts/list-charts.tsx).
 */
final class ListCharts
{
    /**
     * Rows counted per value of a column of the list's own table, largest
     * first. Ordering, limits and eager loads of the list query are dropped;
     * its conditions are kept.
     *
     * @param  Builder<Model>|QueryBuilder  $query
     * @param  string  $column  A column of the query's table (never user input).
     * @param  (callable(mixed): string)|null  $label  Turns a stored value into its label.
     * @param  (callable(mixed): ?string)|null  $tone  Turns a stored value into its chart color (toneOf), for pies.
     * @return list<array{label: string, value: int, tone?: ?string}>
     */
    public static function countBy(Builder|QueryBuilder $query, string $column, ?callable $label = null, int $limit = 10, ?callable $tone = null): array
    {
        $base = $query instanceof Builder ? (clone $query)->toBase() : clone $query;
        $base->orders = null;
        $base->columns = null;
        $base->limit = null;
        $base->offset = null;

        return $base
            ->selectRaw("{$column} as chart_key, count(*) as chart_count")
            ->groupBy($column)
            ->orderByDesc('chart_count')
            ->limit($limit)
            ->get()
            ->map(fn (object $row): array => [
                'label' => $label !== null ? $label($row->chart_key) : (string) ($row->chart_key ?? 'None'),
                'value' => (int) $row->chart_count,
                ...($tone !== null ? ['tone' => $tone($row->chart_key)] : []),
            ])
            ->values()
            ->all();
    }

    /**
     * A horizontal bar chart of counts (or amounts with `format: money`).
     *
     * @param  list<array{label: string, value: int|float|string}>  $items
     * @return array<string, mixed>
     */
    public static function bars(string $title, string $description, array $items, string $one, string $other, ?string $format = null): array
    {
        return ['kind' => 'bars', 'title' => $title, 'description' => $description, 'items' => $items, 'noun' => ['one' => $one, 'other' => $other], 'format' => $format];
    }

    /**
     * A pie: the share of each of a few categories, e.g. records by status.
     * Items without a tone take the category colors in order.
     *
     * @param  list<array{label: string, value: int, tone?: ?string}>  $items
     * @return array<string, mixed>
     */
    public static function pie(string $title, string $description, array $items, string $one, string $other): array
    {
        return ['kind' => 'pie', 'title' => $title, 'description' => $description, 'items' => $items, 'noun' => ['one' => $one, 'other' => $other], 'format' => null];
    }

    /**
     * A line of counts per calendar day, oldest first.
     *
     * @param  list<array{date: string, value: int}>  $points  dates as YYYY-MM-DD
     * @return array<string, mixed>
     */
    public static function dailyLine(string $title, string $description, string $seriesLabel, array $points, string $one, string $other): array
    {
        return [
            'kind' => 'line', 'title' => $title, 'description' => $description, 'items' => [], 'noun' => ['one' => $one, 'other' => $other], 'format' => null,
            'xLabel' => 'Day',
            'data' => ['xType' => 'date', 'series' => [['label' => $seriesLabel, 'tone' => 'c1', 'points' => $points]]],
        ];
    }

    /**
     * The chart color of a status badge tone (StatusBadge: success, warning,
     * danger, neutral, info), so a pie colors statuses as their badges do.
     */
    public static function toneOf(string $badgeTone): ?string
    {
        return match ($badgeTone) {
            'success' => 'passing',
            'warning' => 'atRisk',
            'danger' => 'failing',
            'neutral' => 'incomplete',
            'info' => 'c3',
            default => null,
        };
    }

    /**
     * A column chart, e.g. records per day.
     *
     * @param  list<array{label: string, value: int}>  $items
     * @return array<string, mixed>
     */
    public static function columns(string $title, string $description, array $items, string $one, string $other): array
    {
        return ['kind' => 'columns', 'title' => $title, 'description' => $description, 'items' => $items, 'noun' => ['one' => $one, 'other' => $other], 'format' => null];
    }
}
