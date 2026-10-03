<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A phase of the training course (Phase 1, Phase 2, … or the phases OCS
 * uses). Each subject of a class belongs to at most one phase; phase
 * averages and the CGPA come from GradeCalculationService. The number gives
 * the order. A phase in use is never deleted. Change through
 * TrainingPhaseService.
 */
#[Fillable(['number', 'name'])]
class TrainingPhase extends Model
{
    /** Highest phase number (also a CHECK constraint). */
    public const MAX_NUMBER = 20;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['number' => 'integer'];
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
     * @return array{id: int, number: int, name: string}
     */
    public function toSummary(): array
    {
        return ['id' => $this->id, 'number' => $this->number, 'name' => $this->name];
    }
}
