<?php

namespace App\Http\Requests\Grading;

use App\Enums\CorrectionIncidentType;
use App\Models\Assessment;
use App\Services\Grading\GradeCorrectionService;
use App\Support\DecimalValue;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A request to correct one finalized score, with its incident report.
 * Authorization: AssessmentPolicy::manage on the route. Class membership,
 * the finalized state and the current value are re-checked by
 * GradeCorrectionService.
 */
class StoreGradeCorrectionRequest extends FormRequest
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
        $maxScore = DecimalValue::display($this->assessment()->max_score);

        return [
            'candidate_id' => ['required', 'integer', 'min:1'],
            'score' => ['nullable', 'numeric', 'decimal:0,2', 'min:0', "max:{$maxScore}"],
            'comment' => ['nullable', 'string', 'max:500'],
            'incident_type' => ['required', Rule::enum(CorrectionIncidentType::class)],
            'incident_details' => ['required', 'string', 'min:'.GradeCorrectionService::DETAILS_MIN, 'max:'.GradeCorrectionService::DETAILS_MAX],
            'expected_score' => ['nullable', 'numeric', 'decimal:0,2'],
            'expected_comment' => ['nullable', 'string', 'max:500'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        $maxScore = DecimalValue::display($this->assessment()->max_score);

        return [
            'score.numeric' => 'Enter the score as a number.',
            'score.decimal' => 'Use at most two decimal places.',
            'score.min' => "Enter a score from 0 to {$maxScore}.",
            'score.max' => "Enter a score from 0 to {$maxScore}.",
            'comment.max' => 'Use at most 500 characters.',
            'incident_type.required' => 'Choose what happened.',
            'incident_type.enum' => 'Choose what happened from the list.',
            'incident_details.required' => 'Write the incident report: what happened and why the score must change.',
            'incident_details.min' => 'Describe what happened in at least '.GradeCorrectionService::DETAILS_MIN.' characters.',
            'incident_details.max' => 'Use at most '.GradeCorrectionService::DETAILS_MAX.' characters.',
        ];
    }

    public function scoreValue(): ?string
    {
        return $this->optionalString('score');
    }

    public function commentValue(): ?string
    {
        return $this->optionalString('comment');
    }

    public function incidentType(): CorrectionIncidentType
    {
        return CorrectionIncidentType::from((string) $this->validated('incident_type'));
    }

    public function expectedScore(): ?string
    {
        return $this->optionalString('expected_score');
    }

    public function expectedComment(): ?string
    {
        return $this->optionalString('expected_comment');
    }

    private function optionalString(string $key): ?string
    {
        $value = $this->validated($key);

        return $value === null ? null : (string) $value;
    }

    private function assessment(): Assessment
    {
        /** @var Assessment $assessment */
        $assessment = $this->route('assessment');

        return $assessment;
    }
}
