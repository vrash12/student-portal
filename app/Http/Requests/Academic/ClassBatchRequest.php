<?php

namespace App\Http\Requests\Academic;

use App\Http\Requests\Concerns\NormalizesTextInput;
use App\Models\ClassBatch;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Create a class (name and academic period) or rename one. The academic
 * period cannot change after creation. Authorization is enforced by the
 * `can:class_batches.manage` route middleware.
 */
class ClassBatchRequest extends FormRequest
{
    use NormalizesTextInput;

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['name' => $this->trimmedInput('name')]);

        // With a single campus to choose from, a new class goes there (the form shows it as text).
        if ($this->editedClassBatch() === null && in_array($this->input('campus_id'), [null, ''], true)) {
            $assignable = $this->user()->campusScope()->assignableIds();
            if (count($assignable) === 1) {
                $this->merge(['campus_id' => $assignable[0]]);
            }
        }
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $classBatch = $this->editedClassBatch();
        $periodId = $classBatch?->academic_period_id ?? $this->integer('academic_period_id');
        $campusId = $classBatch?->campus_id ?? $this->integer('campus_id');

        return [
            'academic_period_id' => $classBatch === null
                ? ['required', 'integer', Rule::exists('academic_periods', 'id')]
                : ['prohibited'],
            // The campus is chosen once (an active campus the user may work
            // with) and never changes (owner decision 2026-10-03).
            'campus_id' => $classBatch === null
                ? ['required', 'integer', Rule::in($this->user()->campusScope()->assignableIds())]
                : ['prohibited'],
            'name' => [
                'required', 'string', 'max:100',
                // Two campuses may each have a class of the same name in one year.
                Rule::unique('class_batches', 'name')
                    ->where('academic_period_id', $periodId)
                    ->where('campus_id', $campusId)
                    ->ignore($classBatch),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.unique' => 'Another class of this campus in this academic period already uses this name.',
            'academic_period_id.prohibited' => 'The academic period of an existing class cannot be changed.',
            'campus_id.prohibited' => 'The campus of an existing class cannot be changed.',
            'campus_id.in' => 'Choose an active campus you manage.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['academic_period_id' => 'academic period', 'campus_id' => 'campus'];
    }

    private function editedClassBatch(): ?ClassBatch
    {
        $classBatch = $this->route('classBatch');

        return $classBatch instanceof ClassBatch ? $classBatch : null;
    }
}
