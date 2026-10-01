<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A candidate's raw result in one event of a fitness test (repetitions, or
 * seconds for timed events). Points and pass/fail are calculated by
 * FitnessScoring, never stored. Changes are recorded in the audit log.
 */
class FitnessResult extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'value' => 'decimal:2',
        ];
    }

    /**
     * @return BelongsTo<FitnessTestEvent, $this>
     */
    public function testEvent(): BelongsTo
    {
        return $this->belongsTo(FitnessTestEvent::class, 'fitness_test_event_id');
    }

    /**
     * @return BelongsTo<Candidate, $this>
     */
    public function candidate(): BelongsTo
    {
        return $this->belongsTo(Candidate::class);
    }
}
