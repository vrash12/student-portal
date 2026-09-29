<?php

namespace App\Http\Requests\Academic;

use App\Models\User;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Assign an eligible, active instructor to a class subject. Authorization is
 * enforced by the `can:instructor_assignments.manage` route middleware.
 */
class StoreInstructorAssignmentRequest extends FormRequest
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
            'class_subject_id' => ['bail', 'required', 'integer', Rule::exists('class_subjects', 'id')],
            'instructor_id' => [
                'bail', 'required', 'integer',
                function (string $attribute, mixed $value, Closure $fail): void {
                    $instructor = User::query()->with('role.permissions')->find($value);

                    if ($instructor === null || ! $instructor->canTeach()) {
                        $fail('Select an active instructor.');
                    }
                },
                Rule::unique('instructor_assignments', 'instructor_id')
                    ->where('class_subject_id', $this->integer('class_subject_id')),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'class_subject_id.required' => 'Select the class subject to assign.',
            'instructor_id.required' => 'Select an instructor.',
            'instructor_id.unique' => 'This instructor is already assigned to this subject for this class.',
        ];
    }
}
