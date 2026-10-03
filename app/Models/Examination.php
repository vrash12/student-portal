<?php

namespace App\Models;

use App\Enums\ExaminationKind;
use App\Enums\ExaminationStatus;
use App\Policies\ExaminationPolicy;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['kind', 'title', 'description', 'opens_at', 'closes_at', 'duration_minutes', 'passing_score', 'release_results', 'randomize_questions', 'randomize_choices', 'question_draw_count', 'one_question_at_a_time', 'allow_back_navigation', 'auto_submit', 'access_code'])]
#[Hidden(['access_code'])]
#[UsePolicy(ExaminationPolicy::class)]
class Examination extends Model
{
    /**
     * Attempts each candidate may take (owner request, 2026-10-03): one, with
     * no retakes; examinations are taken at the same time under the
     * instructor's supervision. An unfinished attempt is resumed, not retaken.
     */
    public const ATTEMPTS_ALLOWED = 1;

    public function lifecycle(): array
    {
        if ($this->status !== ExaminationStatus::Published) {
            return $this->status->toArray();
        }
        if ($this->closes_at && $this->closes_at->lte(now())) {
            return ['value' => 'ended', 'label' => 'Ended', 'tone' => 'neutral'];
        }
        if ($this->isActive()) {
            return ['value' => 'active', 'label' => 'Active', 'tone' => 'success'];
        }

        return $this->status->toArray();
    }

    protected function casts(): array
    {
        return ['kind' => ExaminationKind::class, 'status' => ExaminationStatus::class, 'opens_at' => 'datetime', 'closes_at' => 'datetime', 'duration_minutes' => 'integer', 'passing_score' => 'decimal:2', 'release_results' => 'boolean', 'randomize_questions' => 'boolean', 'randomize_choices' => 'boolean', 'question_draw_count' => 'integer', 'one_question_at_a_time' => 'boolean', 'allow_back_navigation' => 'boolean', 'auto_submit' => 'boolean'];
    }

    public function classSubject(): BelongsTo
    {
        return $this->belongsTo(ClassSubject::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function examinationQuestions(): HasMany
    {
        return $this->hasMany(ExaminationQuestion::class)->orderBy('position');
    }

    public function questions(): BelongsToMany
    {
        return $this->belongsToMany(Question::class, 'examination_questions')->withPivot(['position', 'points'])->orderByPivot('position');
    }

    public function attempts(): HasMany
    {
        return $this->hasMany(ExaminationAttempt::class);
    }

    /**
     * Questions each attempt receives: the drawn subset size, or every
     * question when no subset is configured (or it covers all of them).
     */
    public function questionsPerAttempt(?int $questionCount = null): int
    {
        $questionCount ??= $this->examinationQuestions()->count();

        return $this->question_draw_count === null ? $questionCount : min($this->question_draw_count, $questionCount);
    }

    /**
     * Whether attempts receive a random subset of the questions.
     */
    public function drawsSubset(?int $questionCount = null): bool
    {
        $questionCount ??= $this->examinationQuestions()->count();

        return $this->question_draw_count !== null && $this->question_draw_count < $questionCount;
    }

    /**
     * Maximum points of one attempt, as a decimal string. With a random
     * subset every question has the same points (checked at publication),
     * so every attempt has the same maximum.
     */
    public function attemptMaximumPoints(): string
    {
        $items = $this->examinationQuestions()->get(['id', 'examination_id', 'points']);
        $hundredths = $items->map(fn (ExaminationQuestion $item): int => (int) round(((float) $item->points) * 100));
        $total = $this->drawsSubset($items->count())
            ? (int) $hundredths->first() * $this->question_draw_count
            : (int) $hundredths->sum();

        return number_format($total / 100, 2, '.', '');
    }

    public function isActive(?\DateTimeInterface $at = null): bool
    {
        $at = $at ?? now();

        return $this->status === ExaminationStatus::Published && ($this->opens_at === null || $this->opens_at <= $at) && ($this->closes_at === null || $this->closes_at > $at);
    }
}
