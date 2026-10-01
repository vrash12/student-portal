<?php

namespace App\Http\Requests\Fitness;

use App\Enums\FitnessScoringMethod;
use App\Enums\FitnessUnit;
use App\Http\Requests\Concerns\NormalizesTextInput;
use App\Models\FitnessEvent;
use App\Services\Fitness\FitnessStandard;
use App\Services\Fitness\FitnessValue;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Create or update a fitness event, its standard (a points table, or
 * passing and maximum values) and its passing points. Authorization is
 * enforced by the `can:fitness.configure` route middleware.
 */
class FitnessEventRequest extends FormRequest
{
    use NormalizesTextInput;

    /** Rows of a points table. */
    public const MAXIMUM_ROWS = 200;

    private const POINTS_FORMAT = '/^\d{1,3}(\.\d{1,2})?$/';

    /** @var list<array{value: float, points: float}> */
    private array $table = [];

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $rows = $this->input('points_table');
        $this->merge([
            'name' => $this->trimmedInput('name'),
            'description' => $this->optionalInput('description'),
            'passing_points' => $this->trimmedInput('passing_points'),
            'passing_value' => $this->trimmedInput('passing_value'),
            'maximum_value' => $this->trimmedInput('maximum_value'),
            'points_table' => is_array($rows)
                ? array_map(fn (mixed $row): mixed => is_array($row) ? ['value' => self::text($row['value'] ?? null), 'points' => self::text($row['points'] ?? null)] : $row, $rows)
                : $rows,
        ]);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        /** @var FitnessEvent|null $event */
        $event = $this->route('fitnessEvent');
        $table = $this->input('scoring_method') === FitnessScoringMethod::Table->value;

