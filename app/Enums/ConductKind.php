<?php

namespace App\Enums;

/**
 * Side of a merit/demerit entry. Merits reward conduct and demerits record
 * an offence; the conduct totals count the points of each side over entries
 * that are not voided. Values are also enforced by CHECK constraints.
 */
enum ConductKind: string
{
    case Merit = 'merit';
    case Demerit = 'demerit';

    public function label(): string
    {
        return match ($this) {
            self::Merit => 'Merit',
            self::Demerit => 'Demerit',
        };
    }

    /** "Merits" / "Demerits", for headings and option groups. */
    public function pluralLabel(): string
    {
        return match ($this) {
            self::Merit => 'Merits',
            self::Demerit => 'Demerits',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Merit => 'Recognizes good conduct or performance.',
            self::Demerit => 'Records an offence against regulations or standards.',
        };
    }

    /**
     * Badge tone used by the interface. Always shown together with the label.
     */
    public function tone(): string
    {
        return match ($this) {
            self::Merit => 'success',
            self::Demerit => 'warning',
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
     * @return list<array{value: string, label: string, description: string}>
     */
    public static function options(): array
    {
        return array_map(
            fn (self $kind): array => ['value' => $kind->value, 'label' => $kind->label(), 'description' => $kind->description()],
            self::cases(),
        );
    }
}
