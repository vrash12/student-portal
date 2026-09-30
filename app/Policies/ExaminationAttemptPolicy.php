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
}
