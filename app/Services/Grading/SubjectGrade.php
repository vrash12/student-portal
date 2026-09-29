<?php

namespace App\Services\Grading;

use App\Enums\AcademicStanding;
use App\Enums\GradeStatus;

/**
 * A candidate's calculated grade and academic standing in one subject of
 * their class, produced only by GradeCalculationService.
 */
final readonly class SubjectGrade
{
    /**
     * @param  list<CategoryGrade>  $categories
     */
    public function __construct(
        public array $categories,
        /**
         * Current grade on a 0–100 scale over the categories assessed so far,
         * or null when nothing is scored. Equals the final grade once the
         * status is Complete.
         */
        public ?float $grade,
        /** Sum of the weights of the categories the grade is based on. */
        public float $assessedWeight,
        /** Finalized assessments the candidate has no score for. */
        public int $missingScores,
        /** Categories without any finalized assessment yet. */
        public int $pendingCategories,
        /**
         * Null when the period has no passing and warning grades, when the
         * candidate cannot be graded in the class (withdrawn), or when there
         * is nothing to judge yet (no scores and none missing).
         */
        public ?AcademicStanding $standing,
    ) {}

    /**
     * The grade exists but some grading categories have no finalized
     * assessment yet, so it (and its standing) is a current, not final,
     * result. Independent of missing scores, which GradeStatus reports first.
     */
    public function isProvisional(): bool
    {
        return $this->grade !== null && $this->pendingCategories > 0;
    }

    public function status(): GradeStatus
    {
        return match (true) {
            $this->categories === [] => GradeStatus::NotConfigured,
            $this->missingScores > 0 => GradeStatus::MissingScores,
            $this->grade === null => GradeStatus::NoGrades,
            $this->pendingCategories > 0 => GradeStatus::Provisional,
            default => GradeStatus::Complete,
        };
    }

    /**
     * @return array{grade: float|null, assessedWeight: float, missingScores: int, pendingCategories: int, isProvisional: bool, status: array{value: string, label: string, tone: string}, standing: array{value: string, label: string, tone: string}|null, categories: list<array<string, mixed>>}
     */
    public function toArray(): array
    {
        return [
            'grade' => $this->grade,
            'assessedWeight' => $this->assessedWeight,
            'missingScores' => $this->missingScores,
            'pendingCategories' => $this->pendingCategories,
            'isProvisional' => $this->isProvisional(),
            'status' => $this->status()->toArray(),
            'standing' => $this->standing?->toArray(),
            'categories' => array_map(fn (CategoryGrade $category): array => $category->toArray(), $this->categories),
        ];
    }
}
