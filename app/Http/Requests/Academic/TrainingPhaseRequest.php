<?php

namespace App\Http\Requests\Academic;

use App\Http\Requests\Concerns\NormalizesTextInput;
use App\Models\TrainingPhase;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Create or update a training phase. Authorization is enforced by the
 * `can:academic_periods.manage` route middleware.
 */
class TrainingPhaseRequest extends FormRequest
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
            'number' => $this->trimmedInput('number'),
        ]);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        /** @var TrainingPhase|null $phase */
        $phase = $this->route('trainingPhase');

        return [
            'number' => ['required', 'integer', 'min:1', 'max:'.TrainingPhase::MAX_NUMBER, Rule::unique('training_phases', 'number')->ignore($phase?->id)],
            'name' => ['required', 'string', 'max:100', Rule::unique('training_phases', 'name')->ignore($phase?->id)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'number.required' => 'Enter the phase number, for example 1 for the first phase.',
            'number.integer' => 'Enter the phase number as a whole number from 1 to '.TrainingPhase::MAX_NUMBER.'.',
            'number.min' => 'Enter the phase number as a whole number from 1 to '.TrainingPhase::MAX_NUMBER.'.',
            'number.max' => 'Enter the phase number as a whole number from 1 to '.TrainingPhase::MAX_NUMBER.'.',
            'number.unique' => 'Another phase already has this number.',
            'name.required' => 'Name the phase, for example "Phase 1".',
            'name.unique' => 'Another phase already uses this name.',
        ];
    }

    /**
     * @return array{number: int, name: string}
     */
    public function phaseData(): array
    {
        return [
            'number' => (int) $this->validated('number'),
            'name' => (string) $this->validated('name'),
        ];
    }
}
