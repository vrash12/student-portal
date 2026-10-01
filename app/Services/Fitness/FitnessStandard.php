<?php

namespace App\Services\Fitness;

use App\Enums\FitnessScoringMethod;
use App\Enums\FitnessUnit;
use InvalidArgumentException;

/**
 * The standard of one fitness event and the single scoring rule
 * (AGENTS.md §15: one authoritative calculation, never in the browser).
 * Points are on a 0-100 scale; an event is passed with at least its passing
 * points (60 unless configured otherwise).
 *
 * Points table (owner request 2026-10-02): staff list results and the
 * points each earns. A result earns the points of the best row it reaches
 * (at least that many repetitions, or that time or faster); a result short
 * of the first row earns 0 points. Better results never earn fewer points.
 *
 * Scaled (the original rule):
 * - meeting the passing value earns the passing points, the maximum value
 *   100; results in between are scaled linearly, results beyond the maximum
 *   stay at 100;
 * - results short of the passing value earn proportionally fewer than the
 *   passing points (half the passing repetitions earn half; twice the
 *   passing time earns half) and fail the event.
 *
 * Times are in seconds, where lower is better unless configured otherwise.
 */
final readonly class FitnessStandard
{
    public const DEFAULT_PASSING_POINTS = 60.0;

    public const MAXIMUM_POINTS = 100.0;

    /**
     * Use pointsTable() for a points table. For a table, passingValue and
     * maximumValue are the results that first reach the passing points and
     * the table's top points.
     *
     * @param  list<array{value: float, points: float}>  $table  rows from the weakest to the best result (points table only)
     */
    public function __construct(
        public FitnessUnit $unit,
        public bool $higherIsBetter,
        public float $passingValue,
        public float $maximumValue,
        public float $passingPoints = self::DEFAULT_PASSING_POINTS,
        public FitnessScoringMethod $method = FitnessScoringMethod::Scaled,
        public array $table = [],
    ) {}

    /**
     * @param  list<array{value: float|int|string, points: float|int|string}>  $rows  in any order
     *
     * @throws InvalidArgumentException when no row reaches the passing points
     */
    public static function pointsTable(FitnessUnit $unit, bool $higherIsBetter, array $rows, float $passingPoints = self::DEFAULT_PASSING_POINTS): self
    {
        $table = self::sortRows($rows, $higherIsBetter);
        $passing = null;
        foreach ($table as $row) {
            if ($row['points'] >= $passingPoints) {
                $passing = $row['value'];
                break;
            }
        }
        if ($passing === null) {
            throw new InvalidArgumentException('No row of the points table reaches the passing points.');
        }

        $topPoints = $table[count($table) - 1]['points'];
        $maximum = $table[array_key_first(array_filter($table, fn (array $row): bool => $row['points'] >= $topPoints))]['value'];

        return new self($unit, $higherIsBetter, $passing, $maximum, $passingPoints, FitnessScoringMethod::Table, $table);
    }

    /**
     * The standard stored on a fitness event or test event (FitnessEvent,
     * FitnessTestEvent): the points table, or the passing and maximum values.
     *
     * @param  list<array{value: float|int|string, points: float|int|string}>|null  $table
     */
    public static function fromColumns(FitnessUnit $unit, bool $higherIsBetter, FitnessScoringMethod $method, float $passingPoints, ?float $passingValue, ?float $maximumValue, ?array $table): self
    {
        if ($method === FitnessScoringMethod::Table) {
            return self::pointsTable($unit, $higherIsBetter, $table ?? [], $passingPoints);
        }

        return new self($unit, $higherIsBetter, (float) $passingValue, (float) $maximumValue, $passingPoints);
    }

    /**
     * Rows ordered from the weakest to the best result: fewest repetitions
     * first, or slowest time first when lower is better.
     *
     * @param  list<array{value: float|int|string, points: float|int|string}>  $rows
     * @return list<array{value: float, points: float}>
     */
    public static function sortRows(array $rows, bool $higherIsBetter): array
    {
        $table = array_map(fn (array $row): array => ['value' => (float) $row['value'], 'points' => (float) $row['points']], array_values($rows));
        usort($table, fn (array $a, array $b): int => $higherIsBetter ? $a['value'] <=> $b['value'] : $b['value'] <=> $a['value']);

        return $table;
    }

    public function passes(float $value): bool
    {
        return $this->method === FitnessScoringMethod::Table
            ? $this->tablePoints($value) >= $this->passingPoints
            : $this->reaches($value, $this->passingValue);
    }

    public function points(float $value): float
    {
        if ($this->method === FitnessScoringMethod::Table) {
            return $this->tablePoints($value);
        }

        $span = self::MAXIMUM_POINTS - $this->passingPoints;

        if ($this->passes($value)) {
            $progress = $this->higherIsBetter
                ? ($value - $this->passingValue) / ($this->maximumValue - $this->passingValue)
                : ($this->passingValue - $value) / ($this->passingValue - $this->maximumValue);

            return round(min(self::MAXIMUM_POINTS, $this->passingPoints + $span * $progress), 2);
        }

        $share = $this->higherIsBetter ? $value / $this->passingValue : $this->passingValue / max($value, 0.01);

        return round(max(0.0, $this->passingPoints * $share), 2);
    }

    /** Points earned at the maximum value: 100, or the top of the points table. */
    public function maximumPoints(): float
    {
        return $this->method === FitnessScoringMethod::Table ? $this->table[count($this->table) - 1]['points'] : self::MAXIMUM_POINTS;
    }

    /**
     * @return array{method: string, unit: string, higherIsBetter: bool, passingPoints: float, maximumPoints: float, passingValue: float, maximumValue: float, passingDisplay: string, maximumDisplay: string, table: list<array{value: float, display: string, points: float}>}
     */
    public function toArray(): array
    {
        return [
            'method' => $this->method->value,
            'unit' => $this->unit->value,
            'higherIsBetter' => $this->higherIsBetter,
            'passingPoints' => $this->passingPoints,
            'maximumPoints' => $this->maximumPoints(),
            'passingValue' => $this->passingValue,
            'maximumValue' => $this->maximumValue,
            'passingDisplay' => FitnessValue::format($this->passingValue, $this->unit),
            'maximumDisplay' => FitnessValue::format($this->maximumValue, $this->unit),
            'table' => array_map(fn (array $row): array => [
                'value' => $row['value'],
                'display' => FitnessValue::format($row['value'], $this->unit),
                'points' => $row['points'],
            ], $this->table),
        ];
    }

    private function tablePoints(float $value): float
    {
        $points = 0.0;
        foreach ($this->table as $row) {
            if (! $this->reaches($value, $row['value'])) {
                break;
            }
            $points = $row['points'];
        }

        return $points;
    }

    /** Whether the result is at least as good as the standard. */
    private function reaches(float $value, float $standard): bool
    {
        return $this->higherIsBetter ? $value >= $standard : $value <= $standard;
    }
}
