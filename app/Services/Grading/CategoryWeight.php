<?php

namespace App\Services\Grading;

/**
 * One category of a grading scheme, as input to the grade calculation.
 */
final readonly class CategoryWeight
{
    public function __construct(
        public int $id,
        public string $name,
        /** Percentage of the subject grade, 0 < weight <= 100. */
        public float $weight,
    ) {}
}
