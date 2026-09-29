<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Candidate;
use App\Models\User;

/**
 * Candidate records are sensitive. Administrators with "view all" may open
 * any record; teaching staff may open only candidates in classes they are
 * assigned to teach. Knowing a URL never grants access.
 */
class CandidatePolicy
{
    /**
     * Browsing the full candidate list is administrative. Instructors reach
     * their candidates through My Classes instead.
     */
    public function viewAny(User $actor): bool
    {
        return $actor->hasPermission(Permission::ViewAllCandidates);
    }

    public function view(User $actor, Candidate $candidate): bool
    {
        if ($actor->hasPermission(Permission::ViewAllCandidates)) {
            return true;
        }

        return $candidate->class_batch_id !== null && $actor->teachesClass($candidate->class_batch_id);
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
