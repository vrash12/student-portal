<?php

namespace Tests\Feature\Grading;

use App\Enums\AcademicStanding;
use App\Enums\CandidateStatus;
use App\Models\AcademicPeriod;
use App\Models\Assessment;
use App\Models\AssessmentCategory;
use App\Models\Candidate;
use App\Models\ClassBatch;
use App\Models\ClassSubject;
use App\Models\Subject;
use App\Models\User;
use App\Services\Grading\GradeCalculationService;
use App\Services\Grading\GradingThresholds;
use App\Services\Grading\GradingThresholdService;
use ArrayObject;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Milestone 5: academic standing as GradeCalculationService decides it with
 * the real database (stored scores, period thresholds, class membership),
 * the query budget of the batched calculations and the pages that use them,
 * and the row lock taken when thresholds are saved.
 *
 * The pure standing rules are covered by tests/Unit/AcademicStandingTest.php.
 */
class StandingEngineDatabaseTest extends TestCase
{
    use BuildsGradingFixtures;

    private const SETUP_REASON = 'Synthetic thresholds for automated tests.';

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildGradingFixtures();
    }

    // ------------------------------------------------------------------
    // forOffering()
    // ------------------------------------------------------------------

    public function test_for_offering_uses_the_thresholds_of_the_class_own_period(): void
    {
        $this->setThresholds($this->activePeriod, '75', '80');
        $this->setThresholds($this->pastPeriod, '60', '65');

        // The same 70% in both subjects.
        $this->finalizedAssessment($this->quizzes, 'Quiz 1', '20', [
            $this->candidateInA->id => '14',
            $this->secondInA->id => '18',
        ]);

        $oldOffering = $this->oldOffering();
        $this->setScheme($oldOffering, ['Quizzes' => '100']);
        $this->finalizedAssessment($this->category($oldOffering, 'Quizzes'), 'Old Quiz 1', '20', [
            $this->oldCandidate()->id => '14',
        ]);

        $current = $this->calculator()->forOffering($this->fresh($this->offeringA1), [$this->candidateInA->id, $this->secondInA->id]);
        $this->assertSame(70.0, $current[$this->candidateInA->id]->grade);
        // Below the active period's passing grade of 75.
        $this->assertSame(AcademicStanding::Failing, $current[$this->candidateInA->id]->standing);
        $this->assertSame(90.0, $current[$this->secondInA->id]->grade);
        $this->assertSame(AcademicStanding::Passing, $current[$this->secondInA->id]->standing);

        $past = $this->calculator()->forOffering($this->fresh($oldOffering), [$this->oldCandidate()->id]);
        $this->assertSame(70.0, $past[$this->oldCandidate()->id]->grade);
        // At or above the past period's warning grade of 65; a completed
        // candidate is still monitored in their own class.
        $this->assertSame(AcademicStanding::Passing, $past[$this->oldCandidate()->id]->standing);
    }

    public function test_for_offering_gives_no_standing_when_only_another_period_has_thresholds(): void
    {
        // Thresholds of the past period must not leak into the active one.
        $this->setThresholds($this->pastPeriod, '60', '65');
        $this->finalizedAssessment($this->quizzes, 'Quiz 1', '20', [$this->candidateInA->id => '14']);

        $grades = $this->calculator()->forOffering($this->fresh($this->offeringA1), [$this->candidateInA->id, $this->secondInA->id]);

        $this->assertSame(70.0, $grades[$this->candidateInA->id]->grade);
        $this->assertNull($grades[$this->candidateInA->id]->standing);
        // A missing score would be Incomplete with thresholds; without them
        // there is no standing at all.
        $this->assertSame(1, $grades[$this->secondInA->id]->missingScores);
        $this->assertNull($grades[$this->secondInA->id]->standing);
        $this->assertNull($grades[$this->secondInA->id]->toArray()['standing']);
    }

    public function test_withdrawn_candidate_keeps_the_grade_but_gets_no_standing_in_for_offering(): void
    {
        $this->setThresholds($this->activePeriod, '75', '80');
        $this->finalizedAssessment($this->quizzes, 'Quiz 1', '20', [
            $this->candidateInA->id => '18',
            $this->secondInA->id => '12',
        ]);
        $this->finalizedAssessment($this->examinations, 'Midterm Examination', '100', [$this->candidateInA->id => '90']);

        $this->secondInA->forceFill(['status' => CandidateStatus::Withdrawn->value])->save();

        $grades = $this->calculator()->forOffering($this->fresh($this->offeringA1), [$this->candidateInA->id, $this->secondInA->id]);

        $this->assertSame(90.0, $grades[$this->candidateInA->id]->grade);
        $this->assertSame(AcademicStanding::Passing, $grades[$this->candidateInA->id]->standing);

        // 12/20 = 60% would be Failing (with a missing examination score) if
        // the candidate were still monitored.
        $withdrawn = $grades[$this->secondInA->id];
        $this->assertSame(60.0, $withdrawn->grade);
        $this->assertSame(1, $withdrawn->missingScores);
        $this->assertNull($withdrawn->standing);
    }

    public function test_candidate_who_moved_to_another_class_keeps_the_old_grade_without_standing(): void
    {
        $this->setThresholds($this->activePeriod, '75', '80');
        $this->finalizedAssessment($this->quizzes, 'Quiz 1', '20', [$this->candidateInA->id => '10']);

        $this->setScheme($this->offeringB1, ['Quizzes' => '100']);
        $this->finalizedAssessment($this->category($this->offeringB1, 'Quizzes'), 'Quiz B1', '20', [$this->candidateInB->id => '15']);

        $this->candidateInA->forceFill(['class_batch_id' => $this->batchB->id])->save();

        $old = $this->calculator()->forOffering($this->fresh($this->offeringA1), [$this->candidateInA->id])[$this->candidateInA->id];
        $this->assertSame(50.0, $old->grade);
        $this->assertNull($old->standing);

        // In the new class the candidate is monitored: the finalized quiz
        // without their score makes them Incomplete.
        $new = $this->calculator()->forOffering($this->fresh($this->offeringB1), [$this->candidateInA->id, $this->candidateInB->id]);
        $this->assertNull($new[$this->candidateInA->id]->grade);
        $this->assertSame(1, $new[$this->candidateInA->id]->missingScores);
        $this->assertSame(AcademicStanding::Incomplete, $new[$this->candidateInA->id]->standing);
        // 15/20 = 75.00: At Risk.
        $this->assertSame(AcademicStanding::AtRisk, $new[$this->candidateInB->id]->standing);
    }

    public function test_candidate_of_another_class_is_counted_as_missing_but_not_judged(): void
    {
        $this->setThresholds($this->activePeriod, '75', '80');
        $this->finalizedAssessment($this->quizzes, 'Quiz 1', '20', [$this->candidateInA->id => '18']);
        $this->finalizedAssessment($this->examinations, 'Midterm Examination', '100', [$this->candidateInA->id => '90']);

        $grades = $this->calculator()->forOffering($this->fresh($this->offeringA1), [$this->secondInA->id, $this->candidateInB->id]);

        // Same data for both: no scores on two finalized assessments. Only the
        // candidate of this class is Incomplete.
        $this->assertSame(2, $grades[$this->secondInA->id]->missingScores);
        $this->assertSame(AcademicStanding::Incomplete, $grades[$this->secondInA->id]->standing);
        $this->assertNull($grades[$this->candidateInB->id]->grade);
        $this->assertSame(2, $grades[$this->candidateInB->id]->missingScores);
        $this->assertNull($grades[$this->candidateInB->id]->standing);
    }

    public function test_candidate_on_leave_is_still_monitored(): void
    {
        $this->setThresholds($this->activePeriod, '75', '80');
        $this->finalizedAssessment($this->quizzes, 'Quiz 1', '20', [$this->secondInA->id => '15']);
        $this->secondInA->forceFill(['status' => CandidateStatus::OnLeave->value])->save();

        $grade = $this->calculator()->forOffering($this->fresh($this->offeringA1), [$this->secondInA->id])[$this->secondInA->id];

        $this->assertSame(75.0, $grade->grade);
        $this->assertSame(AcademicStanding::AtRisk, $grade->standing);
    }

    public function test_for_offering_with_no_candidates_returns_nothing_and_reads_no_candidate_data(): void
    {
        $this->setThresholds($this->activePeriod, '75', '80');
        $this->finalizedAssessment($this->quizzes, 'Quiz 1', '20', [$this->candidateInA->id => '18']);
        $offering = $this->fresh($this->offeringA1);

        $result = null;
        $queries = $this->recordQueries(function () use ($offering, &$result): void {
            $result = $this->calculator()->forOffering($offering, []);
        });

        $this->assertSame([], $result);
        foreach ($queries as $sql) {
            $this->assertStringNotContainsString('`candidates`', $sql);
            $this->assertStringNotContainsString('`assessment_scores`', $sql);
        }
    }

    public function test_for_offering_returns_a_result_for_every_requested_id_in_the_given_order(): void
    {
        $this->setThresholds($this->activePeriod, '75', '80');
        $this->finalizedAssessment($this->quizzes, 'Quiz 1', '20', [$this->candidateInA->id => '17']);
        $unknownId = (int) Candidate::query()->max('id') + 1000;

        $grades = $this->calculator()->forOffering($this->fresh($this->offeringA1), [$unknownId, $this->candidateInA->id, $this->candidateInB->id]);

        $this->assertSame([$unknownId, $this->candidateInA->id, $this->candidateInB->id], array_keys($grades));
        // An id that is no candidate is never judged.
        $this->assertNull($grades[$unknownId]->grade);
        $this->assertSame(1, $grades[$unknownId]->missingScores);
        $this->assertNull($grades[$unknownId]->standing);
        $this->assertSame(85.0, $grades[$this->candidateInA->id]->grade);
        $this->assertSame(AcademicStanding::Passing, $grades[$this->candidateInA->id]->standing);
        $this->assertNull($grades[$this->candidateInB->id]->standing);
    }

    public function test_standing_is_calculated_on_read_after_a_threshold_change(): void
    {
        $this->setThresholds($this->activePeriod, '75', '80');
        $this->setThresholds($this->pastPeriod, '60', '65');
        $this->finalizedAssessment($this->quizzes, 'Quiz 1', '20', [$this->candidateInA->id => '14']);
        $oldOffering = $this->oldOffering();
        $this->setScheme($oldOffering, ['Quizzes' => '100']);
        $this->finalizedAssessment($this->category($oldOffering, 'Quizzes'), 'Old Quiz 1', '20', [$this->oldCandidate()->id => '14']);

        $this->assertSame(AcademicStanding::Failing, $this->standingIn($this->offeringA1, $this->candidateInA));

        $this->setThresholds($this->activePeriod, '70', '72');
        $this->assertSame(AcademicStanding::AtRisk, $this->standingIn($this->offeringA1, $this->candidateInA));

        $this->setThresholds($this->activePeriod, '65', '70');
        $this->assertSame(AcademicStanding::Passing, $this->standingIn($this->offeringA1, $this->candidateInA));

        // The past period keeps its own thresholds throughout.
        $this->assertSame(AcademicStanding::Passing, $this->standingIn($oldOffering, $this->oldCandidate()));
        $this->assertSame(['passingGrade' => 60.0, 'warningGrade' => 65.0], $this->calculator()->thresholdsFor($this->fresh($oldOffering))?->toArray());
    }

    // ------------------------------------------------------------------
    // forCandidate()
    // ------------------------------------------------------------------

    public function test_for_candidate_takes_each_class_subject_thresholds_from_its_own_period(): void
    {
        $this->setThresholds($this->activePeriod, '75', '80');
        $this->setThresholds($this->pastPeriod, '60', '65');
        $this->finalizedAssessment($this->quizzes, 'Quiz 1', '20', [$this->candidateInA->id => '14']);
        $oldOffering = $this->oldOffering();
        $this->setScheme($oldOffering, ['Quizzes' => '100']);
        $this->finalizedAssessment($this->category($oldOffering, 'Quizzes'), 'Old Quiz 1', '20', [$this->oldCandidate()->id => '14']);

        // Candidate of the past period's class.
        $pastGrades = $this->calculator()->forCandidate($this->oldCandidate(), [$this->offeringA1->id, $oldOffering->id]);
        $this->assertSame([$this->offeringA1->id, $oldOffering->id], array_keys($pastGrades));
        $this->assertSame(70.0, $pastGrades[$oldOffering->id]->grade);
        $this->assertSame(AcademicStanding::Passing, $pastGrades[$oldOffering->id]->standing);
        $this->assertNull($pastGrades[$this->offeringA1->id]->grade);
        $this->assertSame(1, $pastGrades[$this->offeringA1->id]->missingScores);
        $this->assertNull($pastGrades[$this->offeringA1->id]->standing);

        // Candidate of the active period's class, subjects in the other order.
        $currentGrades = $this->calculator()->forCandidate($this->candidateInA->fresh(), [$oldOffering->id, $this->offeringA1->id]);
        $this->assertSame([$oldOffering->id, $this->offeringA1->id], array_keys($currentGrades));
        $this->assertSame(70.0, $currentGrades[$this->offeringA1->id]->grade);
        $this->assertSame(AcademicStanding::Failing, $currentGrades[$this->offeringA1->id]->standing);
        $this->assertSame(1, $currentGrades[$oldOffering->id]->missingScores);
        $this->assertNull($currentGrades[$oldOffering->id]->standing);
    }

    public function test_for_candidate_gives_no_standing_in_a_class_the_candidate_is_not_in(): void
    {
        $this->setThresholds($this->activePeriod, '75', '80');
        $this->finalizedAssessment($this->quizzes, 'Quiz 1', '20', [$this->candidateInA->id => '16']);
        $this->setScheme($this->offeringB1, ['Quizzes' => '100']);
        $this->finalizedAssessment($this->category($this->offeringB1, 'Quizzes'), 'Quiz B1', '20', [$this->candidateInB->id => '15']);

        $grades = $this->calculator()->forCandidate($this->candidateInA->fresh(), [$this->offeringA1->id, $this->offeringB1->id]);

        $this->assertSame(80.0, $grades[$this->offeringA1->id]->grade);
        $this->assertSame(AcademicStanding::Passing, $grades[$this->offeringA1->id]->standing);
        // Same period and thresholds, but Batch B is not the candidate's class.
        $this->assertNull($grades[$this->offeringB1->id]->grade);
        $this->assertSame(1, $grades[$this->offeringB1->id]->missingScores);
        $this->assertNull($grades[$this->offeringB1->id]->standing);
    }

    public function test_for_candidate_with_no_class_subjects_returns_nothing_without_queries(): void
    {
        $this->setThresholds($this->activePeriod, '75', '80');
        $candidate = $this->candidateInA->fresh();

        $result = null;
        $queries = $this->recordQueries(function () use ($candidate, &$result): void {
            $result = $this->calculator()->forCandidate($candidate, []);
        });

        $this->assertSame([], $result);
        $this->assertSame([], $queries);
    }

    public function test_for_candidate_withdrawn_candidate_keeps_grades_without_any_standing(): void
    {
        $this->setThresholds($this->activePeriod, '75', '80');
        $this->finalizedAssessment($this->quizzes, 'Quiz 1', '20', [$this->secondInA->id => '10']);
        $this->setScheme($this->offeringA2, ['Quizzes' => '50', 'Examinations' => '50']);
        $this->finalizedAssessment($this->category($this->offeringA2, 'Quizzes'), 'Quiz A2', '20', [$this->secondInA->id => '19'], $this->bravo);
        $this->secondInA->forceFill(['status' => CandidateStatus::Withdrawn->value])->save();

        $grades = $this->calculator()->forCandidate($this->secondInA->fresh(), [$this->offeringA1->id, $this->offeringA2->id]);

        $this->assertSame(50.0, $grades[$this->offeringA1->id]->grade);
        $this->assertNull($grades[$this->offeringA1->id]->standing);
        $this->assertSame(95.0, $grades[$this->offeringA2->id]->grade);
        $this->assertTrue($grades[$this->offeringA2->id]->isProvisional());
        $this->assertNull($grades[$this->offeringA2->id]->standing);

        $this->assertSame([
            'standing' => null,
            'basedOnSubjects' => 0,
            'totalSubjects' => 2,
            'isProvisional' => false,
        ], $this->calculator()->overallStanding($grades)->toArray());
    }

    public function test_for_candidate_unknown_class_subject_id_gets_an_empty_result(): void
    {
        $this->setThresholds($this->activePeriod, '75', '80');
        $unknownId = (int) ClassSubject::query()->max('id') + 1000;

        $grades = $this->calculator()->forCandidate($this->candidateInA->fresh(), [$unknownId]);

        $this->assertSame([$unknownId], array_keys($grades));
        $this->assertSame([], $grades[$unknownId]->categories);
        $this->assertNull($grades[$unknownId]->grade);
        $this->assertNull($grades[$unknownId]->standing);
        $this->assertSame('not_configured', $grades[$unknownId]->status()->value);
    }

    public function test_for_candidate_and_for_offering_agree(): void
    {
        $this->setThresholds($this->activePeriod, '75', '80');
        $this->finalizedAssessment($this->quizzes, 'Quiz 1', '20', [$this->candidateInA->id => '15', $this->secondInA->id => '19']);
        $this->finalizedAssessment($this->quizzes, 'Quiz 2', '30', [$this->candidateInA->id => '21']);
        $this->finalizedAssessment($this->examinations, 'Midterm Examination', '100', [$this->candidateInA->id => '77.5', $this->secondInA->id => '81']);
        // A draft never counts in either calculation.
        $draft = $this->createAssessment($this->examinations, 'Final Examination', '100');
        $this->recordScores($draft, [$this->candidateInA->id => '10']);

        $byOffering = $this->calculator()->forOffering($this->fresh($this->offeringA1), [$this->candidateInA->id, $this->secondInA->id]);

        foreach ([$this->candidateInA, $this->secondInA] as $candidate) {
            $byCandidate = $this->calculator()->forCandidate($candidate->fresh(), [$this->offeringA1->id]);
            $this->assertSame($byOffering[$candidate->id]->toArray(), $byCandidate[$this->offeringA1->id]->toArray());
        }

        // Quizzes 36/50 = 72%, examinations 77.5%: 0.4 × 72 + 0.6 × 77.5 = 75.30.
        $this->assertSame(75.3, $byOffering[$this->candidateInA->id]->grade);
        $this->assertSame(AcademicStanding::AtRisk, $byOffering[$this->candidateInA->id]->standing);
        // Quiz 2 missing: 0.4 × 95 + 0.6 × 81 = 86.60, Passing withheld.
        $this->assertSame(86.6, $byOffering[$this->secondInA->id]->grade);
        $this->assertSame(AcademicStanding::Incomplete, $byOffering[$this->secondInA->id]->standing);
    }

    // ------------------------------------------------------------------
    // thresholdsFor() and GradingThresholds::forPeriod()
    // ------------------------------------------------------------------

    public function test_thresholds_for_reads_decimal_thresholds_of_the_class_period(): void
    {
        $this->setThresholds($this->activePeriod, '82.5', '90');

        $period = AcademicPeriod::query()->findOrFail($this->activePeriod->id);
        $this->assertSame('82.50', $period->passing_grade);
        $this->assertSame('90.00', $period->warning_grade);

        $thresholds = $this->calculator()->thresholdsFor($this->fresh($this->offeringA1));
        $this->assertNotNull($thresholds);
        $this->assertSame(8250, $thresholds->passingHundredths);
        $this->assertSame(9000, $thresholds->warningHundredths);
        $this->assertSame(82.5, $thresholds->passingGrade());
        $this->assertSame(['passingGrade' => 82.5, 'warningGrade' => 90.0], $thresholds->toArray());

        // Same period, other class: the same thresholds. Past period: none.
        $this->assertSame(8250, $this->calculator()->thresholdsFor($this->fresh($this->offeringB1))?->passingHundredths);
        $this->assertNull($this->calculator()->thresholdsFor($this->fresh($this->oldOffering())));
    }

    public function test_for_period_converts_stored_decimals_to_whole_hundredths(): void
    {
        $this->assertNull(GradingThresholds::forPeriod(AcademicPeriod::query()->findOrFail($this->pastPeriod->id)));

        $cases = [
            ['82.5', '90', 8250, 9000],
            ['0.01', '100', 1, 10000],
            ['99.99', '99.99', 9999, 9999],
            ['74.95', '80.05', 7495, 8005],
        ];

        foreach ($cases as [$passing, $warning, $passingHundredths, $warningHundredths]) {
            DB::table('academic_periods')->where('id', $this->pastPeriod->id)->update(['passing_grade' => $passing, 'warning_grade' => $warning]);

            $period = AcademicPeriod::query()->findOrFail($this->pastPeriod->id);
            $this->assertSame(number_format((float) $passing, 2, '.', ''), $period->passing_grade);

            $thresholds = GradingThresholds::forPeriod($period);
            $this->assertNotNull($thresholds);
            $this->assertSame($passingHundredths, $thresholds->passingHundredths, "passing {$passing}");
            $this->assertSame($warningHundredths, $thresholds->warningHundredths, "warning {$warning}");
        }
    }

    // ------------------------------------------------------------------
    // Boundaries with real stored scores
    // ------------------------------------------------------------------

    public function test_grade_exactly_at_the_passing_grade_is_at_risk_and_one_hundredth_below_is_failing(): void
    {
        $this->setThresholds($this->activePeriod, '75', '80');
        $this->finalizedAssessment($this->quizzes, 'Quiz 1', '20', [$this->candidateInA->id => '15', $this->secondInA->id => '15']);
        $this->finalizedAssessment($this->examinations, 'Midterm Examination', '100', [$this->candidateInA->id => '75', $this->secondInA->id => '74.98']);

        $grades = $this->calculator()->forOffering($this->fresh($this->offeringA1), [$this->candidateInA->id, $this->secondInA->id]);

        // 0.4 × 75 + 0.6 × 75 = 75.00.
        $this->assertSame(75.0, $grades[$this->candidateInA->id]->grade);
        $this->assertSame('complete', $grades[$this->candidateInA->id]->status()->value);
        $this->assertSame(AcademicStanding::AtRisk, $grades[$this->candidateInA->id]->standing);
        // 0.4 × 75 + 0.6 × 74.98 = 74.988, shown as 74.99.
        $this->assertSame(74.99, $grades[$this->secondInA->id]->grade);
        $this->assertSame(AcademicStanding::Failing, $grades[$this->secondInA->id]->standing);
    }

    public function test_grade_exactly_at_the_warning_grade_is_passing_and_one_hundredth_below_is_at_risk(): void
    {
        $this->setThresholds($this->activePeriod, '75', '80');
        $this->finalizedAssessment($this->quizzes, 'Quiz 1', '20', [$this->candidateInA->id => '16', $this->secondInA->id => '16']);
        $this->finalizedAssessment($this->examinations, 'Midterm Examination', '100', [$this->candidateInA->id => '80', $this->secondInA->id => '79.98']);

        $grades = $this->calculator()->forOffering($this->fresh($this->offeringA1), [$this->candidateInA->id, $this->secondInA->id]);

        $this->assertSame(80.0, $grades[$this->candidateInA->id]->grade);
        $this->assertSame(AcademicStanding::Passing, $grades[$this->candidateInA->id]->standing);
        // 0.4 × 80 + 0.6 × 79.98 = 79.988, shown as 79.99.
        $this->assertSame(79.99, $grades[$this->secondInA->id]->grade);
        $this->assertSame(AcademicStanding::AtRisk, $grades[$this->secondInA->id]->standing);
    }

    public function test_unrounded_grade_of_74_995_from_two_categories_is_shown_as_75_and_is_at_risk(): void
    {
        $this->setThresholds($this->activePeriod, '75', '80');
        // Quizzes 20/20 = 100% (weighted 40); examinations 116.65/200 =
        // 58.325% (weighted 34.995): unrounded grade 74.995.
        $this->finalizedAssessment($this->quizzes, 'Quiz 1', '20', [$this->candidateInA->id => '20']);
        $this->finalizedAssessment($this->examinations, 'Midterm Examination', '200', [$this->candidateInA->id => '116.65']);

        $grade = $this->calculator()->forOffering($this->fresh($this->offeringA1), [$this->candidateInA->id])[$this->candidateInA->id];

        $this->assertSame(75.0, $grade->grade);
        $this->assertSame(AcademicStanding::AtRisk, $grade->standing);
        $exams = $grade->categories[1];
        $this->assertSame('Examinations', $exams->name);
        $this->assertSame(58.33, $exams->percentage);
        $this->assertSame(35.0, $exams->weightedScore);
    }

    public function test_rounding_at_the_passing_grade_with_a_single_category(): void
    {
        $this->setThresholds($this->activePeriod, '75', '80');
        $this->setScheme($this->offeringA2, ['Assessments' => '100']);
        $category = $this->category($this->offeringA2, 'Assessments');
        // 149.99/200 = 74.995% (shown 75.00); 1499.89/2000 = 74.9945% (shown 74.99).
        $this->finalizedAssessment($category, 'Written Test', '200', [$this->candidateInA->id => '149.99'], $this->bravo);
        $this->finalizedAssessment($category, 'Long Test', '2000', [$this->secondInA->id => '1499.89'], $this->bravo);

        $grades = $this->calculator()->forOffering($this->fresh($this->offeringA2), [$this->candidateInA->id, $this->secondInA->id]);

        $this->assertSame(75.0, $grades[$this->candidateInA->id]->grade);
        // One missing score each, but At Risk and Failing are never hidden.
        $this->assertSame(1, $grades[$this->candidateInA->id]->missingScores);
        $this->assertSame(AcademicStanding::AtRisk, $grades[$this->candidateInA->id]->standing);
        $this->assertSame(74.99, $grades[$this->secondInA->id]->grade);
        $this->assertSame(AcademicStanding::Failing, $grades[$this->secondInA->id]->standing);
    }

    public function test_decimal_thresholds_are_compared_exactly_with_stored_scores(): void
    {
        $this->setThresholds($this->activePeriod, '82.50', '90');
        $this->setScheme($this->offeringA2, ['Assessments' => '100']);
        $category = $this->category($this->offeringA2, 'Assessments');
        $third = Candidate::factory()->create(['class_batch_id' => $this->batchA->id, 'last_name' => 'A4']);
        $this->finalizedAssessment($category, 'Written Test', '40', [
            $this->candidateInA->id => '33',     // 82.50
            $this->secondInA->id => '32.99',     // 82.475, shown 82.48
            $third->id => '36',                  // 90.00
        ], $this->bravo);

        $grades = $this->calculator()->forOffering($this->fresh($this->offeringA2), [$this->candidateInA->id, $this->secondInA->id, $third->id]);

        $this->assertSame(82.5, $grades[$this->candidateInA->id]->grade);
        $this->assertSame(AcademicStanding::AtRisk, $grades[$this->candidateInA->id]->standing);
        $this->assertSame(82.48, $grades[$this->secondInA->id]->grade);
        $this->assertSame(AcademicStanding::Failing, $grades[$this->secondInA->id]->standing);
        $this->assertSame(90.0, $grades[$third->id]->grade);
        $this->assertSame(AcademicStanding::Passing, $grades[$third->id]->standing);
    }

    public function test_equal_passing_and_warning_grades_leave_no_at_risk_band_with_stored_scores(): void
    {
        $this->setThresholds($this->activePeriod, '75', '75');
        $this->finalizedAssessment($this->quizzes, 'Quiz 1', '100', [$this->candidateInA->id => '75', $this->secondInA->id => '74.99']);

        $grades = $this->calculator()->forOffering($this->fresh($this->offeringA1), [$this->candidateInA->id, $this->secondInA->id]);

        $this->assertSame(AcademicStanding::Passing, $grades[$this->candidateInA->id]->standing);
        $this->assertSame(AcademicStanding::Failing, $grades[$this->secondInA->id]->standing);
    }

    public function test_missing_scores_on_finalized_assessments_turn_passing_into_incomplete_but_keep_warnings(): void
    {
        $this->setThresholds($this->activePeriod, '75', '80');
        $this->finalizedAssessment($this->quizzes, 'Quiz 1', '20', [$this->candidateInA->id => '18', $this->secondInA->id => '15']);
        $this->finalizedAssessment($this->examinations, 'Midterm Examination', '100', [$this->candidateInA->id => '95']);

        $before = $this->calculator()->forOffering($this->fresh($this->offeringA1), [$this->candidateInA->id, $this->secondInA->id]);
        // 0.4 × 90 + 0.6 × 95 = 93.00, complete.
        $this->assertSame(93.0, $before[$this->candidateInA->id]->grade);
        $this->assertSame(AcademicStanding::Passing, $before[$this->candidateInA->id]->standing);
        // Quizzes only (75%), examination missing: At Risk is kept.
        $this->assertSame(75.0, $before[$this->secondInA->id]->grade);
        $this->assertSame(AcademicStanding::AtRisk, $before[$this->secondInA->id]->standing);

        // A second examination without the first candidate's score.
        $this->finalizedAssessment($this->examinations, 'Final Examination', '100', [$this->secondInA->id => '60']);

        $after = $this->calculator()->forOffering($this->fresh($this->offeringA1), [$this->candidateInA->id, $this->secondInA->id]);
        $this->assertSame(93.0, $after[$this->candidateInA->id]->grade);
        $this->assertSame(1, $after[$this->candidateInA->id]->missingScores);
        $this->assertSame(AcademicStanding::Incomplete, $after[$this->candidateInA->id]->standing);
        // 0.4 × 75 + 0.6 × 60 = 66.00, still missing the midterm.
        $this->assertSame(66.0, $after[$this->secondInA->id]->grade);
        $this->assertSame(AcademicStanding::Failing, $after[$this->secondInA->id]->standing);
    }

    // ------------------------------------------------------------------
    // overallStanding() over forCandidate() results
    // ------------------------------------------------------------------

    public function test_overall_standing_from_for_candidate_is_the_most_serious_subject_standing(): void
    {
        $this->setThresholds($this->activePeriod, '75', '80');
        // Subject 1: 0.4 × 50 + 0.6 × 60 = 56.00, complete, Failing.
        $this->finalizedAssessment($this->quizzes, 'Quiz 1', '20', [$this->candidateInA->id => '10']);
        $this->finalizedAssessment($this->examinations, 'Midterm Examination', '100', [$this->candidateInA->id => '60']);
        // Subject 2: quizzes only so far (90%), provisional, Passing.
        $this->setScheme($this->offeringA2, ['Quizzes' => '50', 'Examinations' => '50']);
        $this->finalizedAssessment($this->category($this->offeringA2, 'Quizzes'), 'Quiz A2', '20', [$this->candidateInA->id => '18'], $this->bravo);
        // Subject 3: grading not set up, no standing.
        $offeringA3 = $this->offering($this->batchA, Subject::factory()->create(['code' => 'SUBJ-3', 'name' => 'Subject 3']));

        $grades = $this->calculator()->forCandidate($this->candidateInA->fresh(), [$this->offeringA1->id, $this->offeringA2->id, $offeringA3->id]);

        $this->assertSame(AcademicStanding::Failing, $grades[$this->offeringA1->id]->standing);
        $this->assertFalse($grades[$this->offeringA1->id]->isProvisional());
        $this->assertSame(AcademicStanding::Passing, $grades[$this->offeringA2->id]->standing);
        $this->assertTrue($grades[$this->offeringA2->id]->isProvisional());
        $this->assertNull($grades[$offeringA3->id]->standing);
        $this->assertFalse($grades[$offeringA3->id]->isProvisional());

        $this->assertSame([
            'standing' => ['value' => 'failing', 'label' => 'Failing', 'tone' => 'danger'],
            'basedOnSubjects' => 2,
            'totalSubjects' => 3,
            'isProvisional' => true,
        ], $this->calculator()->overallStanding($grades)->toArray());

        // Without the failing subject: Passing, still provisional.
        $this->assertSame([
            'standing' => ['value' => 'passing', 'label' => 'Passing', 'tone' => 'success'],
            'basedOnSubjects' => 1,
            'totalSubjects' => 2,
            'isProvisional' => true,
        ], $this->calculator()->overallStanding([$grades[$this->offeringA2->id], $grades[$offeringA3->id]])->toArray());
    }

    public function test_overall_standing_ignores_provisional_grades_of_subjects_without_standing(): void
    {
        $this->setThresholds($this->activePeriod, '75', '80');
        $this->finalizedAssessment($this->quizzes, 'Quiz 1', '20', [$this->candidateInA->id => '18']);
        $this->finalizedAssessment($this->examinations, 'Midterm Examination', '100', [$this->candidateInA->id => '90']);
        $this->setScheme($this->offeringA2, ['Quizzes' => '50', 'Examinations' => '50']);
        $this->finalizedAssessment($this->category($this->offeringA2, 'Quizzes'), 'Quiz A2', '20', [$this->candidateInA->id => '18'], $this->bravo);
        $this->setScheme($this->offeringB1, ['Quizzes' => '100']);
        $this->finalizedAssessment($this->category($this->offeringB1, 'Quizzes'), 'Quiz B1', '20', [$this->candidateInB->id => '15'], $this->bravo);

        // The candidate moves from Batch A to Batch B.
        $this->candidateInA->forceFill(['class_batch_id' => $this->batchB->id])->save();

        $grades = $this->calculator()->forCandidate($this->candidateInA->fresh(), [$this->offeringA1->id, $this->offeringA2->id, $this->offeringB1->id]);

        $this->assertSame(90.0, $grades[$this->offeringA1->id]->grade);
        $this->assertNull($grades[$this->offeringA1->id]->standing);
        $this->assertTrue($grades[$this->offeringA2->id]->isProvisional());
        $this->assertNull($grades[$this->offeringA2->id]->standing);
        $this->assertSame(AcademicStanding::Incomplete, $grades[$this->offeringB1->id]->standing);

        $this->assertSame([
            'standing' => ['value' => 'incomplete', 'label' => 'Incomplete', 'tone' => 'neutral'],
            'basedOnSubjects' => 1,
            'totalSubjects' => 3,
            // Subject 2 is provisional but not part of the standing.
            'isProvisional' => false,
        ], $this->calculator()->overallStanding($grades)->toArray());
    }

    // ------------------------------------------------------------------
    // Query budget
    // ------------------------------------------------------------------

    public function test_for_offering_uses_a_fixed_number_of_queries_regardless_of_candidate_count(): void
    {
        $this->setThresholds($this->activePeriod, '75', '80');
        $extra = Candidate::factory()->count(8)->create(['class_batch_id' => $this->batchA->id]);
        $ids = [$this->candidateInA->id, $this->secondInA->id, ...$extra->modelKeys()];
        $this->assertCount(10, $ids);

        $scores = array_fill_keys($ids, '16');
        $this->finalizedAssessment($this->quizzes, 'Quiz 1', '20', $scores);
        $this->finalizedAssessment($this->examinations, 'Midterm Examination', '100', array_fill_keys($ids, '70'));

        $twoOffering = $this->fresh($this->offeringA1);
        $two = null;
        $twoQueries = count($this->recordQueries(function () use ($twoOffering, $ids, &$two): void {
            $two = $this->calculator()->forOffering($twoOffering, array_slice($ids, 0, 2));
        }));

        $tenOffering = $this->fresh($this->offeringA1);
        $ten = null;
        $tenQueries = count($this->recordQueries(function () use ($tenOffering, $ids, &$ten): void {
            $ten = $this->calculator()->forOffering($tenOffering, $ids);
        }));

        $this->assertCount(2, $two);
        $this->assertCount(10, $ten);
        // 0.4 × 80 + 0.6 × 70 = 74.00 for everyone.
        foreach ($ten as $grade) {
            $this->assertSame(74.0, $grade->grade);
            $this->assertSame(AcademicStanding::Failing, $grade->standing);
        }
        $this->assertSame($twoQueries, $tenQueries);
        // Scheme, assessments, scores, class and period (thresholds), and
        // the gradable-candidate filter.
        $this->assertSame(6, $tenQueries);
    }

    public function test_for_candidate_uses_a_fixed_number_of_queries_regardless_of_subject_count(): void
    {
        $this->setThresholds($this->activePeriod, '75', '80');
        [, $candidate, $offerings] = $this->buildProfileClasses();
        $this->assertCount(4, $offerings);
        $ids = array_map(fn (ClassSubject $offering): int => $offering->id, $offerings);

        $one = null;
        $oneQueries = count($this->recordQueries(function () use ($candidate, $ids, &$one): void {
            $one = $this->calculator()->forCandidate($candidate, [$ids[0]]);
        }));
        $four = null;
        $fourQueries = count($this->recordQueries(function () use ($candidate, $ids, &$four): void {
            $four = $this->calculator()->forCandidate($candidate, $ids);
        }));

        $this->assertCount(1, $one);
        $this->assertCount(4, $four);
        foreach ($four as $grade) {
            $this->assertSame(75.0, $grade->grade);
            $this->assertSame(AcademicStanding::AtRisk, $grade->standing);
        }
        $this->assertSame($oneQueries, $fourQueries);
        // Thresholds per class subject, categories, assessments, scores.
        $this->assertSame(4, $fourQueries);
    }

    public function test_gradebook_page_query_count_does_not_grow_with_the_number_of_candidates(): void
    {
        $this->setThresholds($this->activePeriod, '75', '80');
        $subject = Subject::factory()->create(['code' => 'SUBJ-3', 'name' => 'Subject 3']);
        $small = $this->gradedClass('Sample Batch Small', $subject, 2);
        $large = $this->gradedClass('Sample Batch Large', $subject, 12);

        // Warm up once so per-user lookups cached on the first request do not
        // count against the first measured page.
        $this->actingAs($this->alpha)->get($this->gradebookUrl($small))->assertOk();

        $smallResponse = null;
        $smallQueries = count($this->recordQueries(function () use ($small, &$smallResponse): void {
            $smallResponse = $this->actingAs($this->alpha)->get($this->gradebookUrl($small));
        }));
        $largeResponse = null;
        $largeQueries = count($this->recordQueries(function () use ($large, &$largeResponse): void {
            $largeResponse = $this->actingAs($this->alpha)->get($this->gradebookUrl($large));
        }));

        $this->assertInstanceOf(TestResponse::class, $smallResponse);
        $this->assertInstanceOf(TestResponse::class, $largeResponse);
        $smallResponse->assertOk();
        $largeResponse->assertOk();
        $this->assertCount(2, $smallResponse->inertiaProps('grades.data'));
        $this->assertCount(12, $largeResponse->inertiaProps('grades.data'));
        $this->assertSame(
            ['value' => 'at_risk', 'label' => 'At Risk', 'tone' => 'warning'],
            $largeResponse->inertiaProps('grades.data')[11]['result']['standing'],
        );
        // The log really captured the page (at least the six queries of the
        // calculation itself), and twelve candidates cost no more than two.
        $this->assertGreaterThan(6, $smallQueries);
        $this->assertSame($smallQueries, $largeQueries);
    }

    public function test_candidate_profile_query_count_does_not_grow_with_the_number_of_subjects(): void
    {
        $this->setThresholds($this->activePeriod, '75', '80');
        [$oneSubjectCandidate, $fourSubjectCandidate] = $this->buildProfileClasses();

        foreach ([$this->academicAdmin, $this->alpha] as $viewer) {
            $this->actingAs($viewer)->get("/candidates/{$oneSubjectCandidate->id}")->assertOk();

            $oneResponse = null;
            $oneQueries = count($this->recordQueries(function () use ($viewer, $oneSubjectCandidate, &$oneResponse): void {
                $oneResponse = $this->actingAs($viewer)->get("/candidates/{$oneSubjectCandidate->id}");
            }));
            $fourResponse = null;
            $fourQueries = count($this->recordQueries(function () use ($viewer, $fourSubjectCandidate, &$fourResponse): void {
                $fourResponse = $this->actingAs($viewer)->get("/candidates/{$fourSubjectCandidate->id}");
            }));

            $this->assertInstanceOf(TestResponse::class, $oneResponse);
            $this->assertInstanceOf(TestResponse::class, $fourResponse);
            $oneResponse->assertOk();
            $fourResponse->assertOk();
            $this->assertCount(1, $oneResponse->inertiaProps('performance'));
            $this->assertCount(4, $fourResponse->inertiaProps('performance'));
            $this->assertSame([
                'standing' => ['value' => 'at_risk', 'label' => 'At Risk', 'tone' => 'warning'],
                'basedOnSubjects' => 4,
                'totalSubjects' => 4,
                'isProvisional' => false,
            ], $fourResponse->inertiaProps('standing.overall'));
            $this->assertGreaterThan(4, $oneQueries);
            $this->assertSame($oneQueries, $fourQueries, "Query count grows with subjects for {$viewer->name}.");
        }
    }

    // ------------------------------------------------------------------
    // Locking when thresholds are saved
    // ------------------------------------------------------------------

    public function test_saving_thresholds_locks_the_period_row_inside_the_transaction(): void
    {
        $statements = $this->listenToStatements();
        $outerLevel = DB::transactionLevel();

        $this->app->make(GradingThresholdService::class)->save($this->activePeriod, '75', '80', null);

        $inTransaction = array_values(array_filter($statements->getArrayCopy(), fn (array $statement): bool => $statement['level'] === $outerLevel + 1));
        $this->assertNotEmpty($inTransaction);

        // The first statement of the transaction locks the period row.
        $lock = $inTransaction[0];
        $this->assertMatchesRegularExpression('/^select .* from `academic_periods` where .*`id` = \? limit 1 for update$/', $lock['sql']);
        $this->assertSame([$this->activePeriod->id], $lock['bindings']);

        $sqls = array_column($inTransaction, 'sql');
        $updates = array_values(array_filter($sqls, fn (string $sql): bool => str_starts_with($sql, 'update `academic_periods`')));
        $audits = array_values(array_filter($sqls, fn (string $sql): bool => str_starts_with($sql, 'insert into `audit_logs`')));
        $this->assertCount(1, $updates);
        $this->assertCount(1, $audits);

        // Nothing touches the period outside the transaction except the final refresh.
        $outside = array_filter($statements->getArrayCopy(), fn (array $statement): bool => $statement['level'] === $outerLevel
            && str_contains($statement['sql'], '`academic_periods`')
            && ! str_starts_with($statement['sql'], 'select'));
        $this->assertSame([], array_values($outside));

        $this->assertSame('75.00', $this->activePeriod->passing_grade);
    }

    public function test_unchanged_threshold_save_still_locks_but_writes_nothing(): void
    {
        $this->setThresholds($this->activePeriod, '75', '80');
        $statements = $this->listenToStatements();
        $outerLevel = DB::transactionLevel();

        $this->app->make(GradingThresholdService::class)->save($this->activePeriod, '75.00', '80.0', null);

        $inTransaction = array_values(array_filter($statements->getArrayCopy(), fn (array $statement): bool => $statement['level'] === $outerLevel + 1));
        $this->assertStringEndsWith('for update', $inTransaction[0]['sql']);
        foreach ($statements->getArrayCopy() as $statement) {
            $this->assertFalse(str_starts_with($statement['sql'], 'update'), $statement['sql']);
            $this->assertFalse(str_starts_with($statement['sql'], 'insert'), $statement['sql']);
        }
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function calculator(): GradeCalculationService
    {
        return $this->app->make(GradeCalculationService::class);
    }

    private function setThresholds(AcademicPeriod $period, string $passing, string $warning): void
    {
        $this->app->make(GradingThresholdService::class)->save($period, $passing, $warning, self::SETUP_REASON);
    }

    /**
     * Creates an assessment, records the given scores, and finalizes it.
     *
     * @param  array<int, string|null>  $scores  candidate id => raw score
     */
    private function finalizedAssessment(AssessmentCategory $category, string $title, string $maxScore, array $scores, ?User $actor = null): Assessment
    {
        $assessment = $this->createAssessment($category, $title, $maxScore, actor: $actor);
        $this->recordScores($assessment, $scores, $actor);
        $this->finalize($assessment, $actor);

        return $assessment;
    }

    private function category(ClassSubject $offering, string $name): AssessmentCategory
    {
        return $offering->assessmentCategories()->where('name', $name)->sole();
    }

    /**
     * A new instance without loaded relations, as a request would bind it.
     */
    private function fresh(ClassSubject $offering): ClassSubject
    {
        return ClassSubject::query()->findOrFail($offering->id);
    }

    private function oldOffering(): ClassSubject
    {
        return $this->offering($this->batchOld, Subject::query()->where('code', 'SUBJ-1')->sole());
    }

    /** The completed candidate of the past period's class. */
    private function oldCandidate(): Candidate
    {
        return Candidate::query()->where('class_batch_id', $this->batchOld->id)->where('last_name', 'Old1')->sole();
    }

    private function standingIn(ClassSubject $offering, Candidate $candidate): ?AcademicStanding
    {
        return $this->calculator()->forOffering($this->fresh($offering), [$candidate->id])[$candidate->id]->standing;
    }

    private function gradebookUrl(ClassSubject $offering): string
    {
        return "/my-classes/{$offering->class_batch_id}/subjects/{$offering->id}";
    }

    /**
     * A class of the active period with the given number of candidates, one
     * subject taught by Alpha (Quizzes 40%, Examinations 60%), and one
     * finalized quiz and examination scored for everyone at 75%.
     */
    private function gradedClass(string $name, Subject $subject, int $candidateCount): ClassSubject
    {
        $classBatch = ClassBatch::factory()->for($this->activePeriod)->create(['name' => $name]);
        $offering = $this->offering($classBatch, $subject);
        $this->teach($this->alpha, $offering);
        $ids = Candidate::factory()->count($candidateCount)->create(['class_batch_id' => $classBatch->id])->modelKeys();

        $this->setScheme($offering, ['Quizzes' => '40', 'Examinations' => '60']);
        $this->finalizedAssessment($this->category($offering, 'Quizzes'), 'Quiz 1', '20', array_fill_keys($ids, '15'));
        $this->finalizedAssessment($this->category($offering, 'Examinations'), 'Midterm Examination', '100', array_fill_keys($ids, '75'));

        return $offering;
    }

    /**
     * Two classes of the active period, all subjects taught by Alpha, each
     * subject with one finalized quiz scored 15/20 (75%, At Risk at 75/80):
     * "Sample Batch One" with one subject and "Sample Batch Four" with four.
     *
     * @return array{Candidate, Candidate, list<ClassSubject>} the one-subject candidate, the four-subject candidate, and the four class subjects
     */
    private function buildProfileClasses(): array
    {
        $subjects = [];
        foreach ([3, 4, 5, 6] as $number) {
            $subjects[] = Subject::factory()->create(['code' => "SUBJ-{$number}", 'name' => "Subject {$number}"]);
        }

        $oneClass = ClassBatch::factory()->for($this->activePeriod)->create(['name' => 'Sample Batch One']);
        $fourClass = ClassBatch::factory()->for($this->activePeriod)->create(['name' => 'Sample Batch Four']);
        $oneCandidate = Candidate::factory()->create(['class_batch_id' => $oneClass->id]);
        $fourCandidate = Candidate::factory()->create(['class_batch_id' => $fourClass->id]);

        $grade = function (ClassSubject $offering, Candidate $candidate): void {
            $this->teach($this->alpha, $offering);
            $this->setScheme($offering, ['Quizzes' => '100']);
            $this->finalizedAssessment($this->category($offering, 'Quizzes'), 'Quiz 1', '20', [$candidate->id => '15']);
        };

        $grade($this->offering($oneClass, $subjects[0]), $oneCandidate);

        $fourOfferings = [];
        foreach ($subjects as $subject) {
            $fourOfferings[] = $offering = $this->offering($fourClass, $subject);
            $grade($offering, $fourCandidate);
        }

        return [$oneCandidate->fresh(), $fourCandidate->fresh(), $fourOfferings];
    }

    /**
     * SQL of the queries run by the callback.
     *
     * @return list<string>
     */
    private function recordQueries(callable $callback): array
    {
        DB::flushQueryLog();
        DB::enableQueryLog();

        try {
            $callback();
            $log = DB::getQueryLog();
        } finally {
            DB::disableQueryLog();
            DB::flushQueryLog();
        }

        return array_map(fn (array $entry): string => $entry['query'], $log);
    }

    /**
     * Every statement from now on, with its bindings and the transaction
     * level it ran at.
     *
     * @return ArrayObject<int, array{sql: string, bindings: array<int, mixed>, level: int}>
     */
    private function listenToStatements(): ArrayObject
    {
        $statements = new ArrayObject;
        DB::listen(function (QueryExecuted $query) use ($statements): void {
            $statements->append([
                'sql' => $query->sql,
                'bindings' => $query->bindings,
                'level' => $query->connection->transactionLevel(),
            ]);
        });

        return $statements;
    }
}
