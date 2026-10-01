<?php

namespace App\Models;

use App\Enums\FitnessScoringMethod;
use App\Enums\FitnessUnit;
use App\Services\Fitness\FitnessStandard;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An event of a fitness test, with the event's standards as they were when
 * the test was created. Never edited afterwards.
 */
class FitnessTestEvent extends Model
{
    public $timestamps = false;

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
            'position' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<FitnessTest, $this>
     */
    public function test(): BelongsTo
    {
        return $this->belongsTo(FitnessTest::class, 'fitness_test_id');
    }

    /**
     * @return BelongsTo<FitnessEvent, $this>
     */
    public function event(): BelongsTo
    {
        return $this->belongsTo(FitnessEvent::class, 'fitness_event_id');
    }

    /**
     * @return HasMany<FitnessResult, $this>
     */
    public function results(): HasMany
    {
        return $this->hasMany(FitnessResult::class);
    }

    public function standard(): FitnessStandard
    {
        return FitnessStandard::fromColumns(
            $this->unit,
            $this->higher_is_better,
            $this->scoring_method,
            (float) $this->passing_points,
            $this->passing_value === null ? null : (float) $this->passing_value,
            $this->maximum_value === null ? null : (float) $this->maximum_value,
            $this->points_table,
        );
    }
}
