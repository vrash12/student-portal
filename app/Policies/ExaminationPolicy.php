<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Examination;
use App\Models\User;

final class ExaminationPolicy
{
    public function view(User $user, Examination $examination): bool
    {
        return $user->hasPermission(Permission::ManageExaminations) && $user->teachesOffering($examination->class_subject_id);
    }

    public function update(User $user, Examination $examination): bool
    {
        return $this->view($user, $examination);
    }
}
