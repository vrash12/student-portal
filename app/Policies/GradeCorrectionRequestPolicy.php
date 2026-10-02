<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\GradeCorrectionRequest;
use App\Models\User;

/**
 * Grade correction requests: instructors file them for the subjects they
 * teach (AssessmentPolicy::manage on the assessment) and see their own and
 * their subjects' requests; administrators with the approval permission see
 * every request and decide them, never their own. Whether a request is still
 * pending is checked by GradeCorrectionService.
 */
class GradeCorrectionRequestPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->hasPermission(Permission::RecordGrades) || $actor->hasPermission(Permission::ApproveGradeCorrections);
    }

    public function view(User $actor, GradeCorrectionRequest $request): bool
    {
        if ($actor->hasPermission(Permission::ApproveGradeCorrections)) {
            return true;
        }

        if (! $actor->hasPermission(Permission::RecordGrades)) {
            return false;
        }

        return (int) $request->requested_by === (int) $actor->getKey()
            || $actor->teachesOffering((int) $request->assessment()->value('class_subject_id'));
    }

    public function decide(User $actor, GradeCorrectionRequest $request): bool
    {
        return $actor->hasPermission(Permission::ApproveGradeCorrections)
            && (int) $request->requested_by !== (int) $actor->getKey();
    }

    public function cancel(User $actor, GradeCorrectionRequest $request): bool
    {
        return $actor->hasPermission(Permission::RecordGrades)
            && (int) $request->requested_by === (int) $actor->getKey();
    }
}
