<?php

namespace App\Policies;

use App\Models\NutritionAssessment;
use App\Models\User;

/**
 * A recorded assessment is corrected or deleted by whoever may record
 * assessments for the candidate (CandidatePolicy::manageNutrition):
 * dietitians of the candidate's campus.
 */
class NutritionAssessmentPolicy
{
    public function manage(User $actor, NutritionAssessment $assessment): bool
    {
        return $assessment->candidate !== null && $actor->can('manageNutrition', $assessment->candidate);
    }
}
