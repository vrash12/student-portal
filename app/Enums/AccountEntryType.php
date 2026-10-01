<?php

namespace App\Enums;

/**
 * Side of a Statement of Account entry. Charges (billing, uniforms, meals)
 * increase what the candidate owes; credits (allowances, payments, bank
 * deposits) reduce it. Values are also enforced by CHECK constraints.
 */
enum AccountEntryType: string
{
    case Charge = 'charge';
    case Credit = 'credit';

    public function label(): string
    {
        return match ($this) {
            self::Charge => 'Charge',
            self::Credit => 'Credit',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Charge => 'Increases the balance owed (billing, uniforms, meals, chargeable items).',
            self::Credit => 'Reduces the balance owed (allowances, payments, bank deposits).',
        };
    }

    /**
     * @return list<array{value: string, label: string, description: string}>
     */
    public static function options(): array
    {
        return array_map(
            fn (self $type): array => ['value' => $type->value, 'label' => $type->label(), 'description' => $type->description()],
            self::cases(),
        );
    }
}
