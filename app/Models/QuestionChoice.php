<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * One answer option of an objective question. Position 1 is shown as "A".
 *
 * Whether the choice is correct is confidential: it is hidden from
 * serialization and must never reach a candidate-facing payload
 * (docs/question-bank-examination-contract.md). correct_marker is a stored
 * generated column that limits each question to one correct choice.
 */
#[Fillable(['position', 'text', 'is_correct'])]
#[Hidden(['is_correct', 'correct_marker'])]
class QuestionChoice extends Model
{
    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'is_correct' => false,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'question_id' => 'integer',
            'position' => 'integer',
            'is_correct' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Question, $this>
     */
    public function question(): BelongsTo
    {
        return $this->belongsTo(Question::class);
    }

    /**
     * The choice's optional image (multiple-choice questions only).
     *
     * @return HasOne<QuestionMedia, $this>
     */
    public function image(): HasOne
    {
        return $this->hasOne(QuestionMedia::class);
    }

    /**
     * Display letter of the choice: A for position 1, B for position 2, ...
     */
    public function letter(): string
    {
        return chr(ord('A') + $this->position - 1);
    }
}
