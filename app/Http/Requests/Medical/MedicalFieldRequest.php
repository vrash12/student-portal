<?php

namespace App\Http\Requests\Medical;

use App\Enums\MedicalFieldType;
use App\Http\Requests\Concerns\NormalizesTextInput;
use App\Models\MedicalField;
use App\Services\Medical\MedicalRecordService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Create or update a medical record field. Authorization is enforced by the
 * `can:medical.configure` route middleware. Choices arrive one per line.
 */
class MedicalFieldRequest extends FormRequest
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
            'help_text' => $this->optionalInput('help_text'),
            'section' => $this->optionalInput('section'),
            'unit' => $this->optionalInput('unit'),
            'sort_order' => $this->trimmedInput('sort_order'),
            'options' => $this->choiceList(),
        ]);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $field = $this->route('medicalField');
        $fieldId = $field instanceof MedicalField ? $field->id : null;
        $choice = $this->input('field_type') === MedicalFieldType::Choice->value;

        return [
            'name' => ['required', 'string', 'max:100', Rule::unique('medical_fields', 'name')->ignore($fieldId)],
            'field_type' => ['required', Rule::enum(MedicalFieldType::class)],
            'options' => $choice ? ['required', 'array', 'min:2', 'max:'.MedicalRecordService::OPTIONS_MAX] : ['nullable', 'array'],
            'options.*' => ['string', 'max:100', 'distinct:ignore_case'],
            'help_text' => ['nullable', 'string', 'max:255'],
            'section' => ['nullable', 'string', 'max:60'],
            'unit' => ['nullable', 'string', 'max:20'],
            'sort_order' => ['required', 'integer', 'min:0', 'max:999'],
            'visible_to_candidate' => ['required', 'boolean'],
            'is_active' => [$fieldId === null ? 'prohibited' : 'required', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => 'Enter the name of the field.',
            'name.unique' => 'Another medical record field already uses this name.',
            'field_type.required' => 'Choose the kind of answer.',
            'field_type.enum' => 'Choose the kind of answer.',
            'options.required' => 'Enter the choices, one per line.',
            'options.min' => 'Enter at least two choices, one per line.',
            'options.max' => 'Use at most '.MedicalRecordService::OPTIONS_MAX.' choices.',
            'options.*.max' => 'Keep each choice to 100 characters.',
            'options.*.distinct' => 'Each choice must appear once.',
            'help_text.max' => 'Use at most 255 characters.',
            'section.max' => 'Use at most 60 characters.',
            'unit.max' => 'Use at most 20 characters.',
            'sort_order.required' => 'Enter the position in the record.',
            'sort_order.integer' => 'Enter the position as a whole number from 0 to 999.',
            'sort_order.min' => 'Enter the position as a whole number from 0 to 999.',
            'sort_order.max' => 'Enter the position as a whole number from 0 to 999.',
        ];
    }

    /**
     * One error message for the whole list, shown under the choices box.
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                foreach ($validator->errors()->keys() as $key) {
                    if (str_starts_with($key, 'options.') && ! $validator->errors()->has('options')) {
                        $validator->errors()->add('options', $validator->errors()->first($key));
                    }
                }
            },
        ];
    }

    /**
     * @return array{name: string, section: ?string, field_type: string, options: list<string>|null, unit: ?string, help_text: ?string, sort_order: int, visible_to_candidate: bool, is_active: bool}
     */
    public function fieldData(): array
    {
        $options = $this->validated('options');

        return [
            'name' => (string) $this->validated('name'),
            'field_type' => (string) $this->validated('field_type'),
            'options' => is_array($options) ? array_values(array_map('strval', $options)) : null,
            'help_text' => $this->validated('help_text'),
            'section' => $this->validated('section'),
            'unit' => $this->validated('unit'),
            'sort_order' => (int) $this->validated('sort_order'),
            'visible_to_candidate' => $this->boolean('visible_to_candidate'),
            'is_active' => $this->boolean('is_active', true),
        ];
    }

    /**
     * The choices typed one per line, trimmed, without blank lines.
     *
     * @return list<string>|null
     */
    private function choiceList(): ?array
    {
        $raw = $this->input('options');
        if (! is_string($raw)) {
            return is_array($raw) ? array_values($raw) : null;
        }

        $lines = array_values(array_filter(array_map('trim', preg_split('/\R/', $raw) ?: []), fn (string $line): bool => $line !== ''));

        return $lines === [] ? null : $lines;
    }
}
