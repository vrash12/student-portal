<?php

namespace App\Models;

use App\Enums\CivilStatus;
use App\Enums\Sex;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A candidate's background record (owner request, 2026-10-03): personal
 * details, contact and emergency contact, eligibility, prior service and
 * previous occupation. Every value is optional. Change through
 * CandidateBackgroundService; who sees which part is decided in
 * CandidateBackgroundPresenter.
 */
#[Fillable([
    'date_of_birth', 'place_of_birth', 'sex', 'civil_status', 'home_address', 'mobile_number', 'personal_email',
    'emergency_contact_name', 'emergency_contact_relationship', 'emergency_contact_phone',
    'eligibility', 'prior_service', 'previous_occupation',
])]
class CandidateBackground extends Model
{
    /** Personal and contact fields: administrators and the candidate only, never instructors. */
    public const PERSONAL_FIELDS = [
        'date_of_birth', 'place_of_birth', 'sex', 'civil_status', 'home_address', 'mobile_number', 'personal_email',
        'emergency_contact_name', 'emergency_contact_relationship', 'emergency_contact_phone',
    ];

    /** Training-relevant fields, also shown to instructors of the candidate's class. */
    public const SERVICE_FIELDS = ['eligibility', 'prior_service', 'previous_occupation'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'date_of_birth' => 'immutable_date',
            'sex' => Sex::class,
            'civil_status' => CivilStatus::class,
        ];
    }

    /**
     * @return BelongsTo<Candidate, $this>
     */
    public function candidate(): BelongsTo
    {
        return $this->belongsTo(Candidate::class);
    }
}
