<?php

namespace App\Models;

use App\Enums\QuestionType;
use App\Policies\QuestionPolicy;
use Database\Factories\QuestionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A reusable question of one subject's question bank.
 *
 * Questions are never deleted; they are deactivated, which keeps them out of
 * new examinations. Once a question is part of a published examination it
 * is locked: its type, prompt, and choices (including the correct answer)
 * can no longer change. Create and change questions through the question
 * bank services only. See docs/question-bank-examination-contract.md.
 *
 * The explanation is staff-only and hidden from serialization; correct
 * answers are hidden on QuestionChoice. Candidate-facing payloads must be
 * built with QuestionPresenter::forCandidate().
 */
#[Fillable(['prompt', 'points', 'explanation'])]
#[Hidden(['explanation'])]
#[UsePolicy(QuestionPolicy::class)]
class Question extends Model
{
    /** @use HasFactory<QuestionFactory> */
    use HasFactory;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'question_topic_id' => null,
        'explanation' => null,
        'is_active' => true,
        'locked_at' => null,
        'updated_by' => null,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'subject_id' => 'integer',
            'question_topic_id' => 'integer',
            'type' => QuestionType::class,
            'points' => 'decimal:2',
            'is_active' => 'boolean',
            'locked_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Subject, $this>
     */
    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class);
    }

    /**
     * @return BelongsTo<QuestionTopic, $this>
     */
    public function topic(): BelongsTo
    {
        return $this->belongsTo(QuestionTopic::class, 'question_topic_id');
    }

    /**
     * Answer choices in display order. Empty for essays.
     *
     * @return HasMany<QuestionChoice, $this>
     */
    public function choices(): HasMany
    {
        return $this->hasMany(QuestionChoice::class)->orderBy('position');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /**
     * Whether the question has been part of a published examination, which
     * makes its type, prompt, and choices permanent.
     */
    public function isLocked(): bool
    {
        return $this->locked_at !== null;
    }

    /**
     * Questions that may be added to new examinations.
     *
     * @param  Builder<Question>  $query
     */
    #[Scope]
    protected function active(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /**
     * @param  Builder<Question>  $query
     */
    #[Scope]
    protected function forSubject(Builder $query, int $subjectId): void
    {
        $query->where('subject_id', $subjectId);
    }
}
