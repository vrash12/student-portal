<?php

namespace App\Services\Grading;

use App\Support\DecimalValue;

/**
 * A candidate's average in one training phase of their class: the
 * unit-weighted average of the phase's subject grades, produced only by
 * CourseRecordService (with GradeCalculationService::weightedAverage).
 */
final readonly class PhaseAverage
{
    /**
     * @param  array{id: int, number: int, name: string}|null  $phase  null for the subjects not in a phase
     * @param  list<array{classSubjectId: int, name: string, units: string, grade: float|null, complete: bool}>  $subjects
     */
    public function __construct(
        public ?array $phase,
        public array $subjects,
        /** Null while no subject of the phase has a grade. */
        public ?float $average,
    ) {}

    public function gradedSubjects(): int
    {
        return count(array_filter($this->subjects, fn (array $subject): bool => $subject['grade'] !== null));
    }

    /**
     * Every subject of the phase has a final grade: graded, every component
     * assessed and no score missing. Until then the average is a current one.
     */
    public function isComplete(): bool
    {
        return $this->subjects !== [] && array_filter($this->subjects, fn (array $subject): bool => ! $subject['complete']) === [];
    }

    /**
     * @return array{phase: array{id: int, number: int, name: string}|null, average: float|null, complete: bool, gradedSubjects: int, totalSubjects: int, units: string, subjects: list<array{classSubjectId: int, name: string, units: string}>}
     */
    public function toArray(): array
    {
        return [
            'phase' => $this->phase,
            'average' => $this->average,
            'complete' => $this->isComplete(),
            'gradedSubjects' => $this->gradedSubjects(),
            'totalSubjects' => count($this->subjects),
            'units' => DecimalValue::display(array_sum(array_map(fn (array $subject): float => (float) $subject['units'], $this->subjects))),
            'subjects' => array_map(fn (array $subject): array => [
                'classSubjectId' => $subject['classSubjectId'],
                'name' => $subject['name'],
                'units' => DecimalValue::display($subject['units']),
            ], $this->subjects),
        ];
    }
}
