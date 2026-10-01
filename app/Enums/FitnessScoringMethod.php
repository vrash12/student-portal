<?php

namespace App\Enums;

/**
 * How a fitness event turns a result into points (FitnessStandard). Values
 * are also enforced by a CHECK constraint on fitness_events and
 * fitness_test_events.
 *
 * - Table: a points table set by staff, e.g. 25 push-ups = 60 points,
 *   30 = 70 points; a result earns the points of the best row it reaches.
 * - Scaled: the passing standard earns the passing points and the maximum
 *   standard 100 points, scaled in between (the original rule).
 */
enum FitnessScoringMethod: string
{
    case Table = 'table';
    case Scaled = 'scaled';

    public function label(): string
    {
        return match ($this) {
            self::Table => 'Points table',
            self::Scaled => 'Scaled between two standards',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Table => 'List results and the points each earns, such as 25 repetitions = 60 points and 30 = 70 points.',
            self::Scaled => 'The passing standard earns the passing points and the maximum standard 100 points; results in between are scaled.',
        };
    }

    /**
     * @return list<array{value: string, label: string, description: string}>
     */
    public static function options(): array
    {
        return array_map(fn (self $method): array => ['value' => $method->value, 'label' => $method->label(), 'description' => $method->description()], self::cases());
    }
}
