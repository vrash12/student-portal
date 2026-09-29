<?php

namespace App\Http\Requests\Academic;

use App\Models\ClassBatch;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Add an active subject to a class. Authorization is enforced by the
 * `can:class_batches.manage` route middleware.
 */
class StoreClassSubjectRequest extends FormRequest
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
        /** @var ClassBatch $classBatch */
        $classBatch = $this->route('classBatch');

        return [
            'subject_id' => [
                'bail', 'required', 'integer',
                Rule::exists('subjects', 'id')->where('is_active', true),
                Rule::unique('class_subjects', 'subject_id')->where('class_batch_id', $classBatch->id),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'subject_id.required' => 'Select a subject to add.',
            'subject_id.exists' => 'Select an active subject.',
            'subject_id.unique' => 'This class already takes this subject.',
        ];
    }
}
