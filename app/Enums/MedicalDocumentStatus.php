<?php

namespace App\Enums;

/**
 * Review state of an uploaded medical document. Values are also enforced by
 * a CHECK constraint.
 */
enum MedicalDocumentStatus: string
{
    case Submitted = 'submitted';
    case Accepted = 'accepted';
    case Returned = 'returned';

    public function label(): string
    {
        return match ($this) {
            self::Submitted => 'Waiting for Review',
            self::Accepted => 'Accepted',
            self::Returned => 'Returned',
        };
    }

    public function tone(): string
    {
        return match ($this) {
            self::Submitted => 'warning',
            self::Accepted => 'success',
            self::Returned => 'danger',
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
