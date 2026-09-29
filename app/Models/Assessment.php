<?php

namespace App\Models;

use App\Enums\AssessmentStatus;
use App\Policies\AssessmentPolicy;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A graded activity of one class subject: a quiz, examination, practical
 * exercise, or other requirement. Only finalized assessments count toward
 * grades. Create and change through App\Services\Grading\AssessmentService.
 */
#[Fillable(['title', 'max_score', 'assessed_on'])]
#[UsePolicy(AssessmentPolicy::class)]
class Assessment extends Model
{
    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'draft',
        'finalized_at' => null,
        'finalized_by' => null,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'max_score' => 'decimal:2',
            'assessed_on' => 'immutable_date',
            'status' => AssessmentStatus::class,
            'finalized_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<ClassSubject, $this>
     */
    public function classSubject(): BelongsTo
    {
        return $this->belongsTo(ClassSubject::class);
    }

    /**
     * @return BelongsTo<AssessmentCategory, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(AssessmentCategory::class, 'assessment_category_id');
    }

    /**
     * @return HasMany<AssessmentScore, $this>
     */
    public function scores(): HasMany
    {
        return $this->hasMany(AssessmentScore::class);
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
    public function finalizer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'finalized_by');
    }

    public function isDraft(): bool
    {
        return $this->status === AssessmentStatus::Draft;
    }

    public function isFinalized(): bool
    {
        return $this->status === AssessmentStatus::Finalized;
    }

    /**
     * @param  Builder<Assessment>  $query
     */
    #[Scope]
    protected function finalized(Builder $query): void
    {
        $query->where('status', AssessmentStatus::Finalized->value);
    }
}
