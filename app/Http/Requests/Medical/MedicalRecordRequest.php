<?php

namespace App\Http\Requests\Medical;

use App\Enums\MedicalFieldType;
use App\Models\MedicalField;
use App\Services\Medical\MedicalRecordService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Http\FormRequest;

/**
 * A candidate's medical record values, keyed by field id (`values.{id}`).
 * Only active fields are accepted; each is checked by its type's rules.
 * Authorization: CandidatePolicy::manageMedical on the route.
 */
class MedicalRecordRequest extends FormRequest
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
        $rules = ['values' => ['present', 'array']];
        foreach ($this->activeFields() as $field) {
            $rules["values.{$field->id}"] = MedicalRecordService::rulesFor($field);
        }

        return $rules;
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        $messages = [];
        foreach ($this->activeFields() as $field) {
            $key = "values.{$field->id}";
            $messages["{$key}.in"] = 'Choose one of the listed answers.';
            $messages["{$key}.date_format"] = 'Enter a valid date.';
            $messages["{$key}.numeric"] = 'Enter a number.';
            $messages["{$key}.decimal"] = 'Use at most two decimal places.';
            $messages["{$key}.min"] = 'Enter a number from 0 to 99,999.';
            $messages["{$key}.max"] = $field->field_type === MedicalFieldType::Number ? 'Enter a number from 0 to 99,999.' : 'This answer is too long (:max characters at most).';
        }

        return $messages;
    }

    /**
     * Values of active fields only; unknown or inactive field ids are ignored.
     *
     * @return array<int, mixed>
     */
    public function values(): array
    {
        $values = (array) $this->input('values', []);
        $active = $this->activeFields()->modelKeys();

        return array_filter($values, fn (mixed $value, int|string $id): bool => in_array((int) $id, $active, true), ARRAY_FILTER_USE_BOTH);
    }

    /**
     * @return Collection<int, MedicalField>
     */
    private function activeFields()
    {
        return once(fn () => MedicalField::query()->active()->get());
    }
}
