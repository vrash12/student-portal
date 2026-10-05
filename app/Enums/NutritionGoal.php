<?php

namespace App\Enums;

/**
 * The weight goal of a nutrition plan (also a CHECK constraint).
 */
enum NutritionGoal: string
{
    case Maintain = 'maintain';
    case Gain = 'gain';
    case Lose = 'lose';

    public function label(): string
    {
        return match ($this) {
            self::Maintain => 'Maintain weight',
            self::Gain => 'Gain weight',
            self::Lose => 'Lose weight',
        };
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(fn (self $case): array => ['value' => $case->value, 'label' => $case->label()], self::cases());
    }
}
