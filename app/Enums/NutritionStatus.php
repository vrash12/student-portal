<?php

namespace App\Enums;

/**
 * Body mass index category of a nutrition assessment, by the cut-offs in
 * nutrition_standards (NutritionStandards::classify). Never stored: the
 * category follows the standards in force.
 */
enum NutritionStatus: string
{
    case Underweight = 'underweight';
    case Normal = 'normal';
    case Overweight = 'overweight';
    case Obese = 'obese';

    public function label(): string
    {
        return match ($this) {
            self::Underweight => 'Underweight',
            self::Normal => 'Normal',
            self::Overweight => 'Overweight',
            self::Obese => 'Obese',
        };
    }

    public function tone(): string
    {
        return match ($this) {
            self::Normal => 'success',
            self::Underweight, self::Overweight => 'warning',
            self::Obese => 'danger',
        };
    }

    /**
     * @return array{value: string, label: string, tone: string}
     */
    public function toArray(): array
    {
        return ['value' => $this->value, 'label' => $this->label(), 'tone' => $this->tone()];
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(fn (self $case): array => ['value' => $case->value, 'label' => $case->label()], self::cases());
    }
}
