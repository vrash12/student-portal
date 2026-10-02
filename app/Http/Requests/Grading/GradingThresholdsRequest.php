<?php

namespace App\Http\Requests\Grading;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Input shape for the passing and warning grades of an academic period. The
 * order of the two grades and the reason requirement are enforced by
 * GradingThresholdService. Authorization: `can:grading.configure` on the
 * route.
 */
class GradingThresholdsRequest extends FormRequest
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
        return [
            'passing_grade' => ['required', 'numeric', 'decimal:0,2', 'gt:0', 'max:100'],
            'warning_grade' => ['required', 'numeric', 'decimal:0,2', 'gt:0', 'max:100'],
            'reason' => ['nullable', 'string', 'max:500'],
            // Where to go after saving: only Grading Setup, never a free URL.
            'return' => ['nullable', 'string', 'in:setup'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        $messages = ['reason.max' => 'Use at most 500 characters.'];

        foreach (['passing_grade' => 'passing grade', 'warning_grade' => 'warning grade'] as $field => $name) {
            $messages += [
                "{$field}.required" => "Enter the {$name}.",
                "{$field}.numeric" => 'Enter the grade as a number, for example 75.',
                "{$field}.decimal" => 'Use at most two decimal places.',
                "{$field}.gt" => 'Enter a grade greater than 0.',
                "{$field}.max" => 'A grade cannot be more than 100.',
            ];
        }

        return $messages;
    }

    public function passingGrade(): string
    {
        return (string) $this->validated('passing_grade');
    }

    public function warningGrade(): string
    {
        return (string) $this->validated('warning_grade');
    }

    public function reason(): ?string
    {
        $reason = trim((string) $this->validated('reason'));

        return $reason === '' ? null : $reason;
    }
}
