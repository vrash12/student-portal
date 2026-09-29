<?php

namespace App\Services\Grading;

use App\Models\AcademicPeriod;
use App\Support\DecimalValue;
use InvalidArgumentException;

/**
 * The passing and warning grades of an academic period, as input to the
 * academic standing calculation. Kept in whole hundredths so boundary
 * comparisons are exact (75.00 is 7500).
 */
final readonly class GradingThresholds
{
    public function __construct(
        /** Grades below this are Failing. */
        public int $passingHundredths,
        /** Grades at or above the passing grade but below this are At Risk. */
        public int $warningHundredths,
    ) {
        if ($passingHundredths <= 0 || $passingHundredths > $warningHundredths || $warningHundredths > 10000) {
            throw new InvalidArgumentException('Thresholds must satisfy 0 < passing <= warning <= 100.');
        }
    }

    /**
     * The period's thresholds, or null when they are not set up.
     */
    public static function forPeriod(AcademicPeriod $period): ?self
    {
        return self::fromStored($period->passing_grade, $period->warning_grade);
    }

    /**
     * From stored DECIMAL values; null unless both are set.
     */
    public static function fromStored(string|int|float|null $passing, string|int|float|null $warning): ?self
    {
        if ($passing === null || $warning === null) {
            return null;
        }

        return new self(DecimalValue::toHundredths($passing), DecimalValue::toHundredths($warning));
    }

    public function passingGrade(): float
    {
        return $this->passingHundredths / 100;
    }

    public function warningGrade(): float
    {
        return $this->warningHundredths / 100;
    }

    /**
     * @return array{passingGrade: float, warningGrade: float}
     */
    public function toArray(): array
    {
        return ['passingGrade' => $this->passingGrade(), 'warningGrade' => $this->warningGrade()];
    }
}
