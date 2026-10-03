<?php

namespace App\Http\Requests\Accounts;

use App\Http\Requests\Concerns\NormalizesTextInput;
use App\Models\AccountCategory;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Create or update a Statement of Account category. Authorization is
 * enforced by the `can:accounts.manage` route middleware.
 */
class AccountCategoryRequest extends FormRequest
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
            'description' => $this->optionalInput('description'),
        ]);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        /** @var AccountCategory|null $category */
        $category = $this->route('accountCategory');

        return [
            'name' => ['required', 'string', 'max:100', Rule::unique('account_categories', 'name')->ignore($category?->id)],
            'description' => ['nullable', 'string', 'max:255'],
            'is_active' => [$category === null ? 'prohibited' : 'required', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['name.unique' => 'Another category already uses this name.'];
    }

    /**
     * @return array{name: string, description: ?string, is_active: bool}
     */
    public function categoryData(): array
    {
        return [
            'name' => (string) $this->validated('name'),
            'description' => $this->validated('description'),
            'is_active' => $this->boolean('is_active', true),
        ];
    }
}