        return [
            'name' => ['required', 'string', 'max:100', Rule::unique('fitness_events', 'name')->ignore($event?->id)],
            'description' => ['nullable', 'string', 'max:500'],
            'unit' => ['required', Rule::enum(FitnessUnit::class)],
            'higher_is_better' => ['required', 'boolean'],
            'scoring_method' => ['required', Rule::enum(FitnessScoringMethod::class)],
            'passing_points' => ['required', 'string', 'regex:'.self::POINTS_FORMAT],
            'passing_value' => $table ? ['nullable'] : ['required', 'string', 'max:12'],
            'maximum_value' => $table ? ['nullable'] : ['required', 'string', 'max:12'],
            'points_table' => $table ? ['required', 'array', 'min:1', 'max:'.self::MAXIMUM_ROWS] : ['nullable', 'array'],
            'points_table.*' => $table ? ['array'] : [],
            'points_table.*.value' => $table ? ['required', 'string', 'max:12'] : [],
            'points_table.*.points' => $table ? ['required', 'string', 'regex:'.self::POINTS_FORMAT] : [],
            'sort_order' => ['required', 'integer', 'min:0', 'max:999'],
            'is_active' => [$event === null ? 'prohibited' : 'required', 'boolean'],
        ];
    }

    /**
     * Results are read in the event's unit (minutes:seconds for times).
     * Scaled: the maximum must be better than the passing value. Table: each
     * result once, points from 0 to 100, better results never earning fewer
     * points, and at least one row reaching the passing points.
     *
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $unit = FitnessUnit::tryFrom((string) $this->input('unit'));
            $method = FitnessScoringMethod::tryFrom((string) $this->input('scoring_method'));
            if ($unit === null || $method === null || $validator->errors()->hasAny(['higher_is_better', 'passing_points'])) {
                return;
            }

            $passingPoints = (float) $this->input('passing_points');
            if ($passingPoints <= 0 || $passingPoints > FitnessStandard::MAXIMUM_POINTS) {
                $validator->errors()->add('passing_points', 'Enter passing points from 1 to 100.');

                return;
            }

            $method === FitnessScoringMethod::Table
                ? $this->validateTable($validator, $unit, $passingPoints)
                : $this->validateScaled($validator, $unit, $passingPoints);
        }];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.unique' => 'Another fitness event already uses this name.',
            'passing_points.required' => 'Enter the passing points.',
            'passing_points.regex' => 'Enter passing points from 1 to 100, with up to two decimals.',
            'passing_value.required' => 'Enter the passing standard.',
            'maximum_value.required' => 'Enter the maximum standard.',
            'points_table.required' => 'Add at least one row to the points table.',
            'points_table.min' => 'Add at least one row to the points table.',
            'points_table.max' => 'A points table can have up to '.self::MAXIMUM_ROWS.' rows.',
            'points_table.*.value.required' => 'Enter the result for this row.',
            'points_table.*.points.required' => 'Enter the points for this row.',
            'points_table.*.points.regex' => 'Enter points from 0 to 100, with up to two decimals.',
        ];
    }

    /**
     * @return array{name: string, description: ?string, unit: string, higher_is_better: bool, scoring_method: string, passing_points: float, passing_value: ?float, maximum_value: ?float, points_table: list<array{value: float, points: float}>|null, sort_order: int, is_active: bool}
     */
    public function eventData(): array
    {
        $unit = FitnessUnit::from((string) $this->validated('unit'));
        $method = FitnessScoringMethod::from((string) $this->validated('scoring_method'));
        $table = $method === FitnessScoringMethod::Table;

        return [
            'name' => (string) $this->validated('name'),
            'description' => $this->validated('description'),
            'unit' => $unit->value,
            'higher_is_better' => $this->boolean('higher_is_better'),
            'scoring_method' => $method->value,
            'passing_points' => (float) $this->validated('passing_points'),
            'passing_value' => $table ? null : (float) FitnessValue::parse((string) $this->validated('passing_value'), $unit),
            'maximum_value' => $table ? null : (float) FitnessValue::parse((string) $this->validated('maximum_value'), $unit),
            'points_table' => $table ? $this->table : null,
            'sort_order' => (int) $this->validated('sort_order'),
            'is_active' => $this->boolean('is_active', true),
        ];
    }

    private function validateScaled(Validator $validator, FitnessUnit $unit, float $passingPoints): void
    {
        if ($validator->errors()->hasAny(['passing_value', 'maximum_value'])) {
            return;
        }
        if ($passingPoints >= FitnessStandard::MAXIMUM_POINTS) {
            $validator->errors()->add('passing_points', 'The maximum standard earns 100 points, so the passing points must be below 100.');

            return;
        }

        $hint = $this->valueHint($unit);
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
    }

    private function validateTable(Validator $validator, FitnessUnit $unit, float $passingPoints): void
    {
        if ($validator->errors()->has('points_table') || collect($validator->errors()->keys())->contains(fn (string $key): bool => str_starts_with($key, 'points_table.'))) {
            return;
        }

        $hint = $this->valueHint($unit);
        $rows = [];
        $seen = [];
        foreach (array_values((array) $this->input('points_table')) as $index => $row) {
            $value = FitnessValue::parse((string) $row['value'], $unit);
            $points = (float) $row['points'];
            if ($value === null) {
                $validator->errors()->add("points_table.{$index}.value", $hint);

                continue;
            }
            if ($points > FitnessStandard::MAXIMUM_POINTS) {
                $validator->errors()->add("points_table.{$index}.points", 'Points cannot be more than 100.');

                continue;
            }
            $key = number_format($value, 2, '.', '');
            if (isset($seen[$key])) {
                $validator->errors()->add("points_table.{$index}.value", 'This result is already in row '.($seen[$key] + 1).'. List each result once.');

                continue;
            }
            $seen[$key] = $index;
            $rows[] = ['value' => $value, 'points' => $points, 'index' => $index];
        }
        if (count($rows) !== count((array) $this->input('points_table'))) {
            return;
        }

        $higherIsBetter = $this->boolean('higher_is_better');
        usort($rows, fn (array $a, array $b): int => $higherIsBetter ? $a['value'] <=> $b['value'] : $b['value'] <=> $a['value']);
        for ($i = 1; $i < count($rows); $i++) {
            if ($rows[$i]['points'] < $rows[$i - 1]['points']) {
                $better = FitnessValue::format($rows[$i]['value'], $unit);
                $weaker = FitnessValue::format($rows[$i - 1]['value'], $unit);
                $validator->errors()->add(
                    "points_table.{$rows[$i]['index']}.points",
                    "A better result cannot earn fewer points: {$better} would earn less than {$weaker}.",
                );

                return;
            }
        }

        if ($rows[count($rows) - 1]['points'] < $passingPoints) {
            $validator->errors()->add('passing_points', 'No row of the points table reaches the passing points. Lower the passing points or add a row that reaches them.');

            return;
        }

        $this->table = array_map(fn (array $row): array => ['value' => $row['value'], 'points' => $row['points']], $rows);
    }

    /** A table cell as trimmed text; anything other than text or a number is treated as empty. */
    private static function text(mixed $cell): string
    {
        return is_string($cell) || is_int($cell) || is_float($cell) ? trim((string) $cell) : '';
    }

    private function valueHint(FitnessUnit $unit): string
    {
        return $unit === FitnessUnit::Time ? 'Enter a time such as 12:30 (minutes:seconds).' : 'Enter a whole number of repetitions.';
    }
}
