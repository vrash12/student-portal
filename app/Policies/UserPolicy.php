<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\User;

/**
 * Staff account management. Candidate accounts are managed through
 * candidate records instead.
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
     * Administrators may edit their own profile, and accounts whose role is
     * ranked below their own. They can never modify peers or superiors.
     */
    public function update(User $actor, User $target): bool
    {
        if (! $actor->hasPermission(Permission::ManageUsers) || ! $target->isStaffAccount()) {
            return false;
        }

        return $actor->is($target) || $actor->canAssignRole($target->role);
    }
}
