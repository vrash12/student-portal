<?php

namespace App\Enums;

/**
 * Why a score revision was written. Every change to a candidate's score or
 * comment is kept as a revision (AGENTS.md §36).
 *
 * Values are also enforced by a CHECK constraint on
 * assessment_score_revisions.kind.
 */
enum ScoreRevisionKind: string
{
    /** First value recorded for the candidate in this assessment. */
    case Recorded = 'recorded';

    /** Change made while the assessment was still a draft. */
    case Updated = 'updated';

    /** Change made after finalization; always carries a reason. */
    case Corrected = 'corrected';

    public function label(): string
    {
        return match ($this) {
            self::Recorded => 'Recorded',
            self::Updated => 'Changed',
            self::Corrected => 'Corrected',
        };
    }
}
