<?php

namespace App\Http\Requests\Medical;

use App\Services\Medical\MedicalAccessService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Filing, approving and rejecting instructors' requests to see a full
 * medical record; the route decides which fields apply. Authorization is on
 * the routes (CandidatePolicy::requestMedicalAccess, MedicalAccessRequestPolicy).
 */
class MedicalAccessRequestForm extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return match (true) {
            $this->routeIs('medical.access.store') => [
                'reason' => ['required', 'string', 'min:'.MedicalAccessService::REASON_MIN, 'max:'.MedicalAccessService::REASON_MAX],
            ],
            $this->routeIs('medical.access.approve') => [
                'days' => ['required', 'integer', Rule::in(MedicalAccessService::DURATIONS)],
                'note' => ['nullable', 'string', 'max:1000'],
            ],
            default => [
                'note' => ['required', 'string', 'max:1000'],
            ],
        };
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'reason.required' => 'Explain why you need to see the full medical record.',
            'reason.min' => 'Explain why in at least '.MedicalAccessService::REASON_MIN.' characters.',
            'reason.max' => 'Use at most '.MedicalAccessService::REASON_MAX.' characters.',
            'days.required' => 'Choose how long the instructor may see the record.',
            'days.in' => 'Choose 1, 7 or 30 days.',
            'note.required' => 'Explain why the request is rejected. The instructor will see this.',
            'note.max' => 'Use at most 1000 characters.',
        ];
    }

    public function note(): ?string
    {
        $note = $this->validated('note');

        return $note === null ? null : (string) $note;
    }
}
