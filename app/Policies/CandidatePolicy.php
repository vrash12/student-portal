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

    /** Full-record exports are available to administrators and the record owner. */
    public function downloadRecord(User $actor, Candidate $candidate): bool
    {
        return ($actor->hasPermission(Permission::AccessStaffArea) && $actor->hasPermission(Permission::ViewAllCandidates))
            || ($actor->hasPermission(Permission::AccessExamPortal) && (int) $candidate->user_id === (int) $actor->id);
    }

    public function update(User $actor, Candidate $candidate): bool
    {
        return $actor->hasPermission(Permission::ManageCandidates);
    }

    /**
     * Entering and changing the candidate's medical record. Instructors see
     * it only through an approved access request (read-only) and never
     * change it.
     */
    public function manageMedical(User $actor, Candidate $candidate): bool
    {
        return $actor->hasPermission(Permission::ManageMedical) && $actor->hasPermission(Permission::ViewMedical);
    }

    /**
     * Instructors of the candidate's class may ask the medical staff to see
     * the full medical record (MedicalAccessService). Medical staff already see it.
     */
    public function requestMedicalAccess(User $actor, Candidate $candidate): bool
    {
        return ! $actor->hasPermission(Permission::ViewMedical)
            && $candidate->class_batch_id !== null
            && $actor->canTeach()
            && $actor->teachesClass($candidate->class_batch_id);
    }
}
