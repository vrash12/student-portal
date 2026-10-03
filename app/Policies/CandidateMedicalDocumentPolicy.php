<?php

namespace App\Policies;

use App\Enums\MedicalDocumentStatus;
use App\Enums\Permission;
use App\Models\CandidateMedicalDocument;
use App\Models\User;

/**
 * Who may open, review and withdraw uploaded medical documents:
 *
 * - medical staff (medical.view) open and download every document of
 *   their campus (CampusScope), and with medical.manage accept or return
 *   those waiting for review;
 * - the candidate opens and downloads their own documents, and withdraws
 *   one only while it waits for review;
 * - an instructor of the candidate's class views documents (not returned
 *   ones) in the protected viewer only: no print, and a download only with
 *   an approved download request (owner decision 2026-10-02).
 */
class CandidateMedicalDocumentPolicy
{
    public function view(User $user, CandidateMedicalDocument $document): bool
    {
        return ($user->hasPermission(Permission::ViewMedical) && $user->campusScope()->allowsRecord($document))
            || $this->owns($user, $document);
    }

    public function review(User $user, CandidateMedicalDocument $document): bool
    {
        return $user->hasPermission(Permission::ManageMedical) && $user->hasPermission(Permission::ViewMedical)
            && $user->campusScope()->allowsRecord($document);
    }

    public function withdraw(User $user, CandidateMedicalDocument $document): bool
    {
        return $this->owns($user, $document) && $document->isWaiting();
    }

    /** The protected, view-only display for instructors of the candidate's class. */
    public function viewProtected(User $user, CandidateMedicalDocument $document): bool
    {
        return ! $user->hasPermission(Permission::ViewMedical)
            && $document->status !== MedicalDocumentStatus::Returned
            && $user->can('viewMedicalAsInstructor', $document->candidate);
    }

    /** Asking for a copy: whoever may view the document in the protected viewer. */
    public function requestDownload(User $user, CandidateMedicalDocument $document): bool
    {
        return $this->viewProtected($user, $document);
    }

    private function owns(User $user, CandidateMedicalDocument $document): bool
    {
        return $user->hasPermission(Permission::AccessExamPortal)
            && $user->candidate !== null
            && (int) $user->candidate->id === (int) $document->candidate_id;
    }
}
