<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\User;

/**
 * Staff account management. Candidate accounts are managed through
 * candidate records (Milestone 2), not through this policy.
 */
class UserPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->hasPermission(Permission::ViewUsers);
    }

    public function create(User $actor): bool
    {
        return $actor->hasPermission(Permission::ManageUsers);
    }

    /**
     * An account may only be edited by someone who could grant its role,
     * so administrators cannot modify accounts more privileged than their own.
     */
    public function update(User $actor, User $target): bool
    {
        return $actor->hasPermission(Permission::ManageUsers)
            && $target->isStaffAccount()
            && $actor->canGrantRole($target->role);
    }
}
