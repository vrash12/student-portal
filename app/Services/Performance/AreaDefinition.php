<?php

namespace App\Services\Performance;

use App\Enums\PerformanceSource;
use App\Models\PerformanceArea;

/**
 * The rules of one performance area as QualificationEngine applies them:
 * a plain copy of the configured values, so the rules can be evaluated (and
 * tested) without the database.
 */
final readonly class AreaDefinition
{
    public function __construct(
        public int $id,
        public string $name,
        public PerformanceSource $source,
        /** Share in the overall score (0–100); 0 leaves the area out of the overall. */
        public float $weight,
        /** Grade needed to pass the area (0–100). */
        public float $passingGrade,
        public bool $mustPass,
        public ?string $description = null,
        /** Conduct areas only: rating = base + merit points × merit value − demerit points × demerit value. */
        public ?float $baseRating = null,
        public ?float $meritValue = null,
        public ?float $demeritValue = null,
    ) {}

    public static function fromModel(PerformanceArea $area): self
    {
        $conduct = $area->source === PerformanceSource::Conduct;

        return new self(
            id: $area->id,
            name: $area->name,
            source: $area->source,
            weight: (float) $area->weight,
            passingGrade: (float) $area->passing_grade,
            mustPass: $area->must_pass,
            description: $area->description,
            baseRating: $conduct ? (float) $area->base_rating : null,
            meritValue: $conduct ? (float) $area->merit_value : null,
            demeritValue: $conduct ? (float) $area->demerit_value : null,
        );
    }

    /**
     * @return array{id: int, name: string, description: ?string, source: array{value: string, label: string}, weight: float, passingGrade: float, mustPass: bool, conductRule: array{baseRating: float, meritValue: float, demeritValue: float}|null}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'source' => $this->source->toArray(),
            'weight' => $this->weight,
            'passingGrade' => $this->passingGrade,
            'mustPass' => $this->mustPass,
            'conductRule' => $this->source === PerformanceSource::Conduct ? [
                'baseRating' => (float) $this->baseRating,
                'meritValue' => (float) $this->meritValue,
                'demeritValue' => (float) $this->demeritValue,
            ] : null,
        ];
    }
}
