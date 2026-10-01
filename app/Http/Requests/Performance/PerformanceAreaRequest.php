<?php

namespace App\Http\Requests\Performance;

use App\Enums\PerformanceSource;
use App\Http\Requests\Concerns\NormalizesTextInput;
use App\Models\PerformanceArea;
use App\Services\Performance\PerformanceAreaService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Create or update a performance area. Authorization is enforced by the
 * `can:performance.configure` route middleware. Source-specific fields are
 * accepted only for their source: subjects for subject areas, the conduct
 * rating values for conduct areas. PerformanceAreaService enforces the same
 * rules again under a lock.
 */
class PerformanceAreaRequest extends FormRequest
{
    use NormalizesTextInput;

    private const CONDUCT_FIELDS = ['base_rating' => 'base rating', 'merit_value' => 'value of a merit point', 'demerit_value' => 'value of a demerit point'];

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'name' => $this->trimmedInput('name'),
            'description' => $this->optionalInput('description'),
            'weight' => $this->trimmedInput('weight'),
            'passing_grade' => $this->trimmedInput('passing_grade'),
            'base_rating' => $this->optionalInput('base_rating'),
            'merit_value' => $this->optionalInput('merit_value'),
            'demerit_value' => $this->optionalInput('demerit_value'),
            'sort_order' => $this->trimmedInput('sort_order'),
        ]);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $source = PerformanceSource::tryFrom((string) $this->input('source'));
        $conduct = $source === PerformanceSource::Conduct;
        $holdsSubjects = $source === PerformanceSource::Subjects;

        $rules = [
            'name' => ['required', 'string', 'max:100', Rule::unique('performance_areas', 'name')->ignore($this->area()?->id)],
            'description' => ['nullable', 'string', 'max:255'],
            'source' => ['required', Rule::enum(PerformanceSource::class)],
            'weight' => ['required', 'numeric', 'decimal:0,2', 'min:0', 'max:100'],
            'passing_grade' => ['required', 'numeric', 'decimal:0,2', 'gt:0', 'max:100'],
            'must_pass' => ['required', 'boolean'],
            'sort_order' => ['required', 'integer', 'min:0', 'max:999'],
            'is_active' => ['required', 'boolean'],
            'subject_ids' => [Rule::prohibitedIf(! $holdsSubjects), 'nullable', 'array'],
            'subject_ids.*' => ['integer', 'distinct', Rule::exists('subjects', 'id')],
        ];

        foreach (array_keys(self::CONDUCT_FIELDS) as $field) {
            $rules[$field] = [Rule::requiredIf($conduct), Rule::prohibitedIf(! $conduct), 'nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:100'];
        }

        return $rules;
    }

    /**
     * At most one active fitness, conduct or attendance area.
     *
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $source = PerformanceSource::tryFrom((string) $this->input('source'));
            if ($source === null || ! $this->boolean('is_active') || $validator->errors()->hasAny(['source', 'is_active'])) {
                return;
            }

            $other = app(PerformanceAreaService::class)->activeAreaUsing($source, $this->area()?->id);
            if ($other !== null) {
                $field = $this->area() !== null && $this->area()->source === $source ? 'is_active' : 'source';
                $validator->errors()->add($field, "{$other->name} is already the active {$source->label()} area. Only one can be active: deactivate it first, or save this area as inactive.");
            }
        }];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        $messages = [
            'name.required' => 'Enter the name of the area.',
            'name.unique' => 'Another performance area already uses this name.',
            'source.required' => 'Choose where the area takes its grade from.',
            'source.enum' => 'Choose where the area takes its grade from.',
            'weight.required' => 'Enter the weight of the area in the overall score.',
            'weight.numeric' => 'Enter the weight as a number from 0 to 100, for example 40.',
            'weight.decimal' => 'Use at most two decimal places.',
            'weight.min' => 'Enter the weight as a number from 0 to 100, for example 40.',
            'weight.max' => 'Enter the weight as a number from 0 to 100, for example 40.',
            'passing_grade.required' => 'Enter the passing grade.',
            'passing_grade.numeric' => 'Enter the passing grade as a number, for example 75.',
            'passing_grade.decimal' => 'Use at most two decimal places.',
            'passing_grade.gt' => 'Enter a passing grade greater than 0.',
            'passing_grade.max' => 'A passing grade cannot be more than 100.',
            'sort_order.required' => 'Enter the position in lists.',
            'sort_order.integer' => 'Enter the position as a whole number from 0 to 999.',
            'sort_order.min' => 'Enter the position as a whole number from 0 to 999.',
            'sort_order.max' => 'Enter the position as a whole number from 0 to 999.',
            'subject_ids.prohibited' => 'Only areas based on subject grades can hold subjects.',
            'subject_ids.array' => 'Choose the subjects from the list.',
            'subject_ids.*.integer' => 'Choose the subjects from the list.',
            'subject_ids.*.distinct' => 'Choose each subject only once.',
            'subject_ids.*.exists' => 'One of the selected subjects no longer exists. Reload the page and try again.',
        ];

        foreach (self::CONDUCT_FIELDS as $field => $name) {
            $messages += [
                "{$field}.required" => "Enter the {$name}.",
                "{$field}.prohibited" => 'The conduct rating values apply to conduct areas only.',
                "{$field}.numeric" => "Enter the {$name} as a number from 0 to 100.",
                "{$field}.decimal" => 'Use at most two decimal places.',
                "{$field}.min" => "Enter the {$name} as a number from 0 to 100.",
                "{$field}.max" => "Enter the {$name} as a number from 0 to 100.",
            ];
        }

        return $messages;
    }

    /**
     * @return array{name: string, description: ?string, source: string, weight: string, passing_grade: string, must_pass: bool, base_rating: ?string, merit_value: ?string, demerit_value: ?string, sort_order: int, is_active: bool}
     */
    public function areaData(): array
    {
        $conduct = $this->validated('source') === PerformanceSource::Conduct->value;

        return [
            'name' => (string) $this->validated('name'),
            'description' => $this->validated('description'),
            'source' => (string) $this->validated('source'),
            'weight' => (string) $this->validated('weight'),
            'passing_grade' => (string) $this->validated('passing_grade'),
            'must_pass' => $this->boolean('must_pass'),
            'base_rating' => $conduct ? (string) $this->validated('base_rating') : null,
            'merit_value' => $conduct ? (string) $this->validated('merit_value') : null,
            'demerit_value' => $conduct ? (string) $this->validated('demerit_value') : null,
            'sort_order' => (int) $this->validated('sort_order'),
            'is_active' => $this->boolean('is_active'),
        ];
    }

    /**
     * @return list<int>
     */
    public function subjectIds(): array
    {
        return array_values(array_map('intval', (array) ($this->validated('subject_ids') ?? [])));
    }

    private function area(): ?PerformanceArea
    {
        $area = $this->route('performanceArea');

        return $area instanceof PerformanceArea ? $area : null;
    }
}
