<?php

namespace App\Enums;

/**
 * Level of an education entry on the candidate's background record (also a CHECK constraint), highest last.
 */
enum EducationLevel: string
{
    case Vocational = 'vocational';
    case Bachelor = 'bachelor';
    case Master = 'master';
    case Doctorate = 'doctorate';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Vocational => 'Vocational / Technical',
            self::Bachelor => "Bachelor's Degree",
            self::Master => "Master's Degree",
            self::Doctorate => 'Doctorate',
            self::Other => 'Other',
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
