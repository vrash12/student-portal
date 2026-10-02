<?php

namespace App\Http\Requests\Grading;

use App\Models\Assessment;
use App\Models\ClassSubject;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Details of an assessment, for creating one (route has {classSubject}) or
 * editing a draft (route has {assessment}). Authorization is on the route.
 */
class AssessmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Only strings are trimmed; anything else is left for the rules to reject.
     */
    protected function prepareForValidation(): void
    {
        $title = $this->input('title');

        if (is_string($title)) {
            $this->merge(['title' => trim($title)]);
        }
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $offeringId = $this->offering()->id;

        return [
            'title' => [
                'required', 'string', 'max:150',
                Rule::unique('assessments', 'title')->where('class_subject_id', $offeringId)->ignore($this->editedAssessment()),
            ],
            'assessment_category_id' => [
                'required', 'integer',
                Rule::exists('assessment_categories', 'id')->where('class_subject_id', $offeringId),
            ],
            'max_score' => ['required', 'numeric', 'decimal:0,2', 'gt:0', 'max:9999.99'],
            'assessed_on' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:2000-01-01', 'before:2100-01-01'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'title.required' => 'Enter a title, for example Quiz 1.',
            'title.unique' => 'This subject already has an assessment with this title.',
            'assessment_category_id.required' => 'Select a grading component.',
            'assessment_category_id.exists' => 'Select a grading component of this subject.',
            'max_score.required' => 'Enter the maximum score.',
            'max_score.numeric' => 'Enter the maximum score as a number, for example 50.',
            'max_score.decimal' => 'Use at most two decimal places.',
            'max_score.gt' => 'The maximum score must be greater than 0.',
            'max_score.max' => 'The maximum score cannot be more than 9,999.99.',
            'assessed_on.date_format' => 'Enter a valid date.',
            'assessed_on.after_or_equal' => 'Enter a valid date.',
            'assessed_on.before' => 'Enter a valid date.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'assessment_category_id' => 'component',
            'max_score' => 'maximum score',
            'assessed_on' => 'date',
        ];
    }

    /**
     * @return array{title: string, assessment_category_id: int, max_score: string, assessed_on: ?string}
     */
    public function assessmentData(): array
    {
        $date = $this->validated('assessed_on');

        return [
            'title' => (string) $this->validated('title'),
            'assessment_category_id' => (int) $this->validated('assessment_category_id'),
            'max_score' => (string) $this->validated('max_score'),
            'assessed_on' => $date === null || $date === '' ? null : (string) $date,
        ];
    }

    private function editedAssessment(): ?Assessment
    {
        $assessment = $this->route('assessment');

        return $assessment instanceof Assessment ? $assessment : null;
    }

    private function offering(): ClassSubject
    {
        $assessment = $this->editedAssessment();
        if ($assessment !== null) {
            return $assessment->classSubject;
        }

        /** @var ClassSubject $offering */
        $offering = $this->route('classSubject');

        return $offering;
    }
}
