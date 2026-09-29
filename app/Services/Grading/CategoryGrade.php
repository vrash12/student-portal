<?php

namespace App\Services\Grading;

/**
 * A candidate's result in one grading category. Values are already rounded
 * for presentation; the subject grade is calculated from unrounded values.
 */
final readonly class CategoryGrade
{
    public function __construct(
        public int $categoryId,
        public string $name,
        public float $weight,
        /** Finalized assessments in the category. */
        public int $assessmentCount,
        /** Of those, how many the candidate has a score for. */
        public int $scoredCount,
        /** Raw points earned and possible over the scored assessments. */
        public float $earned,
        public float $possible,
        /** Category percentage (0–100), or null when nothing is scored yet. */
        public ?float $percentage,
        /** Contribution to the subject grade out of the category weight. */
        public ?float $weightedScore,
    ) {}

    /**
     * Finalized assessments in the category without a score for the candidate.
     */
    public function missingCount(): int
    {
        return $this->assessmentCount - $this->scoredCount;
    }

    /**
     * No finalized assessment exists in the category yet.
     */
    public function isPending(): bool
    {
        return $this->assessmentCount === 0;
    }

    /**
     * @return array{categoryId: int, name: string, weight: float, assessmentCount: int, scoredCount: int, missingCount: int, earned: float, possible: float, percentage: float|null, weightedScore: float|null}
     */
    public function toArray(): array
    {
        return [
            'categoryId' => $this->categoryId,
            'name' => $this->name,
            'weight' => $this->weight,
            'assessmentCount' => $this->assessmentCount,
            'scoredCount' => $this->scoredCount,
            'missingCount' => $this->missingCount(),
            'earned' => $this->earned,
            'possible' => $this->possible,
            'percentage' => $this->percentage,
            'weightedScore' => $this->weightedScore,
        ];
    }
}
