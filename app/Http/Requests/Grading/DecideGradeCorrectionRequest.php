<?php

namespace App\Http\Requests\Grading;

use App\Services\Grading\GradeCorrectionService;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Approval or rejection of a grade correction request. The note is optional
 * when approving and required when rejecting (the route decides which).
 * Authorization: GradeCorrectionRequestPolicy::decide on the route.
 */
class DecideGradeCorrectionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        $rejecting = $this->routeIs('grade-corrections.reject');

        return [
            'note' => [$rejecting ? 'required' : 'nullable', 'string', 'max:'.GradeCorrectionService::NOTE_MAX],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'note.required' => 'Explain why the request is rejected. The instructor will see this.',
            'note.max' => 'Use at most '.GradeCorrectionService::NOTE_MAX.' characters.',
        ];
    }

    public function note(): ?string
    {
        $note = $this->validated('note');

        return $note === null ? null : (string) $note;
    }
}
