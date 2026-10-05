<?php

namespace App\Enums;

/**
 * The candidate's physical activity at the time of a nutrition assessment
 * (also a CHECK constraint). Training days are usually heavy.
 */
enum ActivityLevel: string
{
    case Light = 'light';
    case Moderate = 'moderate';
    case Heavy = 'heavy';

    public function label(): string
    {
        return match ($this) {
            self::Light => 'Light',
            self::Moderate => 'Moderate',
            self::Heavy => 'Heavy (field training)',
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
