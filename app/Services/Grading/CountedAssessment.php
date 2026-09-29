<?php

namespace App\Services\Grading;

/**
 * An assessment that counts toward grades (a finalized one), as input to the
 * grade calculation.
 */
final readonly class CountedAssessment
{
    public function __construct(
        public int $id,
        public int $categoryId,
        public float $maxScore,
    ) {}
}
