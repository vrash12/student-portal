<?php

namespace App\Policies;

use App\Enums\MedicalDocumentStatus;
use App\Enums\Permission;
use App\Models\CandidateMedicalDocument;
use App\Models\User;
use App\Services\Medical\MedicalAccessService;

/**
 * Who may open, review and withdraw uploaded medical documents:
 *
 * - medical staff (medical.view) open and download every document, and
 *   with medical.manage accept or return those waiting for review;
 * - the candidate opens and downloads their own documents, and withdraws
 *   one only while it waits for review;
 * - an instructor of the candidate's class, while the medical staff have
 *   approved their full-record request, views documents (not returned ones)
 *   in the protected viewer only: no download, no print.
 */
class CandidateMedicalDocumentPolicy
{
    public function view(User $user, CandidateMedicalDocument $document): bool
    {
        return $user->hasPermission(Permission::ViewMedical) || $this->owns($user, $document);
    }

    public function review(User $user, CandidateMedicalDocument $document): bool
    {
        return $user->hasPermission(Permission::ManageMedical) && $user->hasPermission(Permission::ViewMedical);
    }

    public function withdraw(User $user, CandidateMedicalDocument $document): bool
    {
        return $this->owns($user, $document) && $document->isWaiting();
    }

    /** The protected, view-only display for instructors with approved access. */
    public function viewProtected(User $user, CandidateMedicalDocument $document): bool
    {
        $candidate = $document->candidate;

        return ! $user->hasPermission(Permission::ViewMedical)
            && $document->status !== MedicalDocumentStatus::Returned
            && $candidate->class_batch_id !== null
            && $user->canTeach()
            && $user->teachesClass($candidate->class_batch_id)
            && MedicalAccessService::activeGrant($user, $candidate) !== null;
    }

    private function owns(User $user, CandidateMedicalDocument $document): bool
    {
        return $user->hasPermission(Permission::AccessExamPortal)
            && $user->candidate !== null
            && (int) $user->candidate->id === (int) $document->candidate_id;
    }
}
