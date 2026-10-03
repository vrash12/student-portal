<?php

namespace App\Models;

use App\Enums\EducationLevel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One education entry of a candidate (owner request, 2026-10-03), e.g. a
 * bachelor's degree: level, degree or course, school, year graduated and
 * honors, in the order entered (position). Replaced as a whole by
 * CandidateBackgroundService.
 */
#[Fillable(['level', 'degree', 'school', 'year_graduated', 'honors', 'position'])]
class CandidateEducation extends Model
{
    protected $table = 'candidate_education';

    /** Entries one candidate may have. */
    public const MAX_ENTRIES = 6;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'level' => EducationLevel::class,
            'year_graduated' => 'integer',
            'position' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Candidate, $this>
     */
    public function candidate(): BelongsTo
    {
        return $this->belongsTo(Candidate::class);
    }
}
