<?php

namespace Tests\Feature\Grading;

use App\Enums\AcademicStanding;
use App\Enums\CandidateStatus;
use App\Enums\Permission as PermissionCode;
use App\Enums\SystemRole;
use App\Models\AcademicPeriod;
use App\Models\Assessment;
use App\Models\AssessmentCategory;
use App\Models\AuditLog;
use App\Models\Candidate;
use App\Models\ClassSubject;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Subject;
use App\Models\User;
use App\Services\Grading\GradeCalculationService;
use App\Services\Grading\GradingSchemeService;
use App\Services\Grading\GradingThresholdService;
use App\Services\Grading\ScoreRecordingService;
use App\Support\DecimalValue;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoGradingSeeder;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Milestone 5: academic standing as it appears on the gradebook, the
 * candidate profile, and the grading setup page, and how it is recalculated
 * when scores, finalization, thresholds, or weights change. Also the demo
 * thresholds of the demo seeder.
 *
 * Unless a test says otherwise, the active period uses a passing grade of
 * 75 and a warning grade of 80 (Failing below 75, At Risk from 75 to 79.99,
 * Passing from 80). Page props are JSON: whole numbers such as 75.0 arrive
 * as 75.
 */
class AcademicStandingIntegrationTest extends TestCase
{
    use BuildsGradingFixtures;

    private const PASSING = ['value' => 'passing', 'label' => 'Passing', 'tone' => 'success'];

    private const AT_RISK = ['value' => 'at_risk', 'label' => 'At Risk', 'tone' => 'warning'];

    private const FAILING = ['value' => 'failing', 'label' => 'Failing', 'tone' => 'danger'];

