<?php

namespace Tests\Unit;

use App\Enums\AcademicStanding;
use App\Services\Grading\CategoryWeight;
use App\Services\Grading\CountedAssessment;
use App\Services\Grading\GradeCalculationService;
use App\Services\Grading\GradingThresholds;
use App\Services\Grading\SubjectGrade;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Deterministic tests for academic standing (Milestone 5): boundaries,
 * rounding, missing scores, provisional grades, and the overall rule.
 */
class AcademicStandingTest extends TestCase
{
    private GradeCalculationService $calculator;

    /** Passing grade 75.00, warning grade 80.00. */
    private GradingThresholds $thresholds;

    protected function setUp(): void
    {
        parent::setUp();

        $this->calculator = new GradeCalculationService;
        $this->thresholds = new GradingThresholds(7500, 8000);
    }

    /**
     * @return array<string, array{float, AcademicStanding}>
     */
    public static function boundaries(): array
    {
        return [
            'zero' => [0.0, AcademicStanding::Failing],
            'one hundredth below passing' => [74.99, AcademicStanding::Failing],
            'exactly passing' => [75.0, AcademicStanding::AtRisk],
            'between' => [77.5, AcademicStanding::AtRisk],
            'one hundredth below warning' => [79.99, AcademicStanding::AtRisk],
            'exactly warning' => [80.0, AcademicStanding::Passing],
            'perfect' => [100.0, AcademicStanding::Passing],
        ];
    }

    #[DataProvider('boundaries')]
    public function test_standing_boundaries(float $grade, AcademicStanding $expected): void
    {
        $this->assertSame($expected, $this->calculator->standing($grade, 0, $this->thresholds));
    }

    public function test_grades_are_compared_as_displayed_after_rounding(): void
    {
        // 74.995 is shown as 75.00, so it is Needs Improvement, not Failing.
        $this->assertSame(AcademicStanding::AtRisk, $this->calculator->standing(74.995, 0, $this->thresholds));
        // Binary noise just below a boundary does not change the result.
        $this->assertSame(AcademicStanding::Passing, $this->calculator->standing(79.99999999999, 0, $this->thresholds));
        $this->assertSame(AcademicStanding::AtRisk, $this->calculator->standing(79.994, 0, $this->thresholds));
    }

    public function test_equal_passing_and_warning_grades_leave_no_at_risk_range(): void
    {
        $thresholds = new GradingThresholds(7500, 7500);

        $this->assertSame(AcademicStanding::Failing, $this->calculator->standing(74.99, 0, $thresholds));
        $this->assertSame(AcademicStanding::Passing, $this->calculator->standing(75.0, 0, $thresholds));
    }

    public function test_missing_scores_turn_passing_into_incomplete_but_keep_warnings(): void
    {
        $this->assertSame(AcademicStanding::Incomplete, $this->calculator->standing(92.0, 1, $this->thresholds));
        // A missing record never hides an early warning.
        $this->assertSame(AcademicStanding::AtRisk, $this->calculator->standing(77.0, 2, $this->thresholds));
        $this->assertSame(AcademicStanding::Failing, $this->calculator->standing(60.0, 1, $this->thresholds));
    }

    public function test_no_grade_is_incomplete_only_when_scores_are_missing(): void
    {
        $this->assertSame(AcademicStanding::Incomplete, $this->calculator->standing(null, 3, $this->thresholds));
        $this->assertNull($this->calculator->standing(null, 0, $this->thresholds));
    }

    public function test_subject_grade_carries_the_standing_only_with_thresholds(): void
    {
        $categories = [new CategoryWeight(1, 'Quizzes', 40), new CategoryWeight(2, 'Examinations', 60)];
        $assessments = [new CountedAssessment(10, 1, 50), new CountedAssessment(20, 2, 100)];
        $scores = [10 => 39.0, 20 => 77.5];

        // Quizzes 78% x 40 + Examinations 77.5% x 60 = 77.70
        $withThresholds = $this->calculator->subjectGrade($categories, $assessments, $scores, $this->thresholds);
        $this->assertSame(77.7, $withThresholds->grade);
        $this->assertSame(AcademicStanding::AtRisk, $withThresholds->standing);
        $this->assertSame(['value' => 'at_risk', 'label' => 'Needs Improvement', 'tone' => 'warning'], $withThresholds->toArray()['standing']);

        $withoutThresholds = $this->calculator->subjectGrade($categories, $assessments, $scores, null);
        $this->assertSame(77.7, $withoutThresholds->grade);
        $this->assertNull($withoutThresholds->standing);
        $this->assertNull($withoutThresholds->toArray()['standing']);
    }

    public function test_a_provisional_grade_gets_a_current_standing_and_says_so(): void
    {
        $grade = $this->calculator->subjectGrade(
            [new CategoryWeight(1, 'Quizzes', 40), new CategoryWeight(2, 'Examinations', 60)],
            [new CountedAssessment(10, 1, 20)],
            [10 => 18.0],
            $this->thresholds,
        );

        // Only Quizzes is assessed (90%): Passing on 40% of the weight.
        $this->assertSame(90.0, $grade->grade);
        $this->assertSame(AcademicStanding::Passing, $grade->standing);
        $this->assertTrue($grade->isProvisional());
        $this->assertTrue($grade->toArray()['isProvisional']);
    }

    public function test_provisional_is_reported_even_when_scores_are_also_missing(): void
    {
        $grade = $this->calculator->subjectGrade(
            [new CategoryWeight(1, 'Quizzes', 40), new CategoryWeight(2, 'Examinations', 60)],
            [new CountedAssessment(10, 1, 20), new CountedAssessment(11, 1, 20)],
            [10 => 10.0],
            $this->thresholds,
        );

        $this->assertSame(50.0, $grade->grade);
        $this->assertSame(AcademicStanding::Failing, $grade->standing);
        $this->assertSame(1, $grade->missingScores);
        $this->assertTrue($grade->isProvisional());
    }

