<?php

namespace App\Services\Performance;

use App\Enums\AreaStatus;

/**
 * A candidate's grade and status in one performance area, produced only by
 * QualificationEngine.
 */
final readonly class AreaResult
{
    public function __construct(
        public AreaDefinition $area,
        /** 0–100 with two decimals, or null when there is no grade. */
        public ?float $grade,
        public AreaStatus $status,
        /** A short plain-language explanation, e.g. which subject has missing scores. */
        public ?string $note = null,
    ) {}

    /**
     * The area itself is sent once per page (AreaDefinition::toArray), so
     * each result only names its area.
     *
     * @return array{areaId: int, grade: float|null, status: array{value: string, label: string, tone: string}, note: string|null}
     */
    public function toArray(): array
    {
        return [
            'areaId' => $this->area->id,
            'grade' => $this->grade,
            'status' => $this->status->toArray(),
            'note' => $this->note,
        ];
    }
}
