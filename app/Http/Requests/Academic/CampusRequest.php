<?php

namespace App\Http\Requests\Academic;

use App\Http\Requests\Concerns\NormalizesTextInput;
use App\Models\Campus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Create or update a campus. Authorization: route middleware
 * `can:campuses.manage` and `institution` (accounts not limited to a campus).
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
        $code = $this->trimmedInput('code');

        $this->merge([
            'name' => $this->trimmedInput('name'),
            'code' => is_string($code) ? strtoupper($code) : $code,
            'address' => $this->optionalInput('address'),
        ]);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $campus = $this->editedCampus();

        return [
            'name' => ['required', 'string', 'max:100', Rule::unique('campuses', 'name')->ignore($campus?->id)],
            'code' => ['required', 'string', 'max:20', 'regex:/^[A-Z0-9_-]+$/', Rule::unique('campuses', 'code')->ignore($campus?->id)],
            'address' => ['nullable', 'string', 'max:255'],
            'is_active' => [$campus === null ? 'prohibited' : 'required', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.unique' => 'Another campus already uses this name.',
            'code.unique' => 'Another campus already uses this code.',
            'code.regex' => 'Use only letters, numbers, hyphens and underscores.',
        ];
    }

    /**
     * @return array{name: string, code: string, address: ?string, is_active: bool}
     */
    public function campusData(): array
    {
        return [
            'name' => (string) $this->validated('name'),
            'code' => (string) $this->validated('code'),
            'address' => $this->validated('address'),
            'is_active' => $this->boolean('is_active', true),
        ];
    }

    private function editedCampus(): ?Campus
    {
        $campus = $this->route('campus');

        return $campus instanceof Campus ? $campus : null;
    }
}
