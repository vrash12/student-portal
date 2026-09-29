<?php

namespace App\Http\Requests\Academic;

use App\Models\AcademicPeriod;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Create or update an academic period. Authorization is enforced by the
 * `can:academic_periods.manage` route middleware.
 */
class AcademicPeriodRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['name' => trim((string) $this->input('name'))]);
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