    private const INCOMPLETE = ['value' => 'incomplete', 'label' => 'Incomplete', 'tone' => 'neutral'];

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildGradingFixtures();
    }

    // ------------------------------------------------------------------
    // Gradebook
    // ------------------------------------------------------------------

    public function test_gradebook_without_period_thresholds_has_no_standing_for_any_candidate(): void
    {
        $this->recordWorkedExample();

        $this->actingAs($this->alpha)
            ->get($this->gradebookUrl($this->offeringA1))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('staff/teaching/gradebook/show')
                ->where('thresholds', null)
                ->has('grades.data', 2)
                // Grades are still calculated; only the standing is unavailable.
                ->where('grades.data.0.result.grade', 77.7)
                ->where('grades.data.0.result.isProvisional', false)
                ->where('grades.data.0.result.standing', null)
                ->where('grades.data.1.result.grade', 70.4)
                ->where('grades.data.1.result.isProvisional', false)
                ->where('grades.data.1.result.standing', null));
    }

    public function test_gradebook_shows_the_period_thresholds_and_each_candidates_standing(): void
    {
        $this->setThresholds($this->activePeriod, '75', '80');
        $third = $this->candidateInBatchA('A4');
        // Candidate A4: Quizzes 47 / 50 = 94% of 40, Examinations 85% of 60 -> 37.6 + 51 = 88.60.
        $this->recordWorkedExample([$third->id => ['20', '27', '85']]);

        $this->actingAs($this->alpha)
            ->get($this->gradebookUrl($this->offeringA1))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('thresholds', ['passingGrade' => 75, 'warningGrade' => 80])
                ->has('grades.data', 3)
                ->where('grades.data.0.candidate.id', $this->candidateInA->id)
                ->where('grades.data.0.result.grade', 77.7)
                ->where('grades.data.0.result.isProvisional', false)
                ->where('grades.data.0.result.status.value', 'complete')
                ->where('grades.data.0.result.standing', self::AT_RISK)
                ->where('grades.data.1.candidate.id', $this->secondInA->id)
                ->where('grades.data.1.result.grade', 70.4)
                ->where('grades.data.1.result.isProvisional', false)
                ->where('grades.data.1.result.standing', self::FAILING)
                ->where('grades.data.2.candidate.id', $third->id)
                ->where('grades.data.2.result.grade', 88.6)
                ->where('grades.data.2.result.isProvisional', false)
                ->where('grades.data.2.result.standing', self::PASSING));
    }

    public function test_decimal_thresholds_are_applied_to_the_hundredth_of_the_displayed_grade(): void
    {
        $this->setThresholds($this->activePeriod, '74.5', '79.75');
        $fourth = $this->candidateInBatchA('A4');
        $fifth = $this->candidateInBatchA('A5');
        $sixth = $this->candidateInBatchA('A6');

        $writtenWork = $this->singleCategoryScheme($this->offeringA2);
        $test = $this->createAssessment($writtenWork, 'Written Test 1', '1000', '2026-09-05', $this->bravo);
        $this->recordScores($test, [
            $this->candidateInA->id => '744.9',  // 74.49: one hundredth below passing
            $this->secondInA->id => '744.95',   // 74.495, shown as 74.50: exactly passing
            $fourth->id => '745',               // 74.50: exactly passing
            $fifth->id => '797.49',             // 79.749, shown as 79.75: exactly warning
            $sixth->id => '797.4',              // 79.74: one hundredth below warning
        ], $this->bravo);
        $this->finalize($test, $this->bravo);

        $this->actingAs($this->bravo)
            ->get($this->gradebookUrl($this->offeringA2))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('thresholds', ['passingGrade' => 74.5, 'warningGrade' => 79.75])
                ->has('grades.data', 5)
                ->where('grades.data.0.result.grade', 74.49)
                ->where('grades.data.0.result.standing', self::FAILING)
                ->where('grades.data.1.result.grade', 74.5)
                ->where('grades.data.1.result.standing', self::AT_RISK)
                ->where('grades.data.2.result.grade', 74.5)
                ->where('grades.data.2.result.standing', self::AT_RISK)
                ->where('grades.data.3.result.grade', 79.75)
                ->where('grades.data.3.result.standing', self::PASSING)
                ->where('grades.data.4.result.grade', 79.74)
                ->where('grades.data.4.result.standing', self::AT_RISK)
                ->where('grades.data.4.result.isProvisional', false));
    }

    public function test_provisional_grades_get_a_current_standing_and_are_flagged(): void
    {
        $this->setThresholds($this->activePeriod, '75', '80');
        $quiz = $this->createAssessment($this->quizzes, 'Quiz 1', '20', '2026-09-01');
        $this->recordScores($quiz, [$this->candidateInA->id => '18', $this->secondInA->id => '15']);
        $this->finalize($quiz);

        $this->actingAs($this->alpha)
            ->get($this->gradebookUrl($this->offeringA1))
            ->assertInertia(fn (Assert $page) => $page
                // Only Quizzes is assessed, so these are current, not final, results.
                ->where('grades.data.0.result.grade', 90)
                ->where('grades.data.0.result.status.value', 'provisional')
                ->where('grades.data.0.result.isProvisional', true)
                ->where('grades.data.0.result.standing', self::PASSING)
                ->where('grades.data.1.result.grade', 75)
                ->where('grades.data.1.result.isProvisional', true)
                ->where('grades.data.1.result.standing', self::AT_RISK));
    }

    public function test_draft_scores_never_affect_standing(): void
    {
        $this->setThresholds($this->activePeriod, '75', '80');
        $draftExam = $this->createAssessment($this->examinations, 'Midterm Examination', '100', '2026-09-15');
        $this->recordScores($draftExam, [$this->candidateInA->id => '0', $this->secondInA->id => '100']);

        // Nothing is finalized: nothing to judge, and nothing missing.
        $this->actingAs($this->alpha)
            ->get($this->gradebookUrl($this->offeringA1))
            ->assertInertia(fn (Assert $page) => $page
                ->where('grades.data.0.result.grade', null)
                ->where('grades.data.0.result.missingScores', 0)
                ->where('grades.data.0.result.isProvisional', false)
                ->where('grades.data.0.result.standing', null)
                ->where('grades.data.1.result.grade', null)
                ->where('grades.data.1.result.standing', null));

        $quiz = $this->createAssessment($this->quizzes, 'Quiz 1', '20', '2026-09-01');
        $this->recordScores($quiz, [$this->candidateInA->id => '18', $this->secondInA->id => '10']);
        $this->finalize($quiz);

        // Only the finalized quiz counts: a draft 0 does not fail A1 and a
        // draft 100 does not rescue A2.
        $this->actingAs($this->alpha)
            ->get($this->gradebookUrl($this->offeringA1))
            ->assertInertia(fn (Assert $page) => $page
                ->where('grades.data.0.result.grade', 90)
                ->where('grades.data.0.result.standing', self::PASSING)
                ->where('grades.data.1.result.grade', 50)
                ->where('grades.data.1.result.standing', self::FAILING));
    }

    public function test_subject_without_grading_setup_has_no_standing_even_with_thresholds(): void
    {
        $this->setThresholds($this->activePeriod, '75', '80');

        $this->actingAs($this->bravo)
            ->get($this->gradebookUrl($this->offeringA2))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('thresholds', ['passingGrade' => 75, 'warningGrade' => 80])
                ->has('grades.data', 2)
                ->where('grades.data.0.result.status.value', 'not_configured')
                ->where('grades.data.0.result.standing', null)
                ->where('grades.data.1.result.standing', null));
    }

    // ------------------------------------------------------------------
    // Recalculation (MILESTONES.md Milestone 5: changing a score
    // recalculates the correct academic standing)
    // ------------------------------------------------------------------

    public function test_finalizing_an_assessment_recalculates_standing(): void
    {
        $this->setThresholds($this->activePeriod, '75', '80');
        $quiz = $this->createAssessment($this->quizzes, 'Quiz 1', '20', '2026-09-01');
        $this->recordScores($quiz, [$this->candidateInA->id => '18', $this->secondInA->id => '16']);
        $this->finalize($quiz);

        $exam = $this->createAssessment($this->examinations, 'Midterm Examination', '100', '2026-09-15');
        $this->recordScores($exam, [$this->candidateInA->id => '50', $this->secondInA->id => '100']);

        // The examination is still a draft.
        $this->actingAs($this->alpha)
            ->get($this->gradebookUrl($this->offeringA1))
            ->assertInertia(fn (Assert $page) => $page
                ->where('grades.data.0.result.grade', 90)
                ->where('grades.data.0.result.standing', self::PASSING)
                ->where('grades.data.1.result.grade', 80)
                ->where('grades.data.1.result.standing', self::PASSING));

        $this->finalize($exam);

        $this->actingAs($this->alpha)
            ->get($this->gradebookUrl($this->offeringA1))
            ->assertInertia(fn (Assert $page) => $page
                // A1: 90% x 40 + 50% x 60 = 36 + 30 = 66.00.
                ->where('grades.data.0.result.grade', 66)
                ->where('grades.data.0.result.isProvisional', false)
                ->where('grades.data.0.result.standing', self::FAILING)
                // A2: 80% x 40 + 100% x 60 = 32 + 60 = 92.00.
                ->where('grades.data.1.result.grade', 92)
                ->where('grades.data.1.result.isProvisional', false)
                ->where('grades.data.1.result.standing', self::PASSING));
    }

    public function test_correcting_a_finalized_score_recalculates_standing(): void
    {
        $this->setThresholds($this->activePeriod, '75', '80');
        $assessments = $this->recordWorkedExample();

        $this->correct($assessments['midterm'], $this->secondInA, '80');
        $this->correct($assessments['midterm'], $this->candidateInA, '60');

        $this->actingAs($this->alpha)
            ->get($this->gradebookUrl($this->offeringA1))
            ->assertInertia(fn (Assert $page) => $page
                // A1: 78% x 40 + 60% x 60 = 31.2 + 36 = 67.20 (was 77.70, At Risk).
                ->where('grades.data.0.result.grade', 67.2)
                ->where('grades.data.0.result.standing', self::FAILING)
                // A2: 80% x 40 + 80% x 60 = 80.00, exactly the warning grade (was 70.40, Failing).
                ->where('grades.data.1.result.grade', 80)
                ->where('grades.data.1.result.standing', self::PASSING));

        // The candidate profile reflects the correction too.
        $this->actingAs($this->academicAdmin)
            ->get("/candidates/{$this->secondInA->id}")
            ->assertInertia(fn (Assert $page) => $page
                ->where('performance.0.result.standing', self::PASSING)
                ->where('standing.overall.standing', self::PASSING));
    }

    public function test_changing_the_thresholds_recalculates_standing(): void
    {
        $this->recordWorkedExample();
        $this->setThresholds($this->activePeriod, '75', '80');

        $this->actingAs($this->alpha)
            ->get($this->gradebookUrl($this->offeringA1))
            ->assertInertia(fn (Assert $page) => $page
                ->where('grades.data.0.result.standing', self::AT_RISK)
                ->where('grades.data.1.result.standing', self::FAILING));

        $this->setThresholds($this->activePeriod, '70', '77', 'Institutional policy revised.');

        $this->actingAs($this->alpha)
            ->get($this->gradebookUrl($this->offeringA1))
            ->assertInertia(fn (Assert $page) => $page
                ->where('thresholds', ['passingGrade' => 70, 'warningGrade' => 77])
                // 77.70 >= 77: Passing. 70.40 >= 70: At Risk.
                ->where('grades.data.0.result.grade', 77.7)
                ->where('grades.data.0.result.standing', self::PASSING)
                ->where('grades.data.1.result.grade', 70.4)
                ->where('grades.data.1.result.standing', self::AT_RISK));

        $this->setThresholds($this->activePeriod, '78', '85', 'Institutional policy revised again.');

        $this->actingAs($this->alpha)
            ->get($this->gradebookUrl($this->offeringA1))
            ->assertInertia(fn (Assert $page) => $page
                ->where('thresholds', ['passingGrade' => 78, 'warningGrade' => 85])
                ->where('grades.data.0.result.standing', self::FAILING)
                ->where('grades.data.1.result.standing', self::FAILING));

        $this->actingAs($this->academicAdmin)
            ->get("/candidates/{$this->candidateInA->id}")
            ->assertInertia(fn (Assert $page) => $page
                ->where('standing.thresholds', ['passingGrade' => 78, 'warningGrade' => 85])
                ->where('performance.0.result.standing', self::FAILING)
                ->where('standing.overall.standing', self::FAILING));
    }

    public function test_changing_the_grading_weights_recalculates_standing(): void
    {
        $this->setThresholds($this->activePeriod, '75', '80');
        $this->recordWorkedExample();

        $this->app->make(GradingSchemeService::class)->save($this->offeringA1, [
            ['id' => $this->quizzes->id, 'name' => 'Quizzes', 'weight' => '90'],
            ['id' => $this->examinations->id, 'name' => 'Examinations', 'weight' => '10'],
        ], 'Weights revised by the academic board.');

        $this->actingAs($this->alpha)
            ->get($this->gradebookUrl($this->offeringA1))
            ->assertInertia(fn (Assert $page) => $page
                // A1: 78% x 90 + 77.5% x 10 = 70.2 + 7.75 = 77.95 (still At Risk).
                ->where('grades.data.0.result.grade', 77.95)
                ->where('grades.data.0.result.standing', self::AT_RISK)
                // A2: 80% x 90 + 64% x 10 = 72 + 6.4 = 78.40 (was 70.40, Failing).
                ->where('grades.data.1.result.grade', 78.4)
                ->where('grades.data.1.result.standing', self::AT_RISK));
    }

    // ------------------------------------------------------------------
    // Missing scores
    // ------------------------------------------------------------------

    public function test_a_missing_score_turns_passing_into_incomplete_until_it_is_recorded(): void
    {
        $this->setThresholds($this->activePeriod, '75', '80');
        $quiz1 = $this->createAssessment($this->quizzes, 'Quiz 1', '20', '2026-09-01');
        $this->recordScores($quiz1, [$this->candidateInA->id => '18', $this->secondInA->id => '17']);
        $this->finalize($quiz1);

        // Candidate A1 has no score on Quiz 2.
        $quiz2 = $this->createAssessment($this->quizzes, 'Quiz 2', '30', '2026-09-08');
        $this->recordScores($quiz2, [$this->secondInA->id => '27']);
        $this->finalize($quiz2);

        $this->actingAs($this->alpha)
            ->get($this->gradebookUrl($this->offeringA1))
            ->assertInertia(fn (Assert $page) => $page
                // 18 / 20 = 90% would be Passing, but a record is missing.
                ->where('grades.data.0.result.grade', 90)
                ->where('grades.data.0.result.missingScores', 1)
                ->where('grades.data.0.result.status.value', 'missing_scores')
                ->where('grades.data.0.result.standing', self::INCOMPLETE)
                // A2: (17 + 27) / 50 = 88%.
                ->where('grades.data.1.result.grade', 88)
                ->where('grades.data.1.result.missingScores', 0)
                ->where('grades.data.1.result.standing', self::PASSING));

        $this->correct($quiz2, $this->candidateInA, '27');

        $this->actingAs($this->alpha)
            ->get($this->gradebookUrl($this->offeringA1))
            ->assertInertia(fn (Assert $page) => $page
                // (18 + 27) / 50 = 90%, nothing missing any more.
                ->where('grades.data.0.result.grade', 90)
                ->where('grades.data.0.result.missingScores', 0)
                ->where('grades.data.0.result.standing', self::PASSING));
    }

    public function test_missing_scores_keep_at_risk_and_failing_standings(): void
    {
        $this->setThresholds($this->activePeriod, '75', '80');
        $third = $this->candidateInBatchA('A4');

        $quiz1 = $this->createAssessment($this->quizzes, 'Quiz 1', '100', '2026-09-01');
        $this->recordScores($quiz1, [$this->candidateInA->id => '77', $this->secondInA->id => '50', $third->id => '90']);
        $this->finalize($quiz1);

        // Only A4 has a score on Quiz 2.
        $quiz2 = $this->createAssessment($this->quizzes, 'Quiz 2', '100', '2026-09-08');
        $this->recordScores($quiz2, [$third->id => '90']);
        $this->finalize($quiz2);

        $this->actingAs($this->alpha)
            ->get($this->gradebookUrl($this->offeringA1))
            ->assertInertia(fn (Assert $page) => $page
                // A missing record never hides an early warning.
                ->where('grades.data.0.result.grade', 77)
                ->where('grades.data.0.result.missingScores', 1)
                ->where('grades.data.0.result.status.value', 'missing_scores')
                ->where('grades.data.0.result.standing', self::AT_RISK)
                ->where('grades.data.1.result.grade', 50)
                ->where('grades.data.1.result.missingScores', 1)
                ->where('grades.data.1.result.status.value', 'missing_scores')
                ->where('grades.data.1.result.standing', self::FAILING)
                ->where('grades.data.2.result.grade', 90)
                ->where('grades.data.2.result.missingScores', 0)
                ->where('grades.data.2.result.standing', self::PASSING));
    }

    public function test_a_candidate_with_every_score_missing_is_incomplete(): void
    {
        $this->setThresholds($this->activePeriod, '75', '80');
        $quiz = $this->createAssessment($this->quizzes, 'Quiz 1', '20', '2026-09-01');
        $this->recordScores($quiz, [$this->candidateInA->id => '18']);
        $this->finalize($quiz);

        $exam = $this->createAssessment($this->examinations, 'Midterm Examination', '100', '2026-09-15');
        $this->recordScores($exam, [$this->candidateInA->id => '80']);
        $this->finalize($exam);

        $this->actingAs($this->alpha)
            ->get($this->gradebookUrl($this->offeringA1))
            ->assertInertia(fn (Assert $page) => $page
                // A1: 90% x 40 + 80% x 60 = 36 + 48 = 84.00.
                ->where('grades.data.0.result.grade', 84)
                ->where('grades.data.0.result.standing', self::PASSING)
                ->where('grades.data.1.result.grade', null)
                ->where('grades.data.1.result.missingScores', 2)
                ->where('grades.data.1.result.isProvisional', false)
                ->where('grades.data.1.result.status.value', 'missing_scores')
                ->where('grades.data.1.result.standing', self::INCOMPLETE));

        $this->actingAs($this->academicAdmin)
            ->get("/candidates/{$this->secondInA->id}")
            ->assertInertia(fn (Assert $page) => $page
                ->where('performance.0.result.standing', self::INCOMPLETE)
                ->where('standing.overall', [
                    'standing' => self::INCOMPLETE,
                    'basedOnSubjects' => 1,
                    'totalSubjects' => 2,
                    'isProvisional' => false,
                ]));
    }

    // ------------------------------------------------------------------
    // Candidate profile
    // ------------------------------------------------------------------

    public function test_administrator_profile_shows_overall_standing_across_every_subject(): void
    {
        $this->setThresholds($this->activePeriod, '75', '80');
        $this->recordWorkedExample();
        $gradebookResult = $this->gradebookResultFor($this->alpha, $this->offeringA1, $this->candidateInA);

        $response = $this->actingAs($this->academicAdmin)->get("/candidates/{$this->candidateInA->id}");
        $response->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('staff/candidates/show')
                ->where('standing', [
                    // Subject 2 is not set up, so the overall rests on Subject 1 only.
                    'overall' => [
                        'standing' => self::AT_RISK,
                        'basedOnSubjects' => 1,
                        'totalSubjects' => 2,
                        'isProvisional' => false,
                    ],
                    'scope' => 'all',
                    'thresholds' => ['passingGrade' => 75, 'warningGrade' => 80],
                    'monitored' => true,
                    'canConfigureThresholds' => true,
                ])
                ->has('performance', 2)
                ->where('performance.0.classSubjectId', $this->offeringA1->id)
                ->where('performance.0.result.standing', self::AT_RISK)
                ->where('performance.0.result.isProvisional', false)
                ->where('performance.1.classSubjectId', $this->offeringA2->id)
                ->where('performance.1.result.standing', null));

        // The profile and the gradebook show the same calculated result.
        $this->assertSame($gradebookResult, $response->inertiaProps('performance.0.result'));
    }

    public function test_overall_standing_is_the_most_serious_subject_standing(): void
    {
        $this->setThresholds($this->activePeriod, '75', '80');
        $this->recordWorkedExample();

        // Subject 2 with two categories, of which only one is assessed.
        $this->setScheme($this->offeringA2, ['Written Work' => '60', 'Practical Work' => '40']);
        $writtenWork = $this->offeringA2->assessmentCategories()->where('name', 'Written Work')->sole();
        $test = $this->createAssessment($writtenWork, 'Written Test 1', '100', '2026-09-05', $this->bravo);
        $this->recordScores($test, [$this->candidateInA->id => '60', $this->secondInA->id => '95'], $this->bravo);
        $this->finalize($test, $this->bravo);

        // A1: At Risk in Subject 1, Failing (provisional) in Subject 2.
        $this->actingAs($this->academicAdmin)
            ->get("/candidates/{$this->candidateInA->id}")
            ->assertInertia(fn (Assert $page) => $page
                ->where('performance.0.result.standing', self::AT_RISK)
                ->where('performance.1.result.standing', self::FAILING)
                ->where('performance.1.result.isProvisional', true)
                ->where('standing.overall', [
                    'standing' => self::FAILING,
                    'basedOnSubjects' => 2,
                    'totalSubjects' => 2,
                    'isProvisional' => true,
                ]));

        // A2: Failing in Subject 1; Passing in Subject 2 does not hide it.
        $this->actingAs($this->academicAdmin)
            ->get("/candidates/{$this->secondInA->id}")
            ->assertInertia(fn (Assert $page) => $page
                ->where('performance.0.result.standing', self::FAILING)
                ->where('performance.1.result.standing', self::PASSING)
                ->where('standing.overall', [
                    'standing' => self::FAILING,
                    'basedOnSubjects' => 2,
                    'totalSubjects' => 2,
                    'isProvisional' => true,
                ]));
    }

    public function test_overall_incomplete_outranks_passing_but_not_at_risk(): void
    {
        $this->setThresholds($this->activePeriod, '75', '80');
        $quiz1 = $this->createAssessment($this->quizzes, 'Quiz 1', '20', '2026-09-01');
        $this->recordScores($quiz1, [$this->candidateInA->id => '18', $this->secondInA->id => '15']);
        $this->finalize($quiz1);
        // A1 has no score on Quiz 2.
        $quiz2 = $this->createAssessment($this->quizzes, 'Quiz 2', '30', '2026-09-08');
        $this->recordScores($quiz2, [$this->secondInA->id => '24']);
        $this->finalize($quiz2);

        // A2 has no score on the only Subject 2 assessment.
        $writtenWork = $this->singleCategoryScheme($this->offeringA2);
        $test = $this->createAssessment($writtenWork, 'Written Test 1', '100', '2026-09-05', $this->bravo);
        $this->recordScores($test, [$this->candidateInA->id => '95'], $this->bravo);
        $this->finalize($test, $this->bravo);

        // A1: Incomplete in Subject 1 (90.00 with a missing score), Passing in Subject 2.
        $this->actingAs($this->academicAdmin)
            ->get("/candidates/{$this->candidateInA->id}")
            ->assertInertia(fn (Assert $page) => $page
                ->where('performance.0.result.standing', self::INCOMPLETE)
                ->where('performance.1.result.standing', self::PASSING)
                ->where('standing.overall', [
                    'standing' => self::INCOMPLETE,
                    'basedOnSubjects' => 2,
                    'totalSubjects' => 2,
                    'isProvisional' => true,
                ]));

        // A2: At Risk in Subject 1 ((15 + 24) / 50 = 78%), Incomplete in Subject 2.
        $this->actingAs($this->academicAdmin)
            ->get("/candidates/{$this->secondInA->id}")
            ->assertInertia(fn (Assert $page) => $page
                ->where('performance.0.result.grade', 78)
                ->where('performance.0.result.standing', self::AT_RISK)
                ->where('performance.1.result.grade', null)
                ->where('performance.1.result.standing', self::INCOMPLETE)
                ->where('standing.overall', [
                    'standing' => self::AT_RISK,
                    'basedOnSubjects' => 2,
                    'totalSubjects' => 2,
                    'isProvisional' => true,
                ]));
    }

    public function test_instructor_overall_standing_covers_only_the_subjects_they_teach(): void
    {
        $this->setThresholds($this->activePeriod, '75', '80');
        $this->recordPassingAndFailingSubjects();

        // Alpha teaches Subject 1 only, where A1 is Passing. Bravo's Failing
        // subject must not leak into Alpha's view.
        $this->actingAs($this->alpha)
            ->get("/candidates/{$this->candidateInA->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('performance', 1)
                ->where('performance.0.classSubjectId', $this->offeringA1->id)
                ->where('performance.0.result.standing', self::PASSING)
                ->where('standing', [
                    'overall' => [
                        'standing' => self::PASSING,
                        'basedOnSubjects' => 1,
                        'totalSubjects' => 1,
                        'isProvisional' => true,
                    ],
                    'scope' => 'taught',
                    'thresholds' => ['passingGrade' => 75, 'warningGrade' => 80],
                    'monitored' => true,
                    'canConfigureThresholds' => false,
                ]));

        // Bravo teaches Subject 2 only, where A1 is Failing.
        $this->actingAs($this->bravo)
            ->get("/candidates/{$this->candidateInA->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('performance', 1)
                ->where('performance.0.classSubjectId', $this->offeringA2->id)
                ->where('performance.0.result.standing', self::FAILING)
                ->where('standing.scope', 'taught')
                ->where('standing.overall', [
                    'standing' => self::FAILING,
                    'basedOnSubjects' => 1,
                    'totalSubjects' => 1,
                    'isProvisional' => false,
                ]));

        // An administrator sees both subjects.
        $this->actingAs($this->academicAdmin)
            ->get("/candidates/{$this->candidateInA->id}")
            ->assertInertia(fn (Assert $page) => $page
                ->has('performance', 2)
                ->where('standing.scope', 'all')
                ->where('standing.overall', [
                    'standing' => self::FAILING,
                    'basedOnSubjects' => 2,
                    'totalSubjects' => 2,
                    'isProvisional' => true,
                ]));
    }

    public function test_scope_and_threshold_configuration_follow_permissions_not_roles(): void
    {
        $this->setThresholds($this->activePeriod, '75', '80');
        $this->recordPassingAndFailingSubjects();

        // A teaching lead may view every candidate but not configure grading.
        $lead = $this->withCustomRole($this->alpha, 'teaching_lead', [
            PermissionCode::AccessStaffArea,
            PermissionCode::TeachClasses,
            PermissionCode::RecordGrades,
            PermissionCode::ViewAllCandidates,
        ]);

        $this->actingAs($lead)
            ->get("/candidates/{$this->candidateInA->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('performance', 2)
                ->where('standing.scope', 'all')
                ->where('standing.canConfigureThresholds', false)
                ->where('standing.overall.standing', self::FAILING)
                ->where('standing.overall.basedOnSubjects', 2)
                ->where('standing.overall.totalSubjects', 2));

        $this->actingAs($this->userWithRole(SystemRole::SuperAdministrator))
            ->get("/candidates/{$this->candidateInA->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('standing.scope', 'all')
                ->where('standing.canConfigureThresholds', true));
    }

    public function test_profile_without_period_thresholds_has_no_standing(): void
    {
        $this->recordWorkedExample();

        $this->actingAs($this->academicAdmin)
            ->get("/candidates/{$this->candidateInA->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('standing', [
                    'overall' => [
                        'standing' => null,
                        'basedOnSubjects' => 0,
                        'totalSubjects' => 2,
                        'isProvisional' => false,
                    ],
                    'scope' => 'all',
                    'thresholds' => null,
                    'monitored' => true,
                    'canConfigureThresholds' => true,
                ])
                ->where('performance.0.result.grade', 77.7)
                ->where('performance.0.result.standing', null)
                ->where('performance.1.result.standing', null));

        $this->actingAs($this->alpha)
            ->get("/candidates/{$this->candidateInA->id}")
            ->assertInertia(fn (Assert $page) => $page
                ->where('standing.thresholds', null)
                ->where('standing.overall.standing', null)
                ->where('standing.overall.totalSubjects', 1)
                ->where('standing.canConfigureThresholds', false));
    }

    public function test_withdrawn_candidate_keeps_grades_but_has_no_standing(): void
    {
        $this->setThresholds($this->activePeriod, '75', '80');
        $this->recordWorkedExample();
        $this->secondInA->forceFill(['status' => CandidateStatus::Withdrawn])->save();

        $expectedStanding = [
            'overall' => [
                'standing' => null,
                'basedOnSubjects' => 0,
                'totalSubjects' => 2,
                'isProvisional' => false,
            ],
            'scope' => 'all',
            'thresholds' => ['passingGrade' => 75, 'warningGrade' => 80],
            'monitored' => false,
            'canConfigureThresholds' => true,
        ];

        $this->actingAs($this->academicAdmin)
            ->get("/candidates/{$this->secondInA->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('candidate.status.value', 'withdrawn')
                // The recorded grade is kept (it was Failing at 70.40).
                ->where('performance.0.result.grade', 70.4)
                ->where('performance.0.result.status.value', 'complete')
                ->where('performance.0.result.standing', null)
                ->where('performance.1.result.standing', null)
                ->where('standing', $expectedStanding));

        // The fixture's withdrawn candidate has no scores at all: the three
        // finalized assessments are missing, but that is not Incomplete.
        $this->actingAs($this->academicAdmin)
            ->get("/candidates/{$this->withdrawnInA->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('performance.0.result.grade', null)
                ->where('performance.0.result.missingScores', 3)
                ->where('performance.0.result.standing', null)
                ->where('standing', $expectedStanding));

        // The instructor of the class sees the same.
        $this->actingAs($this->alpha)
            ->get("/candidates/{$this->secondInA->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('performance.0.result.grade', 70.4)
                ->where('performance.0.result.standing', null)
                ->where('standing.monitored', false)
                ->where('standing.overall.standing', null)
                ->where('standing.overall.basedOnSubjects', 0)
                ->where('standing.overall.totalSubjects', 1));

        // The gradebook leaves withdrawn candidates out (Milestone 4 behaviour).
        $this->actingAs($this->alpha)
            ->get($this->gradebookUrl($this->offeringA1))
            ->assertInertia(fn (Assert $page) => $page
                ->where('gradableCount', 1)
                ->has('grades.data', 1)
                ->where('grades.data.0.candidate.id', $this->candidateInA->id)
                ->where('grades.data.0.result.standing', self::AT_RISK));

        // Standing is calculated on read: re-enrolling brings it back.
        $this->secondInA->forceFill(['status' => CandidateStatus::Enrolled])->save();

        $this->actingAs($this->academicAdmin)
            ->get("/candidates/{$this->secondInA->id}")
            ->assertInertia(fn (Assert $page) => $page
                ->where('performance.0.result.standing', self::FAILING)
                ->where('standing.monitored', true)
                ->where('standing.overall.standing', self::FAILING)
                ->where('standing.overall.basedOnSubjects', 1));
    }

    public function test_grade_engine_gives_no_standing_to_candidates_who_cannot_be_graded_in_the_class(): void
    {
        $this->setThresholds($this->activePeriod, '75', '80');
        $this->recordWorkedExample();
        $this->secondInA->forceFill(['status' => CandidateStatus::Withdrawn])->save();

        $calculator = $this->app->make(GradeCalculationService::class);

        // Even when asked directly, only gradable candidates get a standing.
        $grades = $calculator->forOffering($this->offeringA1, [
            $this->candidateInA->id,
            $this->secondInA->id,
            $this->withdrawnInA->id,
            $this->candidateInB->id,
        ]);

        $this->assertSame(77.7, $grades[$this->candidateInA->id]->grade);
        $this->assertSame(self::AT_RISK, $grades[$this->candidateInA->id]->standing?->toArray());
        // Withdrawn after being graded: the grade is kept, the standing is not decided.
        $this->assertSame(70.4, $grades[$this->secondInA->id]->grade);
        $this->assertNull($grades[$this->secondInA->id]->standing);
        // Withdrawn without scores: missing scores are not turned into Incomplete.
        $this->assertSame(3, $grades[$this->withdrawnInA->id]->missingScores);
        $this->assertNull($grades[$this->withdrawnInA->id]->standing);
        // A candidate of another class.
        $this->assertNull($grades[$this->candidateInB->id]->standing);

        // The per-candidate calculation follows the same rule.
        $forWithdrawn = $calculator->forCandidate($this->secondInA->fresh(), [$this->offeringA1->id, $this->offeringA2->id]);
        $this->assertSame(70.4, $forWithdrawn[$this->offeringA1->id]->grade);
        $this->assertNull($forWithdrawn[$this->offeringA1->id]->standing);
        $this->assertNull($forWithdrawn[$this->offeringA2->id]->standing);

        $forOtherClass = $calculator->forCandidate($this->candidateInB, [$this->offeringA1->id]);
        $this->assertSame(3, $forOtherClass[$this->offeringA1->id]->missingScores);
        $this->assertNull($forOtherClass[$this->offeringA1->id]->standing);

        $forEnrolled = $calculator->forCandidate($this->candidateInA, [$this->offeringA1->id]);
        $this->assertSame(AcademicStanding::AtRisk, $forEnrolled[$this->offeringA1->id]->standing);
    }

    public function test_candidate_who_moved_class_is_judged_only_in_the_new_class(): void
    {
        $this->setThresholds($this->activePeriod, '75', '80');
        $this->recordWorkedExample();
        // Failing (70.40) in Batch A, then moved to Batch B, whose subject is not set up.
        $this->secondInA->forceFill(['class_batch_id' => $this->batchB->id])->save();

        $this->actingAs($this->academicAdmin)
            ->get("/candidates/{$this->secondInA->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('candidate.classBatch.id', $this->batchB->id)
                ->has('performance', 1)
                ->where('performance.0.classSubjectId', $this->offeringB1->id)
                ->where('performance.0.result.standing', null)
                ->where('standing.monitored', true)
                ->where('standing.overall', [
                    'standing' => null,
                    'basedOnSubjects' => 0,
                    'totalSubjects' => 1,
                    'isProvisional' => false,
                ]));

        // The old class no longer decides a standing for them.
        $grades = $this->app->make(GradeCalculationService::class)->forOffering($this->offeringA1, [$this->secondInA->id]);
        $this->assertSame(70.4, $grades[$this->secondInA->id]->grade);
        $this->assertNull($grades[$this->secondInA->id]->standing);
    }

    public function test_candidate_without_a_class_has_no_standing(): void
    {
        $this->setThresholds($this->activePeriod, '75', '80');
        $unassigned = Candidate::factory()->create(['class_batch_id' => null, 'last_name' => 'Unassigned']);

        $this->actingAs($this->academicAdmin)
            ->get("/candidates/{$unassigned->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('performance', [])
                ->where('standing', [
                    'overall' => [
                        'standing' => null,
                        'basedOnSubjects' => 0,
                        'totalSubjects' => 0,
                        'isProvisional' => false,
                    ],
                    'scope' => 'all',
                    'thresholds' => null,
                    'monitored' => false,
                    'canConfigureThresholds' => true,
                ]));
    }

    // ------------------------------------------------------------------
    // Periods
    // ------------------------------------------------------------------

    public function test_class_in_a_past_period_uses_that_periods_thresholds(): void
    {
        $this->setThresholds($this->activePeriod, '75', '80');
        $this->setThresholds($this->pastPeriod, '60', '70');
        [$oldOffering, $oldCandidate] = $this->recordOldPeriodGrade('65');

        // 65.00 is At Risk at 60 / 70; with the active period's 75 / 80 it would be Failing.
        $this->actingAs($this->alpha)
            ->get($this->gradebookUrl($oldOffering))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('thresholds', ['passingGrade' => 60, 'warningGrade' => 70])
                ->has('grades.data', 1)
                ->where('grades.data.0.candidate.id', $oldCandidate->id)
                ->where('grades.data.0.result.grade', 65)
                ->where('grades.data.0.result.standing', self::AT_RISK));

        $this->actingAs($this->academicAdmin)
            ->get("/candidates/{$oldCandidate->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('candidate.classBatch.periodId', $this->pastPeriod->id)
                ->where('performance.0.result.standing', self::AT_RISK)
                ->where('standing', [
                    'overall' => [
                        'standing' => self::AT_RISK,
                        'basedOnSubjects' => 1,
                        'totalSubjects' => 1,
                        'isProvisional' => false,
                    ],
                    'scope' => 'all',
                    'thresholds' => ['passingGrade' => 60, 'warningGrade' => 70],
                    // Completed candidates are still graded in their class.
                    'monitored' => true,
                    'canConfigureThresholds' => true,
                ]));

        // Changing the active period's thresholds does not rewrite the past period's standings.
        $this->setThresholds($this->activePeriod, '50', '60');

        $this->actingAs($this->alpha)
            ->get($this->gradebookUrl($oldOffering))
            ->assertInertia(fn (Assert $page) => $page
                ->where('thresholds', ['passingGrade' => 60, 'warningGrade' => 70])
                ->where('grades.data.0.result.standing', self::AT_RISK));
    }

    public function test_past_period_without_thresholds_does_not_borrow_the_active_periods(): void
    {
        $this->setThresholds($this->activePeriod, '75', '80');
        [$oldOffering, $oldCandidate] = $this->recordOldPeriodGrade('65');

        $this->actingAs($this->alpha)
            ->get($this->gradebookUrl($oldOffering))
            ->assertInertia(fn (Assert $page) => $page
                ->where('thresholds', null)
                ->where('grades.data.0.result.grade', 65)
                ->where('grades.data.0.result.standing', null));

        $this->actingAs($this->academicAdmin)
            ->get("/candidates/{$oldCandidate->id}")
            ->assertInertia(fn (Assert $page) => $page
                ->where('standing.thresholds', null)
                ->where('standing.overall.standing', null)
                ->where('standing.overall.basedOnSubjects', 0)
                ->where('standing.overall.totalSubjects', 1)
                ->where('performance.0.result.standing', null));
    }

    // ------------------------------------------------------------------
    // Grading setup page
    // ------------------------------------------------------------------

    public function test_grading_setup_page_shows_the_thresholds_of_the_class_period(): void
    {
        $this->actingAs($this->academicAdmin)
            ->get($this->gradingUrl($this->offeringA1))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('staff/classes/grading')
                ->where('thresholds', null)
                ->where('periodId', $this->activePeriod->id));

        $this->setThresholds($this->activePeriod, '75', '80');
        $this->setThresholds($this->pastPeriod, '60.5', '70.25');

        $this->actingAs($this->academicAdmin)
            ->get($this->gradingUrl($this->offeringA1))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('thresholds', ['passingGrade' => 75, 'warningGrade' => 80])
                ->where('periodId', $this->activePeriod->id));

        $this->actingAs($this->academicAdmin)
            ->get($this->gradingUrl($this->oldOffering()))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('thresholds', ['passingGrade' => 60.5, 'warningGrade' => 70.25])
                ->where('periodId', $this->pastPeriod->id));
    }

    // ------------------------------------------------------------------
    // Demo seeder
    // ------------------------------------------------------------------

    public function test_database_seeder_sets_demo_thresholds_on_the_active_demo_period(): void
    {
        $demoPeriod = $this->seedDemoData();

        $this->assertSame('75.00', $demoPeriod->passing_grade);
        $this->assertSame('80.00', $demoPeriod->warning_grade);

        // Set through the service, so the change is audited.
        $audit = AuditLog::query()->where('action', 'grading_thresholds.updated')->sole();
        $this->assertSame($demoPeriod->id, (int) $audit->auditable_id);
        $this->assertSame(['passing_grade' => null, 'warning_grade' => null], $audit->old_values);
        $this->assertSame(['passing_grade' => '75.00', 'warning_grade' => '80.00'], $audit->new_values);

        // Other periods are left without thresholds.
        $this->assertNull($this->activePeriod->fresh()->passing_grade);
        $this->assertNull($this->pastPeriod->fresh()->passing_grade);
    }

    public function test_demo_grading_seeder_keeps_thresholds_an_administrator_changed(): void
    {
        $demoPeriod = $this->seedDemoData();
        $this->setThresholds($demoPeriod, '70', '85', 'Thresholds agreed by the academic board.');

        $this->seed(DemoGradingSeeder::class);

        $demoPeriod->refresh();
        $this->assertSame('70.00', $demoPeriod->passing_grade);
        $this->assertSame('85.00', $demoPeriod->warning_grade);
        $this->assertSame(2, AuditLog::query()->where('action', 'grading_thresholds.updated')->count());
    }

    public function test_demo_grading_seeder_keeps_thresholds_set_before_it_first_runs(): void
    {
        // The fixture period is the active one here.
        $this->setThresholds($this->activePeriod, '60', '70');

        $this->seed(DemoGradingSeeder::class);

        $this->activePeriod->refresh();
        $this->assertSame('60.00', $this->activePeriod->passing_grade);
        $this->assertSame('70.00', $this->activePeriod->warning_grade);
        $this->assertSame(1, AuditLog::query()->where('action', 'grading_thresholds.updated')->count());
    }

    public function test_demo_data_yields_every_academic_standing(): void
    {
        $demoPeriod = $this->seedDemoData();
        $calculator = $this->app->make(GradeCalculationService::class);

        $offerings = ClassSubject::query()
            ->whereHas('classBatch', fn ($classes) => $classes->where('academic_period_id', $demoPeriod->id))
            ->whereHas('instructors')
            ->get();
        $this->assertNotEmpty($offerings);

        $standings = [];
        foreach ($offerings as $offering) {
            $candidateIds = Candidate::query()->gradableIn($offering->class_batch_id)->pluck('id')->all();
            foreach ($calculator->forOffering($offering, $candidateIds) as $subjectGrade) {
                $standings[] = $subjectGrade->standing?->value;
            }
        }

        // Every seeded candidate has finalized assessments, so each one has a standing.
        $this->assertNotContains(null, $standings);
        foreach (['passing', 'at_risk', 'failing', 'incomplete'] as $expected) {
            $this->assertContains($expected, $standings, "The demo data has no {$expected} standing.");
        }
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function setThresholds(AcademicPeriod $period, string $passing, string $warning, ?string $reason = null): void
    {
        $this->app->make(GradingThresholdService::class)->save($period, $passing, $warning, $reason);
    }

    private function gradebookUrl(ClassSubject $offering): string
    {
        return "/my-classes/{$offering->class_batch_id}/subjects/{$offering->id}";
    }

    private function gradingUrl(ClassSubject $offering): string
    {
        return "/classes/{$offering->class_batch_id}/subjects/{$offering->id}/grading";
    }

    private function oldOffering(): ClassSubject
    {
        return $this->offering($this->batchOld, Subject::query()->where('code', 'SUBJ-1')->sole());
    }

    private function candidateInBatchA(string $lastName): Candidate
    {
        return Candidate::factory()->create(['class_batch_id' => $this->batchA->id, 'last_name' => $lastName]);
    }

    /**
     * Gives a class subject a single grading category worth 100%.
     */
    private function singleCategoryScheme(ClassSubject $offering): AssessmentCategory
    {
        $this->setScheme($offering, ['Written Work' => '100']);

        return $offering->assessmentCategories()->sole();
    }

    /**
     * Candidate A1: Quiz 1 18/20, Quiz 2 21/30, Midterm 77.5/100 -> 77.70.
     * Candidate A2: Quiz 1 10/20, Quiz 2 30/30, Midterm 64/100 -> 70.40.
     * All finalized. Extra candidates may be added as id => [quiz 1, quiz 2, midterm].
     *
     * @param  array<int, array{string, string, string}>  $extra
     * @return array{quiz1: Assessment, quiz2: Assessment, midterm: Assessment}
     */
    private function recordWorkedExample(array $extra = []): array
    {
        $scores = [
            $this->candidateInA->id => ['18', '21', '77.5'],
            $this->secondInA->id => ['10', '30', '64'],
        ] + $extra;

        $quiz1 = $this->createAssessment($this->quizzes, 'Quiz 1', '20', '2026-09-01');
        $this->recordScores($quiz1, array_map(fn (array $row): string => $row[0], $scores));
        $this->finalize($quiz1);

        $quiz2 = $this->createAssessment($this->quizzes, 'Quiz 2', '30', '2026-09-08');
        $this->recordScores($quiz2, array_map(fn (array $row): string => $row[1], $scores));
        $this->finalize($quiz2);

        $midterm = $this->createAssessment($this->examinations, 'Midterm Examination', '100', '2026-09-15');
        $this->recordScores($midterm, array_map(fn (array $row): string => $row[2], $scores));
        $this->finalize($midterm);

        return ['quiz1' => $quiz1, 'quiz2' => $quiz2, 'midterm' => $midterm];
    }

    /**
     * Candidate A1 is Passing in Subject 1 (Alpha; 90.00 on quizzes only, so
     * provisional) and Failing in Subject 2 (Bravo; 40.00, complete).
     */
    private function recordPassingAndFailingSubjects(): void
    {
        $quiz = $this->createAssessment($this->quizzes, 'Quiz 1', '20', '2026-09-01');
        $this->recordScores($quiz, [$this->candidateInA->id => '18', $this->secondInA->id => '10']);
        $this->finalize($quiz);

        $writtenWork = $this->singleCategoryScheme($this->offeringA2);
        $test = $this->createAssessment($writtenWork, 'Written Test 1', '100', '2026-09-05', $this->bravo);
        $this->recordScores($test, [$this->candidateInA->id => '40', $this->secondInA->id => '90'], $this->bravo);
        $this->finalize($test, $this->bravo);
    }

    /**
     * Alpha's past-period subject, Quizzes 100%, one finalized quiz out of
     * 100 with the given score for the completed candidate.
     *
     * @return array{ClassSubject, Candidate}
     */
    private function recordOldPeriodGrade(string $score): array
    {
        $oldOffering = $this->oldOffering();
        $oldCandidate = Candidate::query()->where('class_batch_id', $this->batchOld->id)->where('last_name', 'Old1')->sole();

        $this->setScheme($oldOffering, ['Quizzes' => '100']);
        $quiz = $this->createAssessment($oldOffering->assessmentCategories()->sole(), 'Old Quiz', '100', '2026-03-10');
        $this->recordScores($quiz, [$oldCandidate->id => $score]);
        $this->finalize($quiz);

        return [$oldOffering, $oldCandidate];
    }

    /**
     * Records a correction of a finalized score, as an instructor would.
     */
    private function correct(Assessment $assessment, Candidate $candidate, string $score): void
    {
        $existing = $assessment->scores()->where('candidate_id', $candidate->id)->first();

        $this->app->make(ScoreRecordingService::class)->correctFinalizedScore(
            $assessment,
            $candidate->id,
            $score,
            $existing?->comment,
            'Marking error found on review.',
            DecimalValue::normalize($existing?->score),
            $existing?->comment,
            $this->alpha,
        );
    }

    /**
     * Runs the full demo seeding with its own active period, as in the
     * Milestone 4 seeder test, and returns that period.
     */
    private function seedDemoData(): AcademicPeriod
    {
        AcademicPeriod::query()->update(['is_active' => false]);

        // DatabaseSeeder runs the demo seeders in the testing environment.
        $this->seed(DatabaseSeeder::class);

        $demoPeriod = AcademicPeriod::query()->where('name', 'Academic Period 2026-1')->sole();
        $this->assertTrue($demoPeriod->is_active);

        return $demoPeriod;
    }

    /**
     * The calculated result of one candidate as the gradebook shows it.
     *
     * @return array<string, mixed>
     */
    private function gradebookResultFor(User $instructor, ClassSubject $offering, Candidate $candidate): array
    {
        $rows = $this->actingAs($instructor)->get($this->gradebookUrl($offering))->inertiaProps('grades.data');
        $row = collect($rows)->firstWhere('candidate.id', $candidate->id);
        $this->assertNotNull($row, 'The candidate is not in the gradebook.');

        return $row['result'];
    }

    /**
     * @param  list<PermissionCode>  $permissions
     */
    private function withCustomRole(User $user, string $code, array $permissions): User
    {
        $role = Role::query()->create(['code' => $code, 'name' => ucwords(str_replace('_', ' ', $code))]);
        $role->permissions()->sync(Permission::query()
            ->whereIn('code', array_map(fn (PermissionCode $permission): string => $permission->value, $permissions))
            ->pluck('id'));
        $user->role()->associate($role)->save();

        return $user->fresh();
    }
}
