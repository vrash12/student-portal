<?php

namespace App\Http\Requests\Conduct;

use App\Enums\ConductKind;
use App\Http\Requests\Concerns\NormalizesTextInput;
use App\Models\ConductType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Create or update a merit/demerit type. Authorization is enforced by the
 * `can:performance.configure` route middleware.
 */
class ConductTypeRequest extends FormRequest
{
    use NormalizesTextInput;

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'name' => $this->trimmedInput('name'),
            'default_points' => $this->trimmedInput('default_points'),
            'description' => $this->optionalInput('description'),
            'sort_order' => $this->trimmedInput('sort_order'),
        ]);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $type = $this->route('conductType');
        $typeId = $type instanceof ConductType ? $type->id : null;

        return [
            'name' => ['required', 'string', 'max:100', Rule::unique('conduct_types', 'name')->ignore($typeId)],
            'kind' => ['required', Rule::enum(ConductKind::class)],
            'default_points' => ['required', 'integer', 'min:1', 'max:100'],
            'description' => ['nullable', 'string', 'max:255'],
            'sort_order' => ['required', 'integer', 'min:0', 'max:999'],
            'is_active' => [$typeId === null ? 'prohibited' : 'required', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => 'Enter the name of the type.',
            'name.unique' => 'Another merit or demerit type already uses this name.',
            'kind.required' => 'Choose whether this is a merit or a demerit.',
            'kind.enum' => 'Choose whether this is a merit or a demerit.',
            'default_points.required' => 'Enter the usual points.',
            'default_points.integer' => 'Enter the usual points as a whole number from 1 to 100.',
            'default_points.min' => 'Enter the usual points as a whole number from 1 to 100.',
            'default_points.max' => 'Enter the usual points as a whole number from 1 to 100.',
            'sort_order.required' => 'Enter the position in lists.',
            'sort_order.integer' => 'Enter the position as a whole number from 0 to 999.',
            'sort_order.min' => 'Enter the position as a whole number from 0 to 999.',
            'sort_order.max' => 'Enter the position as a whole number from 0 to 999.',
        ];
    }

    /**
     * @return array{name: string, kind: string, default_points: int, description: ?string, sort_order: int, is_active: bool}
     */
    public function typeData(): array
    {
        return [
            'name' => (string) $this->validated('name'),
            'kind' => (string) $this->validated('kind'),
            'default_points' => (int) $this->validated('default_points'),
            'description' => $this->validated('description'),
            'sort_order' => (int) $this->validated('sort_order'),
            'is_active' => $this->boolean('is_active', true),
        ];
    }
}
