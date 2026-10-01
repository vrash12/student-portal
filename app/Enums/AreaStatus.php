<?php

namespace App\Enums;

/**
 * A candidate's result in one performance area, decided only by
 * QualificationEngine from the area's passing grade.
 */
enum AreaStatus: string
{
    case Passed = 'passed';
    case Failed = 'failed';

    /** The grade meets the passing grade but records are still missing. */
    case Incomplete = 'incomplete';

    /** Nothing to judge yet (no grade, no test results, no attendance counted). */
    case NotYet = 'not_yet';

    public function label(): string
    {
        return match ($this) {
            self::Passed => 'Passed',
            self::Failed => 'Failed',
            self::Incomplete => 'Incomplete',
            self::NotYet => 'No results yet',
        };
    }

    /**
     * Badge tone used by the interface. Always shown together with the label.
     */
    public function tone(): string
    {
        return match ($this) {
            self::Passed => 'success',
            self::Failed => 'danger',
            self::Incomplete => 'warning',
            self::NotYet => 'neutral',
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
