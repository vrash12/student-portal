<?php

namespace App\Support;

use App\Models\Candidate;

final class CandidatePresenter
{
    /** Explicit whitelist shared by authorized staff and the candidate's own profile. */
    public static function details(Candidate $candidate, bool $portal = false): array
    {
        $candidate->loadMissing('classBatch.academicPeriod');

        return [
            'id' => $candidate->id,
            'candidateNumber' => $candidate->candidate_number,
            'firstName' => $candidate->first_name,
            'middleName' => $candidate->middle_name,
            'lastName' => $candidate->last_name,
            'suffix' => $candidate->suffix,
            'trainingGroup' => $candidate->training_group,
            'company' => $candidate->company,
            'platoon' => $candidate->platoon,
            'photoUrl' => $candidate->profile_photo_path === null ? null : ($portal
                ? route('portal.profile.photo') : route('candidates.photo', $candidate)),
            // The candidate's QR code for attendance (owner request, 2026-10-02).
            'qrCodeUrl' => $portal ? route('portal.profile.qr') : route('candidates.qr', $candidate),
            'name' => $candidate->full_name,
            'status' => ['value' => $candidate->status->value, 'label' => $candidate->status->label(), 'tone' => $candidate->status->tone()],
            'classBatch' => $candidate->classBatch === null ? null : [
                'id' => $candidate->classBatch->id,
                'name' => $candidate->classBatch->name,
                'period' => $candidate->classBatch->academicPeriod->name,
                'periodId' => $candidate->classBatch->academic_period_id,
            ],
            'createdAt' => $candidate->created_at?->toIso8601String(),
            'updatedAt' => $candidate->updated_at?->toIso8601String(),
        ];
    }

    public static function account(Candidate $candidate): array
    {
        $candidate->loadMissing('user');

        return [
            'username' => $candidate->user->username,
            'isActive' => $candidate->user->is_active,
            'lastLoginAt' => $candidate->user->last_login_at?->toIso8601String(),
        ];
    }
}
