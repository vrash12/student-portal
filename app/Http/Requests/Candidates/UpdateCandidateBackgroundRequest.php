<?php

namespace App\Http\Requests\Candidates;

use App\Enums\CivilStatus;
use App\Enums\EducationLevel;
use App\Enums\Sex;
use App\Http\Requests\Concerns\NormalizesTextInput;
use App\Models\Candidate;
use App\Models\CandidateEducation;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * The candidate's background record (owner request, 2026-10-03). Every
 * field is optional; an education entry needs its level, degree and school.
 * Only users who may update the candidate (candidates.manage) save it.
 */
class UpdateCandidateBackgroundRequest extends FormRequest
{
    use NormalizesTextInput;

    /** Text fields, trimmed and squished; empty becomes null. */
    private const TEXT_FIELDS = [
        'place_of_birth', 'home_address', 'mobile_number', 'personal_email', 'emergency_contact_name',
        'emergency_contact_relationship', 'emergency_contact_phone', 'eligibility', 'prior_service', 'previous_occupation',
    ];

    public function authorize(): bool
    {
        $candidate = $this->route('candidate');

        return $candidate instanceof Candidate && $this->user()->can('update', $candidate);
    }

    protected function prepareForValidation(): void
    {
        $clean = fn (mixed $value): mixed => is_string($value) ? (Str::squish($value) === '' ? null : Str::squish($value)) : $value;

        $merged = [];
        foreach ([...self::TEXT_FIELDS, 'date_of_birth', 'sex', 'civil_status'] as $field) {
            $merged[$field] = $clean($this->input($field));
        }
        $education = $this->input('education', []);
        $merged['education'] = is_array($education)
            ? array_values(array_map(fn (mixed $entry): mixed => is_array($entry) ? array_map($clean, $entry) : $entry, $education))
            : $education;

        $this->merge($merged);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $phone = ['nullable', 'string', 'max:30', 'regex:/^[0-9+()\-\s]{7,30}$/'];

        return [
            'date_of_birth' => ['nullable', 'date_format:Y-m-d', 'before:today', 'after:1940-01-01'],
            'place_of_birth' => ['nullable', 'string', 'max:150'],
            'sex' => ['nullable', Rule::enum(Sex::class)],
            'civil_status' => ['nullable', Rule::enum(CivilStatus::class)],
            'home_address' => ['nullable', 'string', 'max:255'],
            'mobile_number' => $phone,
            'personal_email' => ['nullable', 'string', 'email', 'max:150'],
            'emergency_contact_name' => ['nullable', 'string', 'max:150'],
            'emergency_contact_relationship' => ['nullable', 'string', 'max:50'],
            'emergency_contact_phone' => $phone,
            'eligibility' => ['nullable', 'string', 'max:255'],
            'prior_service' => ['nullable', 'string', 'max:255'],
            'previous_occupation' => ['nullable', 'string', 'max:150'],
            'education' => ['present', 'array', 'max:'.CandidateEducation::MAX_ENTRIES],
            'education.*' => ['array'],
            'education.*.level' => ['required', Rule::enum(EducationLevel::class)],
            'education.*.degree' => ['required', 'string', 'max:150'],
            'education.*.school' => ['required', 'string', 'max:150'],
            'education.*.year_graduated' => ['nullable', 'integer', 'min:1950', 'max:'.((int) now()->year + 1)],
            'education.*.honors' => ['nullable', 'string', 'max:100'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'date_of_birth.before' => 'The date of birth must be in the past.',
            'mobile_number.regex' => 'Enter a phone number using digits, spaces, +, - or parentheses.',
            'emergency_contact_phone.regex' => 'Enter a phone number using digits, spaces, +, - or parentheses.',
            'education.max' => 'Record at most '.CandidateEducation::MAX_ENTRIES.' education entries.',
            'education.*.level.required' => 'Choose the level.',
            'education.*.degree.required' => 'Enter the degree or course, for example "BS Criminology".',
            'education.*.school.required' => 'Enter the school.',
            'education.*.year_graduated.integer' => 'Enter the year as four digits, for example 2024.',
            'education.*.year_graduated.min' => 'Enter a year from 1950.',
            'education.*.year_graduated.max' => 'The year cannot be in the future.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'personal_email' => 'personal email',
            'emergency_contact_name' => 'emergency contact name',
            'emergency_contact_relationship' => 'relationship',
            'emergency_contact_phone' => 'emergency contact phone',
        ];
    }

    /** @return array<string, mixed> */
    public function background(): array
    {
        $validated = $this->validated();

        return array_intersect_key($validated, array_flip([...self::TEXT_FIELDS, 'date_of_birth', 'sex', 'civil_status']));
    }

    /** @return list<array{level: string, degree: string, school: string, year_graduated: ?int, honors: ?string}> */
    public function education(): array
    {
        return array_map(fn (array $entry): array => [
            'level' => (string) $entry['level'],
            'degree' => (string) $entry['degree'],
            'school' => (string) $entry['school'],
            'year_graduated' => isset($entry['year_graduated']) ? (int) $entry['year_graduated'] : null,
            'honors' => $entry['honors'] ?? null,
        ], array_values($this->validated('education')));
    }
}
