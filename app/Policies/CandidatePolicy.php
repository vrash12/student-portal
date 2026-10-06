<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Candidate;
use App\Models\User;

/**
 * Candidate records are sensitive. Administrators with "view all" may open
 * any record of their campus (every campus when not limited to one;
 * CampusScope); teaching staff may open only candidates in classes they are
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
            return $actor->campusScope()->allows($candidate->campusId());
        }

        return $candidate->class_batch_id !== null && $actor->teachesClass($candidate->class_batch_id);
    }

    /**
     * The candidate's ID card (owner request 2026-10-05: the administrator
     * side for now): staff who view every candidate of the campus.
     */
    public function viewIdCard(User $actor, Candidate $candidate): bool
    {
        return $actor->hasPermission(Permission::AccessStaffArea)
            && $actor->hasPermission(Permission::ViewAllCandidates)
            && $actor->campusScope()->allows($candidate->campusId());
    }

    /**
     * The Transcript of Records and Certificate of Completion (owner request
     * 2026-10-06): administrators who view every candidate of the campus and
     * see class ranks (both documents print the rank, which is staff only).
     */
    public function issueCompletionDocuments(User $actor, Candidate $candidate): bool
    {
        return $actor->hasPermission(Permission::AccessStaffArea)
            && $actor->hasPermission(Permission::ViewAllCandidates)
            && $actor->hasPermission(Permission::ViewPerformance)
            && $actor->campusScope()->allows($candidate->campusId());
    }

    /** The picture: whoever may open the candidate, and nutrition staff of the campus (to recognise the candidate). */
    public function viewPhoto(User $actor, Candidate $candidate): bool
    {
        return $this->view($actor, $candidate) || $this->viewNutrition($actor, $candidate);
    }

    public function create(User $actor): bool
    {
        return $actor->hasPermission(Permission::ManageCandidates);
    }

    /** Full-record exports are available to administrators and the record owner. */
    public function downloadRecord(User $actor, Candidate $candidate): bool
    {
        return ($actor->hasPermission(Permission::AccessStaffArea) && $actor->hasPermission(Permission::ViewAllCandidates)
                && $actor->campusScope()->allows($candidate->campusId()))
            || ($actor->hasPermission(Permission::AccessExamPortal) && (int) $candidate->user_id === (int) $actor->id);
    }

    public function update(User $actor, Candidate $candidate): bool
    {
        return $actor->hasPermission(Permission::ManageCandidates) && $actor->campusScope()->allows($candidate->campusId());
    }

    /**
     * Entering and changing the candidate's medical record. Instructors of the
     * candidate's class see it (view only) and never change it.
     */
    public function manageMedical(User $actor, Candidate $candidate): bool
    {
        return $actor->hasPermission(Permission::ManageMedical) && $actor->hasPermission(Permission::ViewMedical)
            && $actor->campusScope()->allows($candidate->campusId());
    }

    /**
     * Instructors of the candidate's class see the medical record and its
     * documents, view only (owner decision 2026-10-02: no request needed to
     * view; a download needs approval). Medical staff see it through medical.view.
     */
    public function viewMedicalAsInstructor(User $actor, Candidate $candidate): bool
    {
        return ! $actor->hasPermission(Permission::ViewMedical)
            && $candidate->class_batch_id !== null
            && $actor->canTeach()
            && $actor->teachesClass($candidate->class_batch_id);
    }

    /**
     * Dietitians of the candidate's campus see the medical record (lab
     * results, check-ups) on the same view-only terms as instructors of the
     * class (owner decision 2026-10-05).
     */
    public function viewMedicalAsDietitian(User $actor, Candidate $candidate): bool
    {
        return ! $actor->hasPermission(Permission::ViewMedical)
            && $actor->hasPermission(Permission::ManageNutrition)
            && $actor->campusScope()->allows($candidate->campusId());
    }

    /** The view-only medical record: instructors of the class and dietitians of the campus. */
    public function viewMedicalReadOnly(User $actor, Candidate $candidate): bool
    {
        return $this->viewMedicalAsInstructor($actor, $candidate) || $this->viewMedicalAsDietitian($actor, $candidate);
    }

    /** The whole nutrition record: dietitians and administrators of the candidate's campus. */
    public function viewNutrition(User $actor, Candidate $candidate): bool
    {
        return $actor->hasPermission(Permission::ViewNutrition) && $actor->campusScope()->allows($candidate->campusId());
    }

    /** Recording assessments and the dietary profile: dietitians of the candidate's campus. */
    public function manageNutrition(User $actor, Candidate $candidate): bool
    {
        return $actor->hasPermission(Permission::ManageNutrition) && $this->viewNutrition($actor, $candidate);
    }

    /**
     * Instructors of the class see only the BMI category and what the
     * candidate must not eat (owner decision 2026-10-05).
     */
    public function viewNutritionSummary(User $actor, Candidate $candidate): bool
    {
        return ! $actor->hasPermission(Permission::ViewNutrition)
            && $candidate->class_batch_id !== null
            && $actor->canTeach()
            && $actor->teachesClass($candidate->class_batch_id);
    }
}
