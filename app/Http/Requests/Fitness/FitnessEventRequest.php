<?php

namespace App\Http\Requests\Fitness;

use App\Enums\FitnessUnit;
use App\Http\Requests\Concerns\NormalizesTextInput;
use App\Models\FitnessEvent;
use App\Services\Fitness\FitnessValue;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Create or update a fitness event and its standards. Authorization is
 * enforced by the `can:fitness.manage` route middleware.
 */
class FitnessEventRequest extends FormRequest
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
            'passing_value' => $this->trimmedInput('passing_value'),
            'maximum_value' => $this->trimmedInput('maximum_value'),
        ]);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        /** @var FitnessEvent|null $event */
        $event = $this->route('fitnessEvent');

        return [
            'name' => ['required', 'string', 'max:100', Rule::unique('fitness_events', 'name')->ignore($event?->id)],
            'description' => ['nullable', 'string', 'max:500'],
            'unit' => ['required', Rule::enum(FitnessUnit::class)],
            'higher_is_better' => ['required', 'boolean'],
            'passing_value' => ['required', 'string', 'max:12'],
            'maximum_value' => ['required', 'string', 'max:12'],
            'sort_order' => ['required', 'integer', 'min:0', 'max:999'],
            'is_active' => [$event === null ? 'prohibited' : 'required', 'boolean'],
        ];
    }

    /**
     * Standards are read in the event's unit (minutes:seconds for times), and
     * the maximum must be better than the passing value.
     *
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $unit = FitnessUnit::tryFrom((string) $this->input('unit'));
            if ($unit === null || $validator->errors()->hasAny(['passing_value', 'maximum_value', 'higher_is_better'])) {
                return;
            }

            $hint = $unit === FitnessUnit::Time ? 'Enter a time such as 12:30 (minutes:seconds).' : 'Enter a whole number of repetitions.';
            $passing = FitnessValue::parse((string) $this->input('passing_value'), $unit);
            $maximum = FitnessValue::parse((string) $this->input('maximum_value'), $unit);
            if ($passing === null || $passing <= 0) {
                $validator->errors()->add('passing_value', $hint.' It must be more than zero.');
            }
            if ($maximum === null || $maximum <= 0) {
                $validator->errors()->add('maximum_value', $hint.' It must be more than zero.');
            }
            if ($passing === null || $maximum === null || $passing <= 0 || $maximum <= 0) {
                return;
            }

            $higherIsBetter = $this->boolean('higher_is_better');
            if ($higherIsBetter && $maximum <= $passing) {
                $validator->errors()->add('maximum_value', 'When higher results are better, the maximum standard must be higher than the passing standard.');
            } elseif (! $higherIsBetter && $maximum >= $passing) {
                $validator->errors()->add('maximum_value', 'When lower results are better (such as faster times), the maximum standard must be lower than the passing standard.');
            }
        }];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.unique' => 'Another fitness event already uses this name.',
            'passing_value.required' => 'Enter the passing standard.',
            'maximum_value.required' => 'Enter the maximum standard.',
        ];
    }

    /**
     * @return array{name: string, description: ?string, unit: string, higher_is_better: bool, passing_value: float, maximum_value: float, sort_order: int, is_active: bool}
     */
    public function eventData(): array
    {
        $unit = FitnessUnit::from((string) $this->validated('unit'));

        return [
            'name' => (string) $this->validated('name'),
            'description' => $this->validated('description'),
            'unit' => $unit->value,
            'higher_is_better' => $this->boolean('higher_is_better'),
            'passing_value' => (float) FitnessValue::parse((string) $this->validated('passing_value'), $unit),
            'maximum_value' => (float) FitnessValue::parse((string) $this->validated('maximum_value'), $unit),
            'sort_order' => (int) $this->validated('sort_order'),
            'is_active' => $this->boolean('is_active', true),
        ];
    }
}
