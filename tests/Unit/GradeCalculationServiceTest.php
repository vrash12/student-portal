<?php

namespace Tests\Unit;

use App\Enums\GradeStatus;
use App\Services\Grading\CategoryWeight;
use App\Services\Grading\CountedAssessment;
use App\Services\Grading\GradeCalculationService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Deterministic tests for the authoritative grade calculation (AGENTS.md §52).
 */
class GradeCalculationServiceTest extends TestCase
{
    private GradeCalculationService $calculator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->calculator = new GradeCalculationService;
    }

    public function test_weighted_grade_matches_the_documented_example(): void
    {
        // AGENTS.md §52: Quiz 90 x 20%, Midterm 80 x 30%, Practical 95 x 50%.
        $grade = $this->calculator->subjectGrade(
            [new CategoryWeight(1, 'Quiz', 20), new CategoryWeight(2, 'Midterm', 30), new CategoryWeight(3, 'Practical', 50)],
            [new CountedAssessment(10, 1, 100), new CountedAssessment(20, 2, 100), new CountedAssessment(30, 3, 100)],
            [10 => 90.0, 20 => 80.0, 30 => 95.0],
        );

        // 18.00 + 24.00 + 47.50
        $this->assertSame(89.5, $grade->grade);
        $this->assertSame([18.0, 24.0, 47.5], array_map(fn ($category) => $category->weightedScore, $grade->categories));
        $this->assertSame(100.0, $grade->assessedWeight);
        $this->assertSame(GradeStatus::Complete, $grade->status());
    }

    public function test_category_percentage_is_points_earned_over_points_possible(): void
    {
        // Quiz 1: 18 / 20, Quiz 2: 20 / 25  =>  38 / 45 = 84.444...%
        $grade = $this->calculator->subjectGrade(
            [new CategoryWeight(1, 'Quizzes', 40), new CategoryWeight(2, 'Examinations', 60)],
            [new CountedAssessment(10, 1, 20), new CountedAssessment(11, 1, 25), new CountedAssessment(20, 2, 100)],
            [10 => 18.0, 11 => 20.0, 20 => 70.0],
        );

        $quizzes = $grade->categories[0];
        $this->assertSame(38.0, $quizzes->earned);
        $this->assertSame(45.0, $quizzes->possible);
        $this->assertSame(84.44, $quizzes->percentage);
        $this->assertSame(33.78, $quizzes->weightedScore);

        // 84.444...% x 40% + 70% x 60% = 33.777... + 42 = 75.777... (from unrounded values)
        $this->assertSame(75.78, $grade->grade);
    }

    public function test_missing_scores_are_reported_and_never_treated_as_zero(): void
    {
        $grade = $this->calculator->subjectGrade(
            [new CategoryWeight(1, 'Quizzes', 50), new CategoryWeight(2, 'Examinations', 50)],
            [new CountedAssessment(10, 1, 10), new CountedAssessment(11, 1, 10), new CountedAssessment(20, 2, 100)],
            [10 => 8.0, 20 => 90.0],
        );

        // Quiz 2 is missing: Quizzes stays 8/10 = 80%, not 8/20 = 40%.
        $this->assertSame(80.0, $grade->categories[0]->percentage);
        $this->assertSame(1, $grade->categories[0]->missingCount());
        $this->assertSame(1, $grade->missingScores);
        $this->assertSame(85.0, $grade->grade);
        $this->assertSame(GradeStatus::MissingScores, $grade->status());
    }

    public function test_a_zero_score_counts_as_a_score(): void
    {
        $grade = $this->calculator->subjectGrade(
            [new CategoryWeight(1, 'Quizzes', 100)],
            [new CountedAssessment(10, 1, 10), new CountedAssessment(11, 1, 10)],
            [10 => 0.0, 11 => 10.0],
        );

        $this->assertSame(50.0, $grade->grade);
        $this->assertSame(0, $grade->missingScores);
        $this->assertSame(GradeStatus::Complete, $grade->status());
    }

    public function test_grade_before_every_category_is_assessed_is_based_on_the_assessed_weight(): void
    {
        $grade = $this->calculator->subjectGrade(
            [
                new CategoryWeight(1, 'Quiz', 20),
                new CategoryWeight(2, 'Examination', 30),
                new CategoryWeight(3, 'Practical', 30),
                new CategoryWeight(4, 'Other Requirements', 20),
            ],
            [new CountedAssessment(10, 1, 50), new CountedAssessment(30, 3, 100)],
            [10 => 45.0, 30 => 70.0],
        );

        // (90% x 20 + 70% x 30) / 50 = (18 + 21) / 50 = 78.00
        $this->assertSame(78.0, $grade->grade);
        $this->assertSame(50.0, $grade->assessedWeight);
        $this->assertSame(2, $grade->pendingCategories);
        $this->assertTrue($grade->categories[1]->isPending());
        $this->assertNull($grade->categories[1]->percentage);
        $this->assertSame(GradeStatus::Provisional, $grade->status());
    }

    public function test_no_finalized_assessments_means_no_grade(): void
    {
        $grade = $this->calculator->subjectGrade([new CategoryWeight(1, 'Quizzes', 100)], [], []);

        $this->assertNull($grade->grade);
        $this->assertSame(0.0, $grade->assessedWeight);
        $this->assertSame(GradeStatus::NoGrades, $grade->status());
    }

    public function test_an_unconfigured_subject_has_no_grade(): void
    {
        $grade = $this->calculator->subjectGrade([], [new CountedAssessment(10, 1, 10)], [10 => 5.0]);

        $this->assertNull($grade->grade);
        $this->assertSame([], $grade->categories);
        $this->assertSame(GradeStatus::NotConfigured, $grade->status());
    }

    public function test_only_missing_scores_on_every_assessment_gives_no_grade_but_reports_them(): void
    {
        $grade = $this->calculator->subjectGrade(
            [new CategoryWeight(1, 'Quizzes', 100)],
            [new CountedAssessment(10, 1, 10)],
            [10 => null],
        );

        $this->assertNull($grade->grade);
        $this->assertSame(1, $grade->missingScores);
        $this->assertSame(GradeStatus::MissingScores, $grade->status());
    }

    public function test_results_round_half_up_despite_floating_point_noise(): void
    {
        // (84.49 x 50 + 84.50 x 50) / 100 = 84.495 exactly, which binary
        // floating point stores slightly below .495. It must round to 84.50.
        $grade = $this->calculator->subjectGrade(
            [new CategoryWeight(1, 'A', 50), new CategoryWeight(2, 'B', 50)],
            [new CountedAssessment(10, 1, 100), new CountedAssessment(20, 2, 100)],
            [10 => 84.49, 20 => 84.5],
        );

        $this->assertSame(84.5, $grade->grade);
    }

    public function test_the_same_inputs_always_produce_the_same_grade(): void
    {
        $inputs = [
            [new CategoryWeight(1, 'Quizzes', 33.33), new CategoryWeight(2, 'Examinations', 66.67)],
            [new CountedAssessment(10, 1, 7), new CountedAssessment(11, 1, 9), new CountedAssessment(20, 2, 60)],
            [10 => 5.5, 11 => 8.25, 20 => 41.75],
        ];

        $first = $this->calculator->subjectGrade(...$inputs);

        for ($run = 0; $run < 5; $run++) {
            $this->assertEquals($first, $this->calculator->subjectGrade(...$inputs));
        }
        // 13.75 / 16 = 85.9375% x 33.33 + 69.5833...% x 66.67 = 28.6427 + 46.3912 = 75.03
        $this->assertSame(75.03, $first->grade);
    }

    public function test_categories_keep_the_scheme_order_and_ignore_other_categories(): void
    {
        $grade = $this->calculator->subjectGrade(
            [new CategoryWeight(2, 'Second', 50), new CategoryWeight(1, 'First', 50)],
            [new CountedAssessment(10, 1, 10), new CountedAssessment(99, 99, 10)],
            [10 => 10.0, 99 => 0.0],
        );

        $this->assertSame(['Second', 'First'], array_map(fn ($category) => $category->name, $grade->categories));
        // The assessment in an unknown category is not part of this scheme.
        $this->assertSame(0, $grade->missingScores);
        $this->assertSame(100.0, $grade->grade);
    }

    /**
     * @return array<string, array{float, float, float}>
     */
    public static function scorePercentages(): array
    {
        return [
            'exact' => [45, 50, 90.0],
            'perfect' => [20, 20, 100.0],
            'zero' => [0, 20, 0.0],
            'repeating down' => [1, 3, 33.33],
            'repeating up' => [2, 3, 66.67],
            'decimal score' => [17.5, 40, 43.75],
        ];
    }

    #[DataProvider('scorePercentages')]
    public function test_score_percentage(float $score, float $maxScore, float $expected): void
    {
        $this->assertSame($expected, $this->calculator->scorePercentage($score, $maxScore));
    }
}
