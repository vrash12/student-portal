<?php

namespace App\Enums;

/**
 * A candidate's academic standing in a subject, or overall. Decided only by
 * GradeCalculationService from the calculated grade and the passing and
 * warning grades configured for the academic period.
 */
enum AcademicStanding: string
{
    /** At or above the warning grade, with no missing scores. */
    case Passing = 'passing';

    /** At or above the passing grade but below the warning grade. */
    case AtRisk = 'at_risk';

    /** Below the passing grade. */
    case Failing = 'failing';

    /**
     * Scores are missing on finalized assessments, and the scores recorded
     * so far do not show a concern (otherwise At Risk or Failing is shown).
     */
    case Incomplete = 'incomplete';

    public function label(): string
    {
        return match ($this) {
            self::Passing => 'Passing',
            self::AtRisk => 'At Risk',
            self::Failing => 'Failing',
            self::Incomplete => 'Incomplete',
        };
    }

    /**
     * Badge tone used by the interface. Always shown together with the label.
     */
    public function tone(): string
    {
        return match ($this) {
            self::Passing => 'success',
            self::AtRisk => 'warning',
            self::Failing => 'danger',
            self::Incomplete => 'neutral',
        };
    }

    /**
     * How much attention the standing needs; the overall standing is the
     * most serious subject standing.
     */
    public function severity(): int
    {
        return match ($this) {
            self::Passing => 0,
            self::Incomplete => 1,
            self::AtRisk => 2,
            self::Failing => 3,
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
