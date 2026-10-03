<?php

namespace App\Http\Requests\Academic;

use App\Http\Requests\Concerns\NormalizesTextInput;
use App\Models\AcademicPeriod;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Create or update an academic period: one year of the course at most
 * (owner request, 2026-10-03), still holding all of its training phases
 * when its dates change. Authorization is enforced by the
 * `can:academic_periods.manage` route middleware.
 */
class AcademicPeriodRequest extends FormRequest
{
    use NormalizesTextInput;

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['name' => $this->trimmedInput('name')]);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        /** @var AcademicPeriod|null $period */
        $period = $this->route('academicPeriod');

        return [
            'name' => ['required', 'string', 'max:100', Rule::unique('academic_periods', 'name')->ignore($period)],
            'starts_on' => ['required', 'date_format:Y-m-d'],
            'ends_on' => ['required', 'date_format:Y-m-d', 'after_or_equal:starts_on'],
        ];
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $startsOn = CarbonImmutable::parse($this->validated('starts_on'));
            $endsOn = CarbonImmutable::parse($this->validated('ends_on'));
            $lastEnd = AcademicPeriod::lastAllowedEnd($startsOn);
            if ($endsOn->gt($lastEnd)) {
                $validator->errors()->add('ends_on', 'The course lasts one year: end the academic year on or before '.$lastEnd->format('M j, Y').'.');

                return;
            }

            /** @var AcademicPeriod|null $period */
            $period = $this->route('academicPeriod');
            $first = $period?->trainingPhases()->orderBy('starts_on')->first();
            $last = $period?->trainingPhases()->orderByDesc('ends_on')->first();
            if ($first !== null && $startsOn->gt($first->starts_on)) {
                $validator->errors()->add('starts_on', "{$first->name} starts on ".$first->starts_on->format('M j, Y').'. Start the year on or before that day, or change the phase first.');
            }
            if ($last !== null && $endsOn->lt($last->ends_on)) {
                $validator->errors()->add('ends_on', "{$last->name} ends on ".$last->ends_on->format('M j, Y').'. End the year on or after that day, or change the phase first.');
            }
        }];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.unique' => 'Another academic period already uses this name.',
            'ends_on.after_or_equal' => 'The end date must be on or after the start date.',
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
     * @return array{name: string, starts_on: string, ends_on: string}
     */
    public function periodData(): array
    {
        return [
            'name' => $this->string('name')->value(),
            'starts_on' => $this->string('starts_on')->value(),
            'ends_on' => $this->string('ends_on')->value(),
        ];
    }
}
