<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\MedicalAccessRequest;
use App\Models\User;

/**
 * Deciding and withdrawing instructors' access to full medical records is
 * for medical staff (never on their own request); cancelling a pending
 * request is for its requester. Filing is CandidatePolicy::requestMedicalAccess.
 */
class MedicalAccessRequestPolicy
{
    public function decide(User $actor, MedicalAccessRequest $request): bool
    {
        return $actor->hasPermission(Permission::ManageMedical)
            && $actor->hasPermission(Permission::ViewMedical)
            && (int) $request->requested_by !== (int) $actor->getKey();
    }

    public function revoke(User $actor, MedicalAccessRequest $request): bool
    {
        return $actor->hasPermission(Permission::ManageMedical) && $actor->hasPermission(Permission::ViewMedical);
    }

    public function cancel(User $actor, MedicalAccessRequest $request): bool
    {
        return (int) $request->requested_by === (int) $actor->getKey();
    }
}
