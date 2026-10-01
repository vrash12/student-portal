<?php

namespace App\Enums;

/**
 * How a fitness event is measured. Times are stored in seconds and entered
 * as minutes:seconds. Values are also enforced by a CHECK constraint on
 * fitness_events.unit.
 */
enum FitnessUnit: string
{
    case Repetitions = 'repetitions';
    case Time = 'time';

    public function label(): string
    {
        return match ($this) {
            self::Repetitions => 'Repetitions',
            self::Time => 'Time (minutes:seconds)',
        };
    }

    /** Largest accepted value: 1,000 repetitions or 10 hours. */
    public function maximumValue(): int
    {
        return match ($this) {
            self::Repetitions => 1000,
            self::Time => 36000,
        };
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(fn (self $unit): array => ['value' => $unit->value, 'label' => $unit->label()], self::cases());
    }
}
