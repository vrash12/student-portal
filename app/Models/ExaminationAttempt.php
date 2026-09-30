<?php

namespace App\Models;

use App\Policies\ExaminationAttemptPolicy;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[UsePolicy(ExaminationAttemptPolicy::class)]
#[Hidden(['scoring_key', 'delivery', 'answers', 'item_scores'])]
class ExaminationAttempt extends Model
{
    protected function casts(): array
    {
        return ['candidate_id' => 'integer', 'examination_id' => 'integer', 'started_at' => 'datetime', 'last_activity_at' => 'datetime', 'expires_at' => 'datetime', 'submitted_at' => 'datetime', 'delivery' => 'array', 'answers' => 'array', 'current_position' => 'integer', 'revision' => 'integer', 'scoring_key' => 'array', 'item_scores' => 'array', 'objective_points' => 'decimal:2', 'objective_max_points' => 'decimal:2', 'total_points' => 'decimal:2', 'earned_points' => 'decimal:2', 'percentage' => 'decimal:2', 'passing_score' => 'decimal:2', 'passed' => 'boolean', 'scored_at' => 'datetime'];
    }

    public function examination(): BelongsTo
    {
        return $this->belongsTo(Examination::class);
    }

    public function candidate(): BelongsTo
    {
        return $this->belongsTo(Candidate::class);
    }

    public function focusEvents(): HasMany
    {
        return $this->hasMany(ExaminationFocusEvent::class);
    }

    public function essayGrades(): HasMany
    {
        return $this->hasMany(ExaminationEssayGrade::class);
    }
}
