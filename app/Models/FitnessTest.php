<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

/**
 * One fitness test of a class on a date, with the event standards copied
 * from the configured events when it was created. Change through
 * FitnessTestService.
 */
#[Fillable(['title', 'tested_on', 'notes'])]
class FitnessTest extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'tested_on' => 'date',
        ];
    }

    /**
     * @return BelongsTo<ClassBatch, $this>
     */
    public function classBatch(): BelongsTo
    {
        return $this->belongsTo(ClassBatch::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return HasMany<FitnessTestEvent, $this>
     */
    public function events(): HasMany
    {
        return $this->hasMany(FitnessTestEvent::class)->orderBy('position');
    }

    /**
     * @return HasManyThrough<FitnessResult, FitnessTestEvent, $this>
     */
    public function results(): HasManyThrough
    {
        return $this->hasManyThrough(FitnessResult::class, FitnessTestEvent::class);
    }
}
