<?php

namespace Tests\Unit;

use App\Enums\AttendanceStatus;
use App\Services\Attendance\AttendanceLedger;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The attendance rate rule must be deterministic (AGENTS.md §52):
 * (present + late) ÷ (present + late + absent) × 100, half up to two
 * decimals; excused and unrecorded sessions never enter it.
 */
class AttendanceRateTest extends TestCase
{
    /**
     * @return array<string, array{int, int, int, ?float}>
     */
    public static function rates(): array
    {
        return [
            'nothing counted yet' => [0, 0, 0, null],
            'always present' => [10, 0, 0, 100.0],
            'present and late both attend' => [5, 2, 0, 100.0],
            'never attended' => [0, 0, 3, 0.0],
            'nine of ten' => [8, 1, 1, 90.0],
            'two thirds rounds up' => [2, 0, 1, 66.67],
            'one third rounds down' => [1, 0, 2, 33.33],
            'one eighth' => [0, 1, 7, 12.5],
            'exact half of a hundredth rounds up' => [1, 0, 31, 3.13],
            'one in eight hundred rounds up' => [1, 0, 799, 0.13],
            'large class' => [397, 2, 1, 99.75],
        ];
    }

    #[DataProvider('rates')]
    public function test_the_rate_counts_present_and_late_over_every_session_with_a_decision(int $present, int $late, int $absent, ?float $expected): void
    {
        $this->assertSame($expected, AttendanceLedger::rate($present, $late, $absent));
    }

    public function test_only_present_and_late_count_as_attended(): void
    {
        $this->assertTrue(AttendanceStatus::Present->attended());
        $this->assertTrue(AttendanceStatus::Late->attended());
        $this->assertFalse(AttendanceStatus::Excused->attended());
        $this->assertFalse(AttendanceStatus::Absent->attended());
    }
}
