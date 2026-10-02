<?php

namespace App\Models;

use App\Enums\CandidateStatus;
use App\Policies\CandidatePolicy;
use App\Support\QueryFilters;
use Database\Factories\CandidateFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Candidate record. The linked user account (Candidate role) signs in with
 * the candidate number as username. Create and update through
 * CandidateService so the account stays in sync.
 */
#[Fillable(['candidate_number', 'first_name', 'middle_name', 'last_name', 'suffix', 'training_group', 'company', 'platoon'])]
#[UsePolicy(CandidatePolicy::class)]
class Candidate extends Model
{
    /** @use HasFactory<CandidateFactory> */
    use HasFactory;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'enrolled',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => CandidateStatus::class,
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<ClassBatch, $this>
     */
    public function classBatch(): BelongsTo
    {
        return $this->belongsTo(ClassBatch::class);
    }

    /**
     * @return HasMany<AssessmentScore, $this>
     */
    public function assessmentScores(): HasMany
    {
        return $this->hasMany(AssessmentScore::class);
    }

    /**
     * Medical record values, one per field (MedicalRecordService).
     *
     * @return HasMany<CandidateMedicalValue, $this>
     */
    public function medicalValues(): HasMany
    {
        return $this->hasMany(CandidateMedicalValue::class);
    }

    /**
     * Medical documents the candidate uploaded.
     *
     * @return HasMany<CandidateMedicalDocument, $this>
     */
    public function medicalDocuments(): HasMany
    {
        return $this->hasMany(CandidateMedicalDocument::class);
    }

    /**
     * Whether scores may be recorded for this candidate in the class. Withdrawn
     * candidates keep the scores already recorded but receive no new ones.
     */
    public function isGradableIn(int $classBatchId): bool
    {
        return $this->class_batch_id !== null
            && (int) $this->class_batch_id === $classBatchId
            && $this->status !== CandidateStatus::Withdrawn;
    }

    /**
     * Candidates who can be graded in a class: assigned to it and not withdrawn.
     *
     * @param  Builder<Candidate>  $query
     */
    #[Scope]
    protected function gradableIn(Builder $query, int $classBatchId): void
    {
        $query->where('class_batch_id', $classBatchId)->where('status', '!=', CandidateStatus::Withdrawn->value);
    }

    /**
     * @return Attribute<string, never>
     */
    protected function fullName(): Attribute
    {
        return Attribute::get(fn (): string => implode(' ', array_filter(
            [$this->first_name, $this->middle_name, $this->last_name, $this->suffix],
            fn (?string $part): bool => $part !== null && $part !== '',
        )));
    }

    /**
     * Partial match on candidate number, first name, last name, or full name.
     * The term is matched literally (LIKE wildcards are escaped).
     *
     * @param  Builder<Candidate>  $query
     */
    #[Scope]
    protected function matching(Builder $query, string $term): void
    {
        $pattern = QueryFilters::likeTerm($term);

        $query->where(fn (Builder $match) => $match
            ->where('candidate_number', 'like', $pattern)
            ->orWhere('first_name', 'like', $pattern)
            ->orWhere('last_name', 'like', $pattern)
            ->orWhere('middle_name', 'like', $pattern)
            ->orWhereRaw("concat_ws(' ', `first_name`, `middle_name`, `last_name`, `suffix`) like ?", [$pattern])
            ->orWhereRaw("concat(`first_name`, ' ', `last_name`) like ?", [$pattern]));
    }
}
