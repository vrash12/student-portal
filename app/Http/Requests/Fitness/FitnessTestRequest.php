<?php

namespace App\Http\Requests\Fitness;

use App\Http\Requests\Concerns\NormalizesTextInput;
use App\Models\FitnessTest;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Create a fitness test (class, details, events) or update its details.
 * Authorization is enforced by the `can:fitness.manage` route middleware.
 */
class FitnessTestRequest extends FormRequest
{
    use NormalizesTextInput;

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'title' => $this->trimmedInput('title'),
            'notes' => $this->optionalInput('notes'),
        ]);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $creating = ! $this->route('fitnessTest') instanceof FitnessTest;

        return [
            'class_batch_id' => $creating ? ['required', 'integer', Rule::exists('class_batches', 'id')] : ['prohibited'],
            'title' => ['required', 'string', 'max:150'],
            'tested_on' => ['required', 'date_format:Y-m-d', 'after_or_equal:2000-01-01', 'before_or_equal:2100-12-31'],
            'notes' => ['nullable', 'string', 'max:500'],
            'event_ids' => $creating ? ['required', 'array', 'min:1', 'max:30'] : ['prohibited'],
            'event_ids.*' => ['integer', 'distinct', Rule::exists('fitness_events', 'id')->where('is_active', true)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'class_batch_id.required' => 'Choose the class taking the test.',
            'event_ids.required' => 'Choose at least one fitness event.',
            'event_ids.min' => 'Choose at least one fitness event.',
            'event_ids.*.exists' => 'Choose active fitness events only.',
            'tested_on.date_format' => 'Enter the test date.',
        ];
    }

    /**
     * @return array{title: string, tested_on: string, notes: ?string}
     */
    public function details(): array
    {
        return [
            'title' => (string) $this->validated('title'),
            'tested_on' => (string) $this->validated('tested_on'),
            'notes' => $this->validated('notes'),
        ];
    }

    /**
     * @return list<int>
     */
    public function eventIds(): array
    {
        return array_map('intval', $this->validated('event_ids', []));
    }
}
