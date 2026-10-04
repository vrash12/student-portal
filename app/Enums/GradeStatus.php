<?php

namespace App\Enums;

/**
 * How complete a candidate's calculated subject grade is. This describes the
 * data behind the grade, not academic standing (Passing, Needs Improvement, Failing,
 * Incomplete; see AcademicStanding), which is decided from the passing and
 * warning grades of the academic period.
 */
enum GradeStatus: string
{
    /** The subject has no grading categories yet. */
    case NotConfigured = 'not_configured';

    /** No finalized assessment has a score for this candidate yet. */
    case NoGrades = 'no_grades';

    /** The candidate has no score on at least one finalized assessment. */
    case MissingScores = 'missing_scores';

    /** Some grading categories have no finalized assessments yet. */
    case Provisional = 'provisional';

    /** Every category is assessed and every finalized assessment is scored. */
    case Complete = 'complete';

    public function label(): string
    {
        return match ($this) {
            self::NotConfigured => 'Not Set Up',
            self::NoGrades => 'No Grades Yet',
            self::MissingScores => 'Missing Scores',
            self::Provisional => 'In Progress',
            self::Complete => 'Complete',
        };
    }

    /**
     * Badge tone used by the interface. Always shown together with the label.
     */
    public function tone(): string
    {
        return match ($this) {
            self::NotConfigured, self::NoGrades => 'neutral',
            self::MissingScores => 'warning',
            self::Provisional => 'info',
            self::Complete => 'success',
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
