<?php

namespace App\Http\Requests\Academic;

use App\Models\ClassSubject;
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
        $campus = $this->user()->campusScope();

        return [
            // Only subjects of classes on a campus the user may work with.
            'class_subject_id' => ['bail', 'required', 'integer', Rule::exists('class_subjects', 'id')->where(fn ($offerings) => $campus->constrain($offerings, 'campus_id'))],
            'instructor_id' => [
                'bail', 'required', 'integer',
                function (string $attribute, mixed $value, Closure $fail) use ($campus): void {
                    $instructor = User::query()->with(['role.permissions', 'campus'])->find($value);

                    if ($instructor === null || ! $instructor->canTeach() || ! $campus->allowsRecord($instructor)) {
                        $fail('Select an active instructor.');

                        return;
                    }

                    // Instructors teach only on their own campus (owner decision 2026-10-03).
                    $offeringCampus = ClassSubject::query()->whereKey($this->integer('class_subject_id'))->value('campus_id');
                    if ($offeringCampus !== null && (int) $offeringCampus !== $instructor->campus_id) {
                        $fail($instructor->campus === null
                            ? "{$instructor->name} has no campus yet. Set the campus on the account first."
                            : "{$instructor->name} teaches at {$instructor->campus->name}, not at this class's campus.");
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
