<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PostExaminationGradesRequest extends FormRequest
{
    public function authorize(): bool
    {
        $exam = $this->route('examination');

        return $this->user()->can('view', $exam) && $this->user()->can('recordGrades', $exam->classSubject);
    }

    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:150'],
            'assessment_category_id' => ['required', 'integer'],
            'attempt_rule' => ['required', Rule::in(['highest', 'latest'])],
            'review_token' => ['required', 'string', 'size:64'],
            'confirmed' => ['accepted'],
        ];
    }
}
