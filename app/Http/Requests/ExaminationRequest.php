<?php

namespace App\Http\Requests;

use App\Enums\Permission;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;

final class ExaminationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->hasPermission(Permission::ManageExaminations);
    }

    public function rules(): array
    {
        return [
            'class_subject_id' => $this->isMethod('post') ? 'required|integer|min:1' : 'prohibited',
            'kind' => 'required|in:quiz,examination', 'title' => 'required|string|max:200',
            'description' => 'nullable|string|max:10000', 'duration_minutes' => 'nullable|integer|min:1|max:1440',
            'attempt_limit' => 'required|integer|min:1|max:100', 'passing_score' => 'nullable|numeric|decimal:0,2|min:0|max:100',
            'opens_at' => 'nullable|date', 'closes_at' => 'nullable|date|after:opens_at',
            'access_code' => 'nullable|string|max:100', 'release_results' => 'required|boolean',
            'randomize_questions' => 'required|boolean', 'randomize_choices' => 'required|boolean',
            'one_question_at_a_time' => 'required|boolean', 'allow_back_navigation' => 'required|boolean', 'auto_submit' => 'required|boolean',
        ];
    }

    public function settings(): array
    {
        $data = $this->validated();
        foreach (['opens_at', 'closes_at'] as $field) {
            $data[$field] = empty($data[$field]) ? null : Carbon::parse($data[$field], config('institution.timezone'))->utc()->format('Y-m-d H:i:s');
        }

        return $data;
    }
}
