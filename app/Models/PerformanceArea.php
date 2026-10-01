<?php

namespace App\Models;

use App\Enums\PerformanceSource;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A configurable performance area (e.g. "Academic", "Physical Fitness")
 * with its weight in the overall score, passing grade and must-pass flag.
 * Results are calculated by QualificationEngine and never stored. Change
 * through PerformanceAreaService, which also maps subjects to subject areas
 * and keeps at most one active fitness, conduct and attendance area.
 */
#[Fillable(['name', 'description', 'source', 'weight', 'passing_grade', 'must_pass', 'base_rating', 'merit_value', 'demerit_value', 'sort_order'])]
class PerformanceArea extends Model
{
    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'is_active' => true,
        'must_pass' => true,
        'sort_order' => 0,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'source' => PerformanceSource::class,
            'weight' => 'decimal:2',
            'passing_grade' => 'decimal:2',
            'must_pass' => 'boolean',
            'base_rating' => 'decimal:2',
            'merit_value' => 'decimal:2',
            'demerit_value' => 'decimal:2',
            'sort_order' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /**
     * Subjects whose grades count toward the area (subject areas only).
     *
     * @return HasMany<Subject, $this>
     */
    public function subjects(): HasMany
    {
        return $this->hasMany(Subject::class);
    }

    /**
     * @param  Builder<PerformanceArea>  $query
     */
    #[Scope]
    protected function active(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /**
     * Display and evaluation order: position, then name.
     *
     * @param  Builder<PerformanceArea>  $query
     */
    #[Scope]
    protected function ordered(Builder $query): void
    {
        $query->orderBy('sort_order')->orderBy('name')->orderBy('id');
    }
}
