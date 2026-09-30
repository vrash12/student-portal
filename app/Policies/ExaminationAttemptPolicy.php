<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\ExaminationAttempt;
use App\Models\User;

class ExaminationAttemptPolicy
{
    public function view(User $user, ExaminationAttempt $attempt): bool
    {
        return $user->hasPermission(Permission::AccessExamPortal) && $user->candidate !== null && $attempt->candidate_id === $user->candidate->id;
    }

    public function grade(User $user, ExaminationAttempt $attempt): bool
    {
        return $user->hasPermission(Permission::ManageExaminations)
            && $user->hasPermission(Permission::RecordGrades)
            && $user->teachesOffering((int) $attempt->examination->class_subject_id);
    }
}
