<?php

namespace App\Enums;

/**
 * Whether a candidate meets every must-pass performance area, decided only
 * by QualificationEngine: any failed must-pass area makes the candidate Not
 * Qualified; otherwise any must-pass area still incomplete or without
 * results keeps the decision Pending.
 */
enum QualificationStatus: string
{
    case Qualified = 'qualified';
    case NotQualified = 'not_qualified';
    case Pending = 'pending';

    public function label(): string
    {
        return match ($this) {
            self::Qualified => 'Qualified',
            self::NotQualified => 'Not Qualified',
            self::Pending => 'Pending',
        };
    }

    /**
     * Badge tone used by the interface. Always shown together with the label.
     */
    public function tone(): string
    {
        return match ($this) {
            self::Qualified => 'success',
            self::NotQualified => 'danger',
            self::Pending => 'warning',
        };
    }

    /**
     * @return array{value: string, label: string, tone: string}
     */
    public function toArray(): array
    {
        return ['value' => $this->value, 'label' => $this->label(), 'tone' => $this->tone()];
    }
}
