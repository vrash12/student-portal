<?php

namespace App\Services\Fitness;

use App\Enums\FitnessUnit;

/**
 * The standard of one fitness event and the single scoring rule
 * (AGENTS.md §15: one authoritative calculation, never in the browser):
 *
 * - meeting the passing value scores 60 points, the maximum value 100;
 *   results in between are scaled linearly, results beyond the maximum stay
 *   at 100;
 * - results short of the passing value score proportionally below 60
 *   (half the passing repetitions score 30; twice the passing time scores 30)
 *   and fail the event.
 *
 * Times are in seconds, where lower is better unless configured otherwise.
 */
final readonly class FitnessStandard
{
    public const PASSING_POINTS = 60.0;

    public const MAXIMUM_POINTS = 100.0;

    public function __construct(
        public FitnessUnit $unit,
        public bool $higherIsBetter,
        public float $passingValue,
        public float $maximumValue,
    ) {}

    public function passes(float $value): bool
    {
        return $this->higherIsBetter ? $value >= $this->passingValue : $value <= $this->passingValue;
    }

    public function points(float $value): float
    {
        $span = self::MAXIMUM_POINTS - self::PASSING_POINTS;

        if ($this->passes($value)) {
            $progress = $this->higherIsBetter
                ? ($value - $this->passingValue) / ($this->maximumValue - $this->passingValue)
                : ($this->passingValue - $value) / ($this->passingValue - $this->maximumValue);

            return round(min(self::MAXIMUM_POINTS, self::PASSING_POINTS + $span * $progress), 2);
        }

        $share = $this->higherIsBetter ? $value / $this->passingValue : $this->passingValue / max($value, 0.01);

        return round(max(0.0, self::PASSING_POINTS * $share), 2);
    }

    /**
     * @return array{unit: string, higherIsBetter: bool, passingValue: float, maximumValue: float, passingDisplay: string, maximumDisplay: string}
     */
    public function toArray(): array
    {
        return [
            'unit' => $this->unit->value,
            'higherIsBetter' => $this->higherIsBetter,
            'passingValue' => $this->passingValue,
            'maximumValue' => $this->maximumValue,
            'passingDisplay' => FitnessValue::format($this->passingValue, $this->unit),
            'maximumDisplay' => FitnessValue::format($this->maximumValue, $this->unit),
        ];
    }
}
