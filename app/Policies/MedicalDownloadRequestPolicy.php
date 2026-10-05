<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\MedicalDownloadRequest;
use App\Models\User;

/**
 * Deciding and withdrawing download requests is for medical staff of the
 * candidate's campus (never on their own request); cancelling a pending request, and downloading with an
 * active approval while still teaching the candidate's class, is for its
 * requester. Filing is CandidateMedicalDocumentPolicy::requestDownload.
 */
class MedicalDownloadRequestPolicy
{
    public function decide(User $actor, MedicalDownloadRequest $request): bool
    {
        return $this->isMedicalStaff($actor, $request) && (int) $request->requested_by !== (int) $actor->getKey();
    }

    public function revoke(User $actor, MedicalDownloadRequest $request): bool
    {
        return $this->isMedicalStaff($actor, $request);
    }

    public function cancel(User $actor, MedicalDownloadRequest $request): bool
    {
        return (int) $request->requested_by === (int) $actor->getKey();
    }

    /** While the requester still teaches the candidate's class, or is still a dietitian of the candidate's campus. */
    public function download(User $actor, MedicalDownloadRequest $request): bool
    {
        $candidate = $request->candidate;

        return (int) $request->requested_by === (int) $actor->getKey()
            && $request->isActive()
            && $candidate !== null
            && $actor->can('viewMedicalReadOnly', $candidate);
    }

    private function isMedicalStaff(User $actor, MedicalDownloadRequest $request): bool
    {
        return $actor->hasPermission(Permission::ManageMedical) && $actor->hasPermission(Permission::ViewMedical)
            && $actor->campusScope()->allowsRecord($request);
    }
}
