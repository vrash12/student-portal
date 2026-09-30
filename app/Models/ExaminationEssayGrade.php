<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['score', 'comment'])]
class ExaminationEssayGrade extends Model
{
    protected function casts(): array
    {
        return ['score' => 'decimal:2', 'max_points' => 'decimal:2', 'graded_at' => 'datetime', 'version' => 'integer'];
    }

    public function attempt(): BelongsTo
    {
        return $this->belongsTo(ExaminationAttempt::class, 'examination_attempt_id');
    }

    public function examinationQuestion(): BelongsTo
    {
        return $this->belongsTo(ExaminationQuestion::class);
    }

    public function grader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'graded_by');
    }

    public function revisions(): HasMany
    {
        return $this->hasMany(ExaminationEssayRevision::class)->orderByDesc('id');
    }
}
