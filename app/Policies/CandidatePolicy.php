<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Candidate;
use App\Models\User;

/**
 * Candidate records are sensitive. Viewing requires the "view all"
 * permission for now; Milestone 3 extends `view` so instructors can open
 * candidates in the classes they are assigned to teach.
 */
class CandidatePolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->hasPermission(Permission::ViewAllCandidates);
    }

    public function view(User $actor, Candidate $candidate): bool
    {
        return $actor->hasPermission(Permission::ViewAllCandidates);
    }

    public function create(User $actor): bool
    {
        return $actor->hasPermission(Permission::ManageCandidates);
    }

    public function update(User $actor, Candidate $candidate): bool
    {
        return $actor->hasPermission(Permission::ManageCandidates);
    }
}
