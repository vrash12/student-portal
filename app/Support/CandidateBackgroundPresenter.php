<?php

namespace App\Support;

use App\Enums\CivilStatus;
use App\Enums\EducationLevel;
use App\Enums\Sex;
use App\Models\Candidate;
use App\Models\CandidateBackground;
use App\Models\CandidateEducation;

/**
 * The candidate's background record for a page (owner request, 2026-10-03).
 * "full": administrators (who view every candidate) and the candidate's own
 * portal; "service": instructors who teach the candidate's class, who get
 * the education and service background but no personal or contact details.
 */
final class CandidateBackgroundPresenter
{
    /** @return array<string, mixed> */
    public static function present(Candidate $candidate, string $scope): array
    {
        $background = $candidate->background()->first();
        $full = $scope === 'full';

        $value = fn (string $field): mixed => $background?->getRawOriginal($field);

        return [
            'scope' => $full ? 'full' : 'service',
            'personal' => ! $full ? null : [
                'dateOfBirth' => $value('date_of_birth'),
                'age' => $background?->date_of_birth?->age,
                'placeOfBirth' => $value('place_of_birth'),
                'sex' => $background?->sex?->label(),
                'civilStatus' => $background?->civil_status?->label(),
                'homeAddress' => $value('home_address'),
                'mobileNumber' => $value('mobile_number'),
                'personalEmail' => $value('personal_email'),
                'emergencyContact' => [
                    'name' => $value('emergency_contact_name'),
                    'relationship' => $value('emergency_contact_relationship'),
                    'phone' => $value('emergency_contact_phone'),
                ],
            ],
            'service' => [
                'eligibility' => $value('eligibility'),
                'priorService' => $value('prior_service'),
                'previousOccupation' => $value('previous_occupation'),
            ],
            'education' => $candidate->education()->get()->map(fn (CandidateEducation $entry): array => [
                'level' => $entry->level->value,
                'levelLabel' => $entry->level->label(),
                'degree' => $entry->degree,
                'school' => $entry->school,
                'yearGraduated' => $entry->year_graduated,
                'honors' => $entry->honors,
            ])->values()->all(),
        ];
    }

    /**
     * The form values of the Edit Background page.
     *
     * @return array<string, mixed>
     */
    public static function form(Candidate $candidate): array
    {
        $background = $candidate->background()->first();
        $values = [];
        foreach ([...CandidateBackground::PERSONAL_FIELDS, ...CandidateBackground::SERVICE_FIELDS] as $field) {
            $values[$field] = (string) ($background?->getRawOriginal($field) ?? '');
        }

        return [
            ...$values,
            'education' => $candidate->education()->get()->map(fn (CandidateEducation $entry): array => [
                'level' => $entry->level->value,
                'degree' => $entry->degree,
                'school' => $entry->school,
                'year_graduated' => $entry->year_graduated === null ? '' : (string) $entry->year_graduated,
                'honors' => $entry->honors ?? '',
            ])->values()->all(),
        ];
    }

    /** @return array<string, list<array{value: string, label: string}>> */
    public static function options(): array
    {
        return [
            'sex' => Sex::options(),
            'civilStatus' => CivilStatus::options(),
            'educationLevel' => EducationLevel::options(),
        ];
    }
}