    public function test_an_unconfigured_subject_or_one_without_grades_has_no_standing(): void
    {
        $this->assertNull($this->calculator->subjectGrade([], [], [], $this->thresholds)->standing);
        $this->assertNull($this->calculator->subjectGrade([new CategoryWeight(1, 'Quizzes', 100)], [], [], $this->thresholds)->standing);
        $this->assertFalse($this->calculator->subjectGrade([new CategoryWeight(1, 'Quizzes', 100)], [], [], $this->thresholds)->isProvisional());
    }

    public function test_overall_standing_is_the_most_serious_subject_standing(): void
    {
        $overall = $this->calculator->overallStanding([
            $this->graded(AcademicStanding::Passing),
            $this->graded(AcademicStanding::Incomplete),
            $this->graded(AcademicStanding::AtRisk),
            $this->graded(null),
        ]);

        $this->assertSame(AcademicStanding::AtRisk, $overall->standing);
        $this->assertSame(3, $overall->basedOnSubjects);
        $this->assertSame(4, $overall->totalSubjects);
        $this->assertFalse($overall->isProvisional);

        $this->assertSame(AcademicStanding::Failing, $this->calculator->overallStanding([
            $this->graded(AcademicStanding::Failing),
            $this->graded(AcademicStanding::AtRisk),
        ])->standing);
        $this->assertSame(AcademicStanding::Incomplete, $this->calculator->overallStanding([
            $this->graded(AcademicStanding::Passing),
            $this->graded(AcademicStanding::Incomplete),
        ])->standing);
    }

    /**
     * AGENTS.md §17 shows 84.50 Passing, 72.40 Needs Improvement, 91.00 Passing, and
     * 68.20 Failing with an overall Needs Improvement, which an average could produce.
     * The implemented rule is "most serious subject" (Failing here), pending
     * the owner's confirmation. This test documents the difference.
     */
    public function test_the_documented_profile_example_gives_failing_under_the_most_serious_rule(): void
    {
        $thresholds = new GradingThresholds(7000, 8000);
        $standings = array_map(
            fn (float $grade) => $this->calculator->standing($grade, 0, $thresholds),
            [84.50, 72.40, 91.00, 68.20],
        );

        $this->assertSame(
            [AcademicStanding::Passing, AcademicStanding::AtRisk, AcademicStanding::Passing, AcademicStanding::Failing],
            $standings,
        );
        $this->assertSame(
            AcademicStanding::Failing,
            $this->calculator->overallStanding(array_map(fn (AcademicStanding $standing) => $this->graded($standing), $standings))->standing,
        );
    }

    public function test_overall_standing_without_any_subject_standing_is_empty(): void
    {
        $overall = $this->calculator->overallStanding([$this->graded(null), $this->graded(null)]);

        $this->assertNull($overall->standing);
        $this->assertSame(0, $overall->basedOnSubjects);
        $this->assertSame(2, $overall->totalSubjects);
        $this->assertSame(
            ['standing' => null, 'basedOnSubjects' => 0, 'totalSubjects' => 2, 'isProvisional' => false],
            $overall->toArray(),
        );
        $this->assertNull($this->calculator->overallStanding([])->standing);
    }

    public function test_overall_standing_reports_provisional_subjects(): void
    {
        $overall = $this->calculator->overallStanding([
            $this->graded(AcademicStanding::Passing),
            $this->graded(AcademicStanding::Passing, pendingCategories: 1),
            // A provisional subject without a standing does not count.
            $this->graded(null, pendingCategories: 1),
        ]);

        $this->assertSame(AcademicStanding::Passing, $overall->standing);
        $this->assertTrue($overall->isProvisional);
    }

    public function test_standing_severity_order(): void
    {
        $this->assertGreaterThan(AcademicStanding::AtRisk->severity(), AcademicStanding::Failing->severity());
        $this->assertGreaterThan(AcademicStanding::Incomplete->severity(), AcademicStanding::AtRisk->severity());
        $this->assertGreaterThan(AcademicStanding::Passing->severity(), AcademicStanding::Incomplete->severity());
    }

    /**
     * @return array<string, array{int, int}>
     */
    public static function invalidThresholds(): array
    {
        return [
            'zero passing' => [0, 8000],
            'warning below passing' => [8000, 7500],
            'warning above 100' => [7500, 10001],
        ];
    }

    #[DataProvider('invalidThresholds')]
    public function test_thresholds_must_be_ordered_and_in_range(int $passing, int $warning): void
    {
        $this->expectException(InvalidArgumentException::class);

        new GradingThresholds($passing, $warning);
    }

    public function test_thresholds_from_stored_values(): void
    {
        $thresholds = GradingThresholds::fromStored('75.00', '82.50');

        $this->assertNotNull($thresholds);
        $this->assertSame(7500, $thresholds->passingHundredths);
        $this->assertSame(8250, $thresholds->warningHundredths);
        $this->assertSame(['passingGrade' => 75.0, 'warningGrade' => 82.5], $thresholds->toArray());
        $this->assertNull(GradingThresholds::fromStored(null, null));
        $this->assertNull(GradingThresholds::fromStored('75.00', null));
    }

    private function graded(?AcademicStanding $standing, int $pendingCategories = 0): SubjectGrade
    {
        return new SubjectGrade(
            categories: [],
            grade: $standing === null && $pendingCategories === 0 ? null : 80.0,
            assessedWeight: 100.0,
            missingScores: 0,
            pendingCategories: $pendingCategories,
            standing: $standing,
        );
    }
}
