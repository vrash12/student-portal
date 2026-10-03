<?php

namespace App\Http\Requests\Academic;

use App\Http\Requests\Concerns\NormalizesTextInput;
use App\Models\AcademicPeriod;
use App\Models\TrainingPhase;
use App\Services\TrainingPhaseService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Create or update a training phase of an academic year. The year is chosen
 * when the phase is added and never changes. The phase's dates lie inside
 * the year and follow the phase numbers without overlapping another phase
 * (owner request, 2026-10-03: the whole course lasts one year).
 * Authorization is enforced by the `can:academic_periods.manage` route
 * middleware.
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
        $phase = $this->phaseBeingEdited();
        $periodId = $phase?->academic_period_id ?? (int) $this->input('academic_period_id');

        return [
            'academic_period_id' => $phase === null ? ['required', 'integer', Rule::exists('academic_periods', 'id')] : ['prohibited'],
            'number' => [
                'required', 'integer', 'min:1', 'max:'.TrainingPhase::MAX_NUMBER,
                Rule::unique('training_phases', 'number')->where('academic_period_id', $periodId)->ignore($phase?->id),
            ],
            'name' => ['required', 'string', 'max:100', Rule::unique('training_phases', 'name')->where('academic_period_id', $periodId)->ignore($phase?->id)],
            'starts_on' => ['required', 'date_format:Y-m-d'],
            'ends_on' => ['required', 'date_format:Y-m-d', 'after_or_equal:starts_on'],
        ];
    }

    /**
     * Dates inside the academic year, in phase order, without overlapping.
     *
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            foreach (TrainingPhaseService::datesProblem($this->period(), $this->phaseBeingEdited(), $this->phaseData()) as $field => $message) {
                $validator->errors()->add($field, $message);
            }
        }];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'academic_period_id.required' => 'Choose the academic year of the phase.',
            'academic_period_id.exists' => 'Choose one of the academic years.',
            'academic_period_id.prohibited' => 'A phase stays in the academic year it was added to.',
            'number.required' => 'Enter the phase number, for example 1 for the first phase.',
            'number.integer' => 'Enter the phase number as a whole number from 1 to '.TrainingPhase::MAX_NUMBER.'.',
            'number.min' => 'Enter the phase number as a whole number from 1 to '.TrainingPhase::MAX_NUMBER.'.',
            'number.max' => 'Enter the phase number as a whole number from 1 to '.TrainingPhase::MAX_NUMBER.'.',
            'number.unique' => 'Another phase of this year already has this number.',
            'name.required' => 'Name the phase, for example "Phase 1".',
            'name.unique' => 'Another phase of this year already uses this name.',
            'starts_on.required' => 'Enter the date the phase starts.',
            'ends_on.required' => 'Enter the date the phase ends.',
            'ends_on.after_or_equal' => 'The phase must end on or after its start date.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['starts_on' => 'start date', 'ends_on' => 'end date'];
    }

    /**
     * @return array{number: int, name: string, starts_on: string, ends_on: string}
     */
    public function phaseData(): array
    {
        return [
            'number' => (int) $this->validated('number'),
            'name' => (string) $this->validated('name'),
            'starts_on' => (string) $this->validated('starts_on'),
            'ends_on' => (string) $this->validated('ends_on'),
        ];
    }

    /** The academic year of the phase: its own when edited, the chosen one when added. */
    public function period(): AcademicPeriod
    {
        $phase = $this->phaseBeingEdited();

        return $phase !== null
            ? $phase->academicPeriod()->firstOrFail()
            : AcademicPeriod::query()->findOrFail((int) $this->validated('academic_period_id'));
    }

    private function phaseBeingEdited(): ?TrainingPhase
    {
        $phase = $this->route('trainingPhase');

        return $phase instanceof TrainingPhase ? $phase : null;
    }
}
