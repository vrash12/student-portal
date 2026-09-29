<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Assessment;
use App\Models\User;

/**
 * Assessments inherit the access rules of their class subject: assigned
 * teaching staff may open them, and those who may also record grades may
 * change them. Draft/finalized rules are enforced by the grading services.
 */
class AssessmentPolicy
{
    public function view(User $actor, Assessment $assessment): bool
    {
        return $actor->teachesOffering($assessment->class_subject_id);
    }

    public function manage(User $actor, Assessment $assessment): bool
    {
        return $actor->hasPermission(Permission::RecordGrades) && $actor->teachesOffering($assessment->class_subject_id);
    }
}
