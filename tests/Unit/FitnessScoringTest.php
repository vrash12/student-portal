<?php

namespace Tests\Unit;

use App\Enums\FitnessStatus;
use App\Enums\FitnessUnit;
use App\Services\Fitness\FitnessOutcome;
use App\Services\Fitness\FitnessStandard;
use App\Services\Fitness\FitnessValue;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The fitness scoring rule (FitnessStandard, FitnessOutcome) and value
 * parsing must be deterministic (AGENTS.md §52).
 */
class FitnessScoringTest extends TestCase
{
    /**
     * Push-ups: passing 40, maximum 60 repetitions.
     *
     * @return array<string, array{float, float, bool}>
     */
    public static function repetitions(): array
    {
        return [
            'at passing' => [40, 60.0, true],
            'half way' => [50, 80.0, true],
            'at maximum' => [60, 100.0, true],
            'beyond maximum' => [75, 100.0, true],
            'one short' => [39, 58.5, false],
            'half of passing' => [20, 30.0, false],
            'none' => [0, 0.0, false],
        ];
    }

    #[DataProvider('repetitions')]
    public function test_higher_is_better_events_scale_from_the_passing_to_the_maximum_standard(float $value, float $points, bool $passes): void
    {
        $standard = new FitnessStandard(FitnessUnit::Repetitions, true, 40, 60);

        $this->assertSame($points, $standard->points($value));
        $this->assertSame($passes, $standard->passes($value));
    }

    /**
     * A run: passing 15:00 (900 s), maximum 11:00 (660 s).
     *
     * @return array<string, array{float, float, bool}>
     */
    public static function times(): array
    {
        return [
            'at passing' => [900, 60.0, true],
            'half way' => [780, 80.0, true],
            'at maximum' => [660, 100.0, true],
            'faster than maximum' => [600, 100.0, true],
            'slower' => [1000, 54.0, false],
            'twice the passing time' => [1800, 30.0, false],
        ];
    }

    #[DataProvider('times')]
    public function test_lower_is_better_events_reward_faster_times(float $value, float $points, bool $passes): void
    {
        $standard = new FitnessStandard(FitnessUnit::Time, false, 900, 660);

        $this->assertSame($points, $standard->points($value));
        $this->assertSame($passes, $standard->passes($value));
    }

    public function test_the_outcome_fails_on_any_failed_event_and_passes_only_when_complete(): void
    {
        $passed = ['points' => 80.0, 'passed' => true];
        $failed = ['points' => 50.0, 'passed' => false];

        $this->assertEquals(new FitnessOutcome(FitnessStatus::Passed, 75.0), FitnessOutcome::of([$passed, ['points' => 70.0, 'passed' => true]]));
        $this->assertEquals(new FitnessOutcome(FitnessStatus::Failed, 65.0), FitnessOutcome::of([$passed, $failed]));
        // A failure is final even before every event is recorded; there is no overall score yet.
        $this->assertEquals(new FitnessOutcome(FitnessStatus::Failed, null), FitnessOutcome::of([$failed, null]));
        $this->assertEquals(new FitnessOutcome(FitnessStatus::Incomplete, null), FitnessOutcome::of([$passed, null]));
        $this->assertEquals(new FitnessOutcome(FitnessStatus::NotTested, null), FitnessOutcome::of([null, null]));
    }

    public function test_values_are_read_in_the_events_unit(): void
    {
        $this->assertSame(42.0, FitnessValue::parse(' 42 ', FitnessUnit::Repetitions));
        $this->assertNull(FitnessValue::parse('42.5', FitnessUnit::Repetitions));
        $this->assertNull(FitnessValue::parse('1001', FitnessUnit::Repetitions));
        $this->assertNull(FitnessValue::parse('-3', FitnessUnit::Repetitions));

        $this->assertSame(750.0, FitnessValue::parse('12:30', FitnessUnit::Time));
        $this->assertSame(545.5, FitnessValue::parse('9:05.5', FitnessUnit::Time));
        $this->assertSame(750.0, FitnessValue::parse('750', FitnessUnit::Time));
        $this->assertNull(FitnessValue::parse('12:75', FitnessUnit::Time));
        $this->assertNull(FitnessValue::parse('0:00', FitnessUnit::Time));
        $this->assertNull(FitnessValue::parse('abc', FitnessUnit::Time));
    }

    public function test_values_are_shown_in_the_events_unit(): void
    {
        $this->assertSame('42', FitnessValue::format(42, FitnessUnit::Repetitions));
        $this->assertSame('12:30', FitnessValue::format(750, FitnessUnit::Time));
        $this->assertSame('9:05.5', FitnessValue::format(545.5, FitnessUnit::Time));
        $this->assertSame('0:59', FitnessValue::format(59, FitnessUnit::Time));
    }
}
