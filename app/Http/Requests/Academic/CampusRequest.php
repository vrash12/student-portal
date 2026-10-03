<?php

namespace App\Http\Requests\Academic;

use App\Http\Requests\Concerns\NormalizesTextInput;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Edit one of the four fixed campuses (owner decision 2026-10-04): only the
 * address and whether it is active change; the name and code never do.
 * Authorization: route middleware `can:campuses.manage` and `institution`
 * (accounts not limited to a campus).
 */
class CampusRequest extends FormRequest
{
    use NormalizesTextInput;

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['address' => $this->optionalInput('address')]);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => ['prohibited'],
            'code' => ['prohibited'],
            'address' => ['nullable', 'string', 'max:255'],
            'is_active' => ['required', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.prohibited' => 'The names of the four campuses are fixed.',
            'code.prohibited' => 'The codes of the four campuses are fixed.',
        ];
    }

    /**
     * @return array{address: ?string, is_active: bool}
     */
    public function campusData(): array
    {
        return [
            'address' => $this->validated('address'),
            'is_active' => $this->boolean('is_active'),
        ];
    }
}
