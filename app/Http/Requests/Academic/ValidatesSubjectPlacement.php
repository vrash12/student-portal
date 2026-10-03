<?php

namespace App\Http\Requests\Academic;

use App\Models\ClassBatch;
use App\Models\ClassSubject;
use App\Models\TrainingPhase;
use Illuminate\Validation\Rule;

/**
 * The training phase and units of a subject of a class (owner request,
 * 2026-10-03), shared by adding a subject and changing it later. The phase
 * must be one of the class's academic year.
 */
trait ValidatesSubjectPlacement
{
    public const MIN_UNITS = 0.1;

    public const MAX_UNITS = 50;

    /**
     * @return array<string, array<int, mixed>>
     */
    protected function placementRules(bool $unitsRequired): array
    {
        return [
            'training_phase_id' => ['nullable', 'integer', Rule::exists('training_phases', 'id')->where('academic_period_id', $this->placementClass()->academic_period_id)],
            'units' => [$unitsRequired ? 'required' : 'nullable', 'numeric', 'decimal:0,2', 'min:'.self::MIN_UNITS, 'max:'.self::MAX_UNITS],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function placementMessages(): array
    {
        $units = 'Enter the units as a number from '.self::MIN_UNITS.' to '.self::MAX_UNITS.', for example 3 or 1.5.';

        return [
            'training_phase_id.exists' => 'Select a training phase of the academic year of this class.',
            'units.required' => $units,
            'units.numeric' => $units,
            'units.decimal' => $units,
            'units.min' => $units,
            'units.max' => $units,
        ];
    }

    /** The class of the route (`classes/{classBatch}/subjects`). */
    private function placementClass(): ClassBatch
    {
        $classBatch = $this->route('classBatch');

        return $classBatch instanceof ClassBatch ? $classBatch : ClassBatch::query()->findOrFail((int) $classBatch);
    }

    public function phase(): ?TrainingPhase
    {
        $id = $this->validated('training_phase_id');

        return $id === null ? null : TrainingPhase::query()->findOrFail((int) $id);
    }

    public function units(): string
    {
        $units = $this->validated('units');

        return $units === null || $units === '' ? ClassSubject::DEFAULT_UNITS : (string) $units;
    }
}
