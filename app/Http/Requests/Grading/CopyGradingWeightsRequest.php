<?php

namespace App\Http\Requests\Grading;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

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
        // Only class subjects of a campus the user may work with.
        $campus = $this->user()->campusScope();
        $inCampus = fn () => Rule::exists('class_subjects', 'id')->where(fn ($offerings) => $campus->constrain($offerings, 'campus_id'));

        return [
            'source' => ['required', 'integer', $inCampus()],
            'targets' => ['required', 'array', 'min:1', 'max:'.self::MAX_TARGETS],
            'targets.*' => ['required', 'integer', 'distinct', $inCampus()],
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
