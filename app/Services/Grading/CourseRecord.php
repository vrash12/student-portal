<?php

namespace App\Services\Grading;

/**
 * A candidate's grades for the course of their class, by training phase
 * (owner request, 2026-10-03): each phase's average and the Cumulative
 * General Point Average (CGPA), the unit-weighted average of every subject
 * grade of the class so far. Produced only by CourseRecordService.
 */
final readonly class CourseRecord
{
    /**
     * @param  list<PhaseAverage>  $phases  in phase order; the subjects not in a phase last
     */
    public function __construct(
        public array $phases,
        /** Null while no subject of the class has a grade. */
        public ?float $cgpa,
    ) {}

    public function totalSubjects(): int
    {
        return array_sum(array_map(fn (PhaseAverage $phase): int => count($phase->subjects), $this->phases));
    }

    public function gradedSubjects(): int
    {
        return array_sum(array_map(fn (PhaseAverage $phase): int => $phase->gradedSubjects(), $this->phases));
    }

    /** Every subject of the class has a final grade. */
    public function isComplete(): bool
    {
        return $this->phases !== [] && array_filter($this->phases, fn (PhaseAverage $phase): bool => ! $phase->isComplete()) === [];
    }

    /**
     * @return array{grade: float|null, complete: bool, gradedSubjects: int, totalSubjects: int}
     */
    public function cgpaToArray(): array
    {
        return [
            'grade' => $this->cgpa,
            'complete' => $this->isComplete(),
            'gradedSubjects' => $this->gradedSubjects(),
            'totalSubjects' => $this->totalSubjects(),
        ];
    }

    /**
     * @return array{phases: list<array<string, mixed>>, cgpa: array{grade: float|null, complete: bool, gradedSubjects: int, totalSubjects: int}}
     */
    public function toArray(): array
    {
        return [
            'phases' => array_map(fn (PhaseAverage $phase): array => $phase->toArray(), $this->phases),
            'cgpa' => $this->cgpaToArray(),
        ];
    }
}
