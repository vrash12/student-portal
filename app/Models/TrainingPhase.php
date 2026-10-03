<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A phase of the training course (Phase 1, Phase 2, … or the phases OCS
 * uses) within one academic year (owner request, 2026-10-03: the whole
 * course lasts one year). Its dates lie inside the year and follow the
 * phase numbers without overlapping. Each subject of a class of that year
 * belongs to at most one of its phases; phase averages and the CGPA come
 * from GradeCalculationService. A phase in use is never deleted. Change
 * through TrainingPhaseService.
 */
#[Fillable(['number', 'name', 'starts_on', 'ends_on'])]
class TrainingPhase extends Model
{
    /** Highest phase number (also a CHECK constraint). */
    public const MAX_NUMBER = 20;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'number' => 'integer',
            'starts_on' => 'immutable_date',
            'ends_on' => 'immutable_date',
        ];
    }

    /**
     * @return BelongsTo<AcademicPeriod, $this>
     */
    public function academicPeriod(): BelongsTo
    {
        return $this->belongsTo(AcademicPeriod::class);
    }

    /**
     * @return HasMany<ClassSubject, $this>
     */
    public function classSubjects(): HasMany
    {
        return $this->hasMany(ClassSubject::class);
    }

    /**
     * @param  Builder<TrainingPhase>  $query
     */
    #[Scope]
    protected function ordered(Builder $query): void
    {
        $query->orderBy('number');
    }

    /**
     * @return array{id: int, number: int, name: string, startsOn: string, endsOn: string}
     */
    public function toSummary(): array
    {
        return [
            'id' => $this->id,
            'number' => $this->number,
            'name' => $this->name,
            'startsOn' => $this->starts_on->toDateString(),
            'endsOn' => $this->ends_on->toDateString(),
        ];
    }
}
