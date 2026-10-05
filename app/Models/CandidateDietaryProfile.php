<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * What a candidate must not or does not eat, and what they take: food
 * allergies, dietary restrictions and supplements (one per candidate). The
 * allergies and restrictions are also shown to the instructors of the
 * candidate's class (owner decision 2026-10-05), so that field training
 * meals are safe. Change through NutritionService.
 */
#[Fillable(['food_allergies', 'dietary_restrictions', 'supplements'])]
class CandidateDietaryProfile extends Model
{
    public const FIELDS = ['food_allergies', 'dietary_restrictions', 'supplements'];

    /**
     * @return BelongsTo<Candidate, $this>
     */
    public function candidate(): BelongsTo
    {
        return $this->belongsTo(Candidate::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function isEmpty(): bool
    {
        return collect(self::FIELDS)->every(fn (string $field): bool => blank($this->getAttribute($field)));
    }
}
