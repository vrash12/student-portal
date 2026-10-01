<?php

namespace App\Models;

use App\Enums\FitnessScoringMethod;
use App\Enums\FitnessUnit;
use App\Services\Fitness\FitnessStandard;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A configurable fitness event (e.g. push-ups, a timed run) with its
 * standard: a points table, or passing and maximum values (FitnessStandard),
 * and its passing points. Events that have been used in a test are
 * deactivated, never deleted. Change through FitnessStandardService.
 */
#[Fillable(['name', 'description', 'unit', 'higher_is_better', 'scoring_method', 'passing_points', 'passing_value', 'maximum_value', 'points_table', 'sort_order'])]
class FitnessEvent extends Model
{
    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'is_active' => true,
        'sort_order' => 0,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'unit' => FitnessUnit::class,
            'higher_is_better' => 'boolean',
            'scoring_method' => FitnessScoringMethod::class,
            'passing_points' => 'decimal:2',
            'passing_value' => 'decimal:2',
            'maximum_value' => 'decimal:2',
            'points_table' => 'array',
            'sort_order' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function standard(): FitnessStandard
    {
        return FitnessStandard::fromColumns(
            $this->unit,
            $this->higher_is_better,
            $this->scoring_method ?? FitnessScoringMethod::Scaled,
            (float) ($this->passing_points ?? FitnessStandard::DEFAULT_PASSING_POINTS),
            $this->passing_value === null ? null : (float) $this->passing_value,
            $this->maximum_value === null ? null : (float) $this->maximum_value,
            $this->points_table,
        );
    }

    /**
     * @return HasMany<FitnessTestEvent, $this>
     */
    public function testEvents(): HasMany
    {
        return $this->hasMany(FitnessTestEvent::class);
    }

    /**
     * @param  Builder<FitnessEvent>  $query
     */
    #[Scope]
    protected function active(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /**
     * @param  Builder<FitnessEvent>  $query
     */
    #[Scope]
    protected function ordered(Builder $query): void
    {
        $query->orderBy('sort_order')->orderBy('name');
    }
}
