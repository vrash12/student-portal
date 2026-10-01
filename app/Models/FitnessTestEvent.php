<?php

namespace App\Models;

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
            'passing_value' => 'decimal:2',
            'maximum_value' => 'decimal:2',
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
        return new FitnessStandard($this->unit, $this->higher_is_better, (float) $this->passing_value, (float) $this->maximum_value);
    }
}
