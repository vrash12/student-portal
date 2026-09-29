<?php

namespace App\Http\Requests\Academic;

use App\Models\Subject;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Create or update a subject. Authorization is enforced by the
 * `can:subjects.manage` route middleware.
 */
class SubjectRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $description = trim((string) $this->input('description'));

        $this->merge([
            'code' => trim((string) $this->input('code')),
            'name' => trim((string) $this->input('name')),
            'description' => $description === '' ? null : $description,
        ]);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        /** @var Subject|null $subject */
        $subject = $this->route('subject');

        return [
            'code' => [
                'required', 'string', 'max:30', 'regex:/^[A-Za-z0-9._-]+$/',
                Rule::unique('subjects', 'code')->ignore($subject),
            ],
            'name' => ['required', 'string', 'max:150'],
            'description' => ['nullable', 'string', 'max:500'],
            'is_active' => [$subject === null ? 'sometimes' : 'required', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'code.unique' => 'Another subject already uses this code.',
            'code.regex' => 'Use only letters, numbers, periods, hyphens, and underscores.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['is_active' => 'status'];
    }

    /**
     * @return array{code: string, name: string, description: ?string, is_active: bool}
     */
    public function subjectData(): array
    {
        $description = $this->input('description');

        return [
            'code' => $this->string('code')->value(),
            'name' => $this->string('name')->value(),
            'description' => is_string($description) ? $description : null,
            'is_active' => $this->boolean('is_active', true),
        ];
    }
}
