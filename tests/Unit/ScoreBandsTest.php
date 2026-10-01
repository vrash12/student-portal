<?php

namespace Tests\Unit;

use App\Support\ScoreBands;
use PHPUnit\Framework\TestCase;

class ScoreBandsTest extends TestCase
{
    public function test_values_are_counted_per_range_with_inclusive_lower_bounds(): void
    {
        $counted = ScoreBands::count([100, 90, 89.99, 80, 79.5, 60, 59.99, 0, null]);

        $this->assertSame([
            ['band' => '90–100', 'count' => 2],
            ['band' => '80–89.99', 'count' => 2],
            ['band' => '70–79.99', 'count' => 1],
            ['band' => '60–69.99', 'count' => 1],
            ['band' => 'Below 60', 'count' => 2],
            ['band' => 'No grade', 'count' => 1],
        ], $counted);
    }

    public function test_missing_values_can_be_left_out(): void
    {
        $counted = ScoreBands::count([null, 75.0], withMissing: false);

        $this->assertNotContains(ScoreBands::NO_SCORE, array_column($counted, 'band'));
        $this->assertSame(1, array_sum(array_column($counted, 'count')));
    }

    public function test_chart_columns_run_from_the_lowest_range_with_no_grade_last_and_muted(): void
    {
        $columns = ScoreBands::columns(ScoreBands::count([95, 65, null]));

        $this->assertSame(['Below 60', '60–69.99', '70–79.99', '80–89.99', '90–100', 'No grade'], array_column($columns, 'label'));
        $this->assertSame([0, 1, 0, 0, 1, 1], array_column($columns, 'value'));
        $this->assertSame([false, false, false, false, false, true], array_column($columns, 'muted'));
    }
}
