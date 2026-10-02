<?php

namespace App\Http\Requests\Grading;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Input shape for the grading categories of a class subject. The total of
 * 100%, unique names, removal rules, and the reason requirement are enforced
 * by GradingSchemeService. Authorization: ClassSubjectPolicy::configureGrading
 * on the route.
 */
class GradingSchemeRequest extends FormRequest
{
    public const MAX_CATEGORIES = 10;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * Trims category names. Only strings are trimmed; anything else is left
     * for the rules to reject.
     */
    protected function prepareForValidation(): void
    {
        $categories = $this->input('categories');

        if (is_array($categories)) {
            $this->merge([
                'categories' => array_map(
                    fn (mixed $category): mixed => is_array($category) && is_string($category['name'] ?? null)
                        ? [...$category, 'name' => trim($category['name'])]
                        : $category,
                    array_values($categories),
                ),
            ]);
        }
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'categories' => ['required', 'array', 'min:1', 'max:'.self::MAX_CATEGORIES],
            'categories.*' => ['required', 'array:id,name,weight'],
            'categories.*.id' => ['nullable', 'integer', 'distinct'],
            'categories.*.name' => ['required', 'string', 'max:100'],
            'categories.*.weight' => ['required', 'numeric', 'decimal:0,2', 'gt:0', 'max:100'],
            'reason' => ['nullable', 'string', 'max:500'],
            // Where to go after saving: 'setup' returns to Grading Setup.
            'return' => ['nullable', 'string', 'in:setup'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'categories.required' => 'Add at least one component, for example Quizzes.',
            'categories.max' => 'A subject can have at most '.self::MAX_CATEGORIES.' components.',
            'categories.*.name.required' => 'Enter a component name.',
            'categories.*.name.max' => 'Use at most 100 characters.',
            'categories.*.weight.required' => 'Enter a weight.',
            'categories.*.weight.numeric' => 'Enter the weight as a number, for example 20.',
            'categories.*.weight.decimal' => 'Use at most two decimal places.',
            'categories.*.weight.gt' => 'Enter a weight greater than 0.',
            'categories.*.weight.max' => 'A weight cannot be more than 100.',
        ];
    }

    /**
     * @return list<array{id: int|null, name: string, weight: string}>
     */
    public function categories(): array
    {
        return array_map(fn (array $category): array => [
            'id' => isset($category['id']) ? (int) $category['id'] : null,
            'name' => (string) $category['name'],
            'weight' => (string) $category['weight'],
        ], $this->validated('categories'));
    }

    public function reason(): ?string
    {
        $reason = trim((string) $this->validated('reason'));

        return $reason === '' ? null : $reason;
    }
}
