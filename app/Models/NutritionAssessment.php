<?php

namespace App\Models;

use App\Enums\ActivityLevel;
use App\Enums\NutritionGoal;
use App\Policies\NutritionAssessmentPolicy;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One dated nutrition assessment of a candidate by a dietitian, following
 * the Nutrition Care Process: measurements (height, weight, waist, body
 * fat), food and nutrition history, clinical and lab findings, the
 * nutrition diagnosis and the plan with its next review. The BMI and its
 * category are computed (NutritionStandards). Change through
 * NutritionService; who sees what is decided in NutritionPresenter.
 *
 * @property CarbonImmutable $assessed_on
 * @property string $height_cm
 * @property string $weight_kg
 * @property string|null $waist_cm
 */
#[Fillable([
    'assessed_on', 'height_cm', 'weight_kg', 'waist_cm', 'body_fat_percent',
    'activity_level', 'meals_per_day', 'diet_history', 'clinical_findings', 'lab_findings',
    'diagnosis', 'goal', 'target_weight_kg', 'energy_target_kcal', 'plan', 'next_review_on',
])]
#[UsePolicy(NutritionAssessmentPolicy::class)]
class NutritionAssessment extends Model
{
    /** Written by the dietitian for staff only; never sent to the candidate or instructors. */
    public const STAFF_ONLY_FIELDS = ['diet_history', 'clinical_findings', 'lab_findings', 'diagnosis'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'assessed_on' => 'immutable_date',
            'next_review_on' => 'immutable_date',
            'height_cm' => 'decimal:1',
            'weight_kg' => 'decimal:1',
            'waist_cm' => 'decimal:1',
            'body_fat_percent' => 'decimal:1',
            'target_weight_kg' => 'decimal:1',
            'meals_per_day' => 'integer',
            'energy_target_kcal' => 'integer',
            'activity_level' => ActivityLevel::class,
            'goal' => NutritionGoal::class,
        ];
    }

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
    public function assessor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assessed_by');
    }

    /** Newest first: by date, then by entry. */
    #[Scope]
    protected function newestFirst(Builder $query): void
    {
        $query->orderByDesc('assessed_on')->orderByDesc('id');
    }

    public function bmi(): float
    {
        return NutritionStandards::bmi((float) $this->height_cm, (float) $this->weight_kg);
    }

    public function waistToHeight(): ?float
    {
        return $this->waist_cm === null ? null : NutritionStandards::waistToHeight((float) $this->waist_cm, (float) $this->height_cm);
    }
}
