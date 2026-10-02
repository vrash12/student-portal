<?php

namespace App\Http\Requests\Grading;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Input shape of "Copy Weights" on the Grading Setup page: one subject that
 * has weights and the subjects that should receive them. Which targets may
 * be filled is decided by GradingSchemeService::copy. Authorization:
 * `can:grading.configure` on the route.
 */
class CopyGradingWeightsRequest extends FormRequest
{
    public const MAX_TARGETS = 500;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'source' => ['required', 'integer', 'exists:class_subjects,id'],
            'targets' => ['required', 'array', 'min:1', 'max:'.self::MAX_TARGETS],
            'targets.*' => ['required', 'integer', 'distinct', 'exists:class_subjects,id'],
            'period' => ['nullable', 'integer'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'source.required' => 'Choose the subject whose weights should be copied.',
            'source.exists' => 'That subject no longer exists. Reload the page and try again.',
            'targets.required' => 'Choose at least one subject to receive the weights.',
            'targets.min' => 'Choose at least one subject to receive the weights.',
            'targets.*.exists' => 'One of the chosen subjects no longer exists. Reload the page and try again.',
        ];
    }

    /**
     * @return list<int>
     */
    public function targetIds(): array
    {
        return array_map('intval', $this->validated('targets'));
    }
}
