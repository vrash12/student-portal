<?php

namespace App\Http\Requests\Academic;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Change the training phase and units of a subject of a class.
 * Authorization is enforced by the `can:class_batches.manage` route
 * middleware; the class subject is bound within its class.
 */
class UpdateClassSubjectRequest extends FormRequest
{
    use ValidatesSubjectPlacement;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return $this->placementRules(unitsRequired: true);
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return $this->placementMessages();
    }
}
