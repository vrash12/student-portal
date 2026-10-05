<?php

namespace App\Support;

use App\Models\Candidate;
use App\Models\CandidateDietaryProfile;
use App\Models\NutritionAssessment;
use App\Models\NutritionStandards;
use App\Models\User;

/**
 * Who sees what of a candidate's nutrition record (owner decisions
 * 2026-10-05):
 *
 * - dietitians and administrators of the candidate's campus (nutrition.view):
 *   everything ("staff");
 * - the candidate in the portal: their measurements, BMI category, plan and
 *   dietary profile, without the dietitian's diet history, findings and
 *   diagnosis ("candidate");
 * - instructors of the candidate's class: only the latest BMI category and
 *   what the candidate must not eat ("summary").
 *
 * What is not shown is never sent to the browser.
 */
final class NutritionPresenter
{
    /**
     * @return array<string, mixed>
     */
    public static function assessment(NutritionAssessment $assessment, NutritionStandards $standards, bool $forStaff): array
    {
        $bmi = $assessment->bmi();
        $waistToHeight = $assessment->waistToHeight();

        return [
            'id' => $assessment->id,
            'assessedOn' => $assessment->assessed_on->toDateString(),
            'heightCm' => (float) $assessment->height_cm,
            'weightKg' => (float) $assessment->weight_kg,
            'waistCm' => $assessment->waist_cm === null ? null : (float) $assessment->waist_cm,
            'bodyFatPercent' => $assessment->body_fat_percent === null ? null : (float) $assessment->body_fat_percent,
            'bmi' => $bmi,
            'status' => $standards->classify($bmi)->toArray(),
            'waistToHeight' => $waistToHeight,
            'waistAtRisk' => $waistToHeight !== null && $standards->waistAtRisk($waistToHeight),
            'activityLevel' => $assessment->activity_level === null ? null : ['value' => $assessment->activity_level->value, 'label' => $assessment->activity_level->label()],
            'mealsPerDay' => $assessment->meals_per_day,
            'goal' => $assessment->goal === null ? null : ['value' => $assessment->goal->value, 'label' => $assessment->goal->label()],
            'targetWeightKg' => $assessment->target_weight_kg === null ? null : (float) $assessment->target_weight_kg,
            'energyTargetKcal' => $assessment->energy_target_kcal,
            'plan' => $assessment->plan,
            'nextReviewOn' => $assessment->next_review_on?->toDateString(),
            // The dietitian's own notes: staff only.
            'assessedBy' => $forStaff ? $assessment->assessor?->name : null,
            'dietHistory' => $forStaff ? $assessment->diet_history : null,
            'clinicalFindings' => $forStaff ? $assessment->clinical_findings : null,
            'labFindings' => $forStaff ? $assessment->lab_findings : null,
            'diagnosis' => $forStaff ? $assessment->diagnosis : null,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function history(Candidate $candidate, NutritionStandards $standards, bool $forStaff): array
    {
        return $candidate->nutritionAssessments()
            ->newestFirst()
            ->when($forStaff, fn ($query) => $query->with('assessor:id,name'))
            ->get()
            ->map(fn (NutritionAssessment $assessment): array => self::assessment($assessment, $standards, $forStaff))
            ->all();
    }

    /**
     * @return array{foodAllergies: ?string, dietaryRestrictions: ?string, supplements: ?string, updatedAt: ?string, updatedBy: ?string}
     */
    public static function dietaryProfile(?CandidateDietaryProfile $profile, bool $forStaff): array
    {
        return [
            'foodAllergies' => $profile?->food_allergies,
            'dietaryRestrictions' => $profile?->dietary_restrictions,
            'supplements' => $profile?->supplements,
            'updatedAt' => $profile?->updated_at?->toIso8601String(),
            'updatedBy' => $forStaff ? $profile?->updater?->name : null,
        ];
    }

    /**
     * The nutrition panel of the staff candidate profile: the latest
     * assessment and a link for nutrition staff, the summary for instructors
     * of the class, or null for anyone else.
     *
     * @return array<string, mixed>|null
     */
    public static function forStaffProfile(Candidate $candidate, User $viewer): ?array
    {
        if ($viewer->can('viewNutrition', $candidate)) {
            $scope = 'full';
        } elseif ($viewer->can('viewNutritionSummary', $candidate)) {
            $scope = 'summary';
        } else {
            return null;
        }

        $standards = NutritionStandards::current();
        $latest = $candidate->nutritionAssessments()->newestFirst()->first();
        $profile = $candidate->dietaryProfile()->first();

        return [
            'scope' => $scope,
            'assessedOn' => $latest?->assessed_on->toDateString(),
            'status' => $latest === null ? null : $standards->classify($latest->bmi())->toArray(),
            'bmi' => $scope === 'full' ? $latest?->bmi() : null,
            'weightKg' => $scope === 'full' && $latest !== null ? (float) $latest->weight_kg : null,
            'nextReviewOn' => $scope === 'full' ? $latest?->next_review_on?->toDateString() : null,
            'foodAllergies' => $profile?->food_allergies,
            'dietaryRestrictions' => $profile?->dietary_restrictions,
            'recordUrl' => $scope === 'full' ? route('nutrition.show', $candidate, false) : null,
        ];
    }
}
