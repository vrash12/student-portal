<?php

namespace App\Services\Monitoring;

use App\Models\Candidate;
use App\Models\ClassSubject;
use App\Services\Grading\OverallStanding;
use App\Services\Grading\SubjectGrade;

/**
 * One monitored candidate: their results in the class subjects of the
 * monitoring scope and the overall standing over exactly those subjects.
 * All grades and standings come from GradeCalculationService.
 */
final readonly class MonitoredCandidate
{
    /**
     * @param  list<array{offering: ClassSubject, grade: SubjectGrade}>  $subjects  ordered by subject name
     */
    public function __construct(
        public Candidate $candidate,
        public array $subjects,
        public OverallStanding $overall,
    ) {}

    /**
     * The overall standing's value, or "none" when there is no standing yet.
     */
    public function standingKey(): string
    {
        return $this->overall->standing?->value ?? 'none';
    }

    /**
     * Higher is more serious; no standing ranks below Passing.
     */
    public function severity(): int
    {
        return $this->overall->standing?->severity() ?? -1;
    }

    /**
     * The lowest subject grade, the performance measure used for sorting.
     *
     * @return array{offering: ClassSubject, grade: SubjectGrade}|null
     */
    public function lowest(): ?array
    {
        $lowest = null;
        foreach ($this->subjects as $subject) {
            $grade = $subject['grade']->grade;
            if ($grade !== null && ($lowest === null || $grade < $lowest['grade']->grade)) {
                $lowest = $subject;
            }
        }

        return $lowest;
    }

    public function lowestGrade(): ?float
    {
        return $this->lowest()['grade']->grade ?? null;
    }

    public function missingScores(): int
    {
        return array_sum(array_map(fn (array $subject): int => $subject['grade']->missingScores, $this->subjects));
    }

    /**
     * @return list<array{offering: ClassSubject, grade: SubjectGrade}>
     */
    public function concerns(): array
    {
        return SubjectConcerns::of($this->subjects);
    }
}
