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
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $classBatch = $this->editedClassBatch();
        $periodId = $classBatch?->academic_period_id ?? $this->integer('academic_period_id');

        return [
            'academic_period_id' => $classBatch === null
                ? ['required', 'integer', Rule::exists('academic_periods', 'id')]
                : ['prohibited'],
            'name' => [
                'required', 'string', 'max:100',
                Rule::unique('class_batches', 'name')
                    ->where('academic_period_id', $periodId)
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
            'name.unique' => 'Another class in this academic period already uses this name.',
            'academic_period_id.prohibited' => 'The academic period of an existing class cannot be changed.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['academic_period_id' => 'academic period'];
    }

    private function editedClassBatch(): ?ClassBatch
    {
        $classBatch = $this->route('classBatch');

        return $classBatch instanceof ClassBatch ? $classBatch : null;
    }
}
