<?php

namespace App\Services\Grading;

use App\Enums\AcademicStanding;

/**
 * A candidate's standing across a set of subjects (all subjects of their
 * class, or only the ones a viewer teaches), produced only by
 * GradeCalculationService::overallStanding().
 */
final readonly class OverallStanding
{
    public function __construct(
        /** The most serious subject standing; null when no subject has one. */
        public ?AcademicStanding $standing,
        /** Subjects that have a standing, which the overall standing is based on. */
        public int $basedOnSubjects,
        /** Subjects considered. */
        public int $totalSubjects,
        /** At least one of those standings rests on a provisional grade. */
        public bool $isProvisional,
    ) {}

    /**
     * @return array{standing: array{value: string, label: string, tone: string}|null, basedOnSubjects: int, totalSubjects: int, isProvisional: bool}
     */
    public function toArray(): array
    {
        return [
            'standing' => $this->standing?->toArray(),
            'basedOnSubjects' => $this->basedOnSubjects,
            'totalSubjects' => $this->totalSubjects,
            'isProvisional' => $this->isProvisional,
        ];
    }
}
