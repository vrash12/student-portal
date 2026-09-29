<?php

namespace App\Enums;

/**
 * Lifecycle of a recorded assessment (quiz, examination, practical, ...).
 *
 * Draft scores can be edited freely and do not count toward grades.
 * Finalizing an assessment makes its scores count; after that a score can
 * only change through a correction that records a reason. Finalization is
 * not reversible.
 *
 * Values are also enforced by a CHECK constraint on assessments.status.
 */
enum AssessmentStatus: string
{
    case Draft = 'draft';
    case Finalized = 'finalized';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Finalized => 'Finalized',
        };
    }

    /**
     * Badge tone used by the interface. Always shown together with the label.
     */
    public function tone(): string
    {
        return match ($this) {
            self::Draft => 'neutral',
            self::Finalized => 'success',
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
