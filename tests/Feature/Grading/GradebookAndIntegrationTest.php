<?php

namespace Tests\Feature\Grading;

use App\Enums\Permission as PermissionCode;
use App\Enums\SystemRole;
use App\Models\AcademicPeriod;
use App\Models\Assessment;
use App\Models\AssessmentCategory;
use App\Models\AssessmentScore;
use App\Models\AssessmentScoreRevision;
use App\Models\AuditLog;
use App\Models\Candidate;
use App\Models\ClassSubject;
use App\Models\InstructorAssignment;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Subject;
use App\Models\User;
use App\Services\Grading\ScoreRecordingService;
use App\Services\InstructorAssignmentService;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoAcademicSeeder;
use Database\Seeders\DemoAccountsSeeder;
use Database\Seeders\DemoGradingSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use RuntimeException;
use Tests\TestCase;

/**
 * Milestone 4: the gradebook page and how grades surface elsewhere
 * (candidate profile, instructor dashboard, teaching class page), plus the
 * demo grading seeder.
 */
class GradebookAndIntegrationTest extends TestCase
{
    use BuildsGradingFixtures;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildGradingFixtures();
    }

    // ------------------------------------------------------------------
    // Gradebook authorization
    // ------------------------------------------------------------------

    public function test_assigned_instructor_opens_the_gradebook_of_a_subject_they_teach(): void
    {
        $this->actingAs($this->alpha)
            ->get($this->gradebookUrl($this->offeringA1))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('staff/teaching/gradebook/show')
                ->where('offering', [
                    'id' => $this->offeringA1->id,
                    'classBatch' => ['id' => $this->batchA->id, 'name' => 'Sample Batch A'],
                    'subject' => ['code' => 'SUBJ-1', 'name' => 'Subject 1'],
                    'period' => ['name' => 'Period Current', 'isActive' => true],
                ])
                ->where('filters', ['search' => ''])
                ->where('can.recordGrades', true));
    }

    public function test_instructor_keeps_access_to_past_period_subjects_they_taught(): void
    {
        $oldOffering = $this->oldOffering();

        $this->actingAs($this->alpha)
            ->get($this->gradebookUrl($oldOffering))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('staff/teaching/gradebook/show')
                ->where('offering.id', $oldOffering->id)
                ->where('offering.period', ['name' => 'Period Past', 'isActive' => false])
                // One completed candidate, still gradable (not withdrawn).
                ->where('gradableCount', 1)
                ->has('grades.data', 1));

        $this->actingAs($this->bravo)->get($this->gradebookUrl($oldOffering))->assertForbidden();
    }

    public function test_instructors_cannot_open_gradebooks_of_subjects_they_do_not_teach(): void
    {
        // Another instructor's subject in another class.
        $this->actingAs($this->bravo)->get($this->gradebookUrl($this->offeringA1))->assertForbidden();
        // Another subject of a class the instructor does teach in.
        $this->actingAs($this->alpha)->get($this->gradebookUrl($this->offeringA2))->assertForbidden();
        // The same subject in a class the instructor does not teach.
        $this->actingAs($this->alpha)->get($this->gradebookUrl($this->offeringB1))->assertForbidden();
    }

    public function test_every_instructor_assigned_to_a_subject_can_open_its_gradebook(): void
    {
        $this->teach($this->bravo, $this->offeringA1);

        $this->actingAs($this->bravo)
            ->get($this->gradebookUrl($this->offeringA1))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('offering.id', $this->offeringA1->id)->where('can.recordGrades', true));
    }

    public function test_administrators_and_candidates_cannot_open_gradebooks(): void
    {
        $url = $this->gradebookUrl($this->offeringA1);

        // Administrators configure grading but do not teach.
        $this->actingAs($this->academicAdmin)->get($url)->assertForbidden();
        $this->actingAs($this->userWithRole(SystemRole::SuperAdministrator))->get($url)->assertForbidden();
        $this->actingAs($this->candidateInA->user)->get($url)->assertForbidden();
    }

    public function test_guests_are_sent_to_sign_in(): void
    {
        $this->get($this->gradebookUrl($this->offeringA1))->assertRedirect('/login');
    }

    public function test_subject_must_belong_to_the_class_in_the_url(): void
    {
        // Alpha teaches offering A1, but it is not a subject of Batch B.
        $this->actingAs($this->alpha)
            ->get("/my-classes/{$this->batchB->id}/subjects/{$this->offeringA1->id}")
            ->assertNotFound();

        $this->actingAs($this->alpha)
            ->get("/my-classes/{$this->batchA->id}/subjects/999999")
            ->assertNotFound();
    }

    public function test_access_ends_when_the_assignment_is_removed(): void
    {
        $url = $this->gradebookUrl($this->offeringA1);
        $this->actingAs($this->alpha)->get($url)->assertOk();

        $assignment = InstructorAssignment::query()
            ->where('instructor_id', $this->alpha->id)
            ->where('class_subject_id', $this->offeringA1->id)
            ->sole();
        $this->app->make(InstructorAssignmentService::class)->unassign($assignment);

        $this->actingAs($this->alpha->fresh())->get($url)->assertForbidden();
    }

    public function test_deactivated_instructor_cannot_open_the_gradebook(): void
    {
        $this->alpha->forceFill(['is_active' => false])->save();

        $this->actingAs($this->alpha->fresh())
            ->get($this->gradebookUrl($this->offeringA1))
            ->assertRedirect('/login');

        $this->assertFalse($this->alpha->fresh()->can('viewGradebook', $this->offeringA1));
    }

    public function test_teaching_role_without_grade_recording_can_view_but_not_record(): void
    {
        $viewer = $this->withCustomRole($this->alpha, 'grade_viewer', [
            PermissionCode::AccessStaffArea,
            PermissionCode::TeachClasses,
        ]);

        $this->actingAs($viewer)
            ->get($this->gradebookUrl($this->offeringA1))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('staff/teaching/gradebook/show')
                ->where('can.recordGrades', false));

        $this->actingAs($viewer)
            ->get($this->gradebookUrl($this->offeringA1).'/assessments/create')
            ->assertForbidden();

        $this->actingAs($viewer)
            ->post($this->gradebookUrl($this->offeringA1).'/assessments', [
                'title' => 'Quiz 1',
                'assessment_category_id' => $this->quizzes->id,
                'max_score' => '20',
                'assessed_on' => null,
            ])
            ->assertForbidden();

        $this->assertSame(0, Assessment::query()->count());
    }

    // ------------------------------------------------------------------
    // Gradebook props
    // ------------------------------------------------------------------

    public function test_gradebook_lists_the_scheme_and_assessments_with_undated_last(): void
    {
        $this->createAssessment($this->quizzes, 'Quiz B', '20', '2026-09-10');
        $this->createAssessment($this->examinations, 'Undated Task', '100');
        $quizA = $this->createAssessment($this->quizzes, 'Quiz A', '50', '2026-09-10');
        $this->createAssessment($this->quizzes, 'Diagnostic', '10', '2026-08-20');

        // One score, and one absence recorded without a score.
        $this->app->make(ScoreRecordingService::class)->recordDraftScores($quizA, [
            $this->candidateInA->id => ['score' => '45', 'comment' => null, 'expected_score' => null, 'expected_comment' => null],
            $this->secondInA->id => ['score' => null, 'comment' => 'Absent', 'expected_score' => null, 'expected_comment' => null],
        ], $this->alpha);
        $this->finalize($quizA);

        $this->actingAs($this->alpha)
            ->get($this->gradebookUrl($this->offeringA1))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('scheme', [
                    ['id' => $this->quizzes->id, 'name' => 'Quizzes', 'weight' => '40', 'assessmentCount' => 3],
                    ['id' => $this->examinations->id, 'name' => 'Examinations', 'weight' => '60', 'assessmentCount' => 1],
                ])
                ->has('assessments', 4)
                // By date, then title; undated last.
                ->where('assessments.0.title', 'Diagnostic')
                ->where('assessments.1', [
                    'id' => $quizA->id,
                    'title' => 'Quiz A',
                    'category' => ['id' => $this->quizzes->id, 'name' => 'Quizzes'],
                    'maxScore' => '50',
                    'assessedOn' => '2026-09-10',
                    'status' => ['value' => 'finalized', 'label' => 'Finalized', 'tone' => 'success'],
                    // The absence has no score, so it is not counted.
                    'scoredCount' => 1,
                ])
                ->where('assessments.2.title', 'Quiz B')
                ->where('assessments.2.status.value', 'draft')
                ->where('assessments.2.scoredCount', 0)
                ->where('assessments.3.title', 'Undated Task')
                ->where('assessments.3.assessedOn', null));
    }

    public function test_gradable_count_and_rows_exclude_withdrawn_candidates(): void
    {
        $this->actingAs($this->alpha)
            ->get($this->gradebookUrl($this->offeringA1))
            ->assertInertia(fn (Assert $page) => $page
                ->where('gradableCount', 2)
                ->has('grades.data', 2)
                ->where('grades.data.0.candidate.id', $this->candidateInA->id)
                ->where('grades.data.0.candidate.candidateNumber', $this->candidateInA->candidate_number)
                ->where('grades.data.0.candidate.name', 'Candidate A1')
                ->where('grades.data.0.candidate.status.value', 'enrolled')
                ->where('grades.data.1.candidate.id', $this->secondInA->id)
                ->where('grades.total', 2));

        // Search for the withdrawn candidate finds nothing.
        $this->actingAs($this->alpha)
            ->get($this->gradebookUrl($this->offeringA1).'?search=A3')
            ->assertInertia(fn (Assert $page) => $page->has('grades.data', 0)->where('gradableCount', 2));
    }

    // ------------------------------------------------------------------
    // Calculated grades
    // (Page props are JSON: whole numbers such as 78.0 arrive as 78.)
    // ------------------------------------------------------------------

    public function test_grades_follow_the_worked_example_across_both_categories(): void
    {
        $this->recordWorkedExample();

        $this->actingAs($this->alpha)
            ->get($this->gradebookUrl($this->offeringA1))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                // Candidate A1: Quizzes (18 + 21) / (20 + 30) = 78% of 40,
                // Examinations 77.5% of 60 -> 31.2 + 46.5 = 77.70.
                ->where('grades.data.0.result', [
                    'grade' => 77.7,
                    'assessedWeight' => 100,
                    'missingScores' => 0,
                    'pendingCategories' => 0,
                    'isProvisional' => false,
                    'status' => ['value' => 'complete', 'label' => 'Complete', 'tone' => 'success'],
                    // The fixture period has no passing and warning grades.
                    'standing' => null,
                    'categories' => [
                        [
                            'categoryId' => $this->quizzes->id,
                            'name' => 'Quizzes',
                            'weight' => 40,
                            'assessmentCount' => 2,
                            'scoredCount' => 2,
                            'missingCount' => 0,
                            'earned' => 39,
                            'possible' => 50,
                            'percentage' => 78,
                            'weightedScore' => 31.2,
                        ],
                        [
                            'categoryId' => $this->examinations->id,
                            'name' => 'Examinations',
                            'weight' => 60,
                            'assessmentCount' => 1,
                            'scoredCount' => 1,
                            'missingCount' => 0,
                            'earned' => 77.5,
                            'possible' => 100,
                            'percentage' => 77.5,
                            'weightedScore' => 46.5,
                        ],
                    ],
                ])
                // Candidate A2: Quizzes (10 + 30) / 50 = 80% (points, not the
                // 75% average of 50% and 100%) -> 32.0; Examinations 64% -> 38.4.
                ->where('grades.data.1.result.grade', 70.4)
                ->where('grades.data.1.result.categories.0.percentage', 80)
                ->where('grades.data.1.result.categories.0.weightedScore', 32)
                ->where('grades.data.1.result.categories.1.percentage', 64)
                ->where('grades.data.1.result.categories.1.weightedScore', 38.4)
                ->where('grades.data.1.result.status.value', 'complete'));
    }

    public function test_grades_are_rounded_half_up_only_at_the_end(): void
    {
        $quiz = $this->createAssessment($this->quizzes, 'Long Quiz', '1000', '2026-09-01');
        $this->recordScores($quiz, [$this->candidateInA->id => '844.94', $this->secondInA->id => '844.85']);
        $this->finalize($quiz);

        $exam = $this->createAssessment($this->examinations, 'Long Examination', '1000', '2026-09-15');
        $this->recordScores($exam, [$this->candidateInA->id => '700.13']);
        $this->finalize($exam);

        $this->actingAs($this->alpha)
            ->get($this->gradebookUrl($this->offeringA1))
            ->assertInertia(fn (Assert $page) => $page
                // 84.494% x 40% + 70.013% x 60% = 75.8054 -> 75.81. Rounding the
                // category percentages first (84.49, 70.01) would give 75.80.
                ->where('grades.data.0.result.categories.0.percentage', 84.49)
                ->where('grades.data.0.result.categories.1.percentage', 70.01)
                ->where('grades.data.0.result.grade', 75.81)
                // 844.85 / 1000 = 84.485% -> 84.49 (half up, not to even); A2 has no exam score.
                ->where('grades.data.1.result.categories.0.percentage', 84.49)
                ->where('grades.data.1.result.grade', 84.49)
                ->where('grades.data.1.result.status.value', 'missing_scores'));
    }

    public function test_draft_assessments_never_affect_grades(): void
    {
        // Only a draft with scores: nothing counts yet.
        $draft = $this->createAssessment($this->examinations, 'Final Examination', '100', '2026-10-30');
        $this->recordScores($draft, [$this->candidateInA->id => '0', $this->secondInA->id => '100']);

        $this->actingAs($this->alpha)
            ->get($this->gradebookUrl($this->offeringA1))
            ->assertInertia(fn (Assert $page) => $page
                ->where('grades.data.0.result.grade', null)
                ->where('grades.data.0.result.assessedWeight', 0)
                ->where('grades.data.0.result.missingScores', 0)
                ->where('grades.data.0.result.pendingCategories', 2)
                ->where('grades.data.0.result.status', ['value' => 'no_grades', 'label' => 'No Grades Yet', 'tone' => 'neutral'])
                ->where('grades.data.0.result.categories.1.assessmentCount', 0)
                ->where('grades.data.1.result.status.value', 'no_grades')
                ->where('assessments.0.scoredCount', 2));

        // With finalized assessments, the draft still changes nothing.
        $this->recordWorkedExample();

        $this->actingAs($this->alpha)
            ->get($this->gradebookUrl($this->offeringA1))
            ->assertInertia(fn (Assert $page) => $page
                ->where('grades.data.0.result.grade', 77.7)
                ->where('grades.data.0.result.categories.1.assessmentCount', 1)
                ->where('grades.data.0.result.categories.1.earned', 77.5)
                ->where('grades.data.1.result.grade', 70.4)
                ->where('grades.data.1.result.status.value', 'complete'));
    }

    public function test_grades_are_provisional_until_every_category_is_assessed(): void
    {
        $quiz = $this->createAssessment($this->quizzes, 'Quiz 1', '20', '2026-09-01');
        $this->recordScores($quiz, [$this->candidateInA->id => '18', $this->secondInA->id => '10']);
        $this->finalize($quiz);

        $this->actingAs($this->alpha)
            ->get($this->gradebookUrl($this->offeringA1))
            ->assertInertia(fn (Assert $page) => $page
                // 90% of the 40% assessed so far -> current grade 90.00.
                ->where('grades.data.0.result.grade', 90)
                ->where('grades.data.0.result.assessedWeight', 40)
                ->where('grades.data.0.result.pendingCategories', 1)
                ->where('grades.data.0.result.missingScores', 0)
                ->where('grades.data.0.result.status', ['value' => 'provisional', 'label' => 'In Progress', 'tone' => 'info'])
                ->where('grades.data.0.result.categories.1.percentage', null)
                ->where('grades.data.0.result.categories.1.weightedScore', null)
                ->where('grades.data.1.result.grade', 50)
                ->where('grades.data.1.result.status.value', 'provisional'));
    }

    public function test_missing_scores_are_counted_and_never_treated_as_zero(): void
    {
        $quiz1 = $this->createAssessment($this->quizzes, 'Quiz 1', '20', '2026-09-01');
        $this->recordScores($quiz1, [$this->candidateInA->id => '18', $this->secondInA->id => '10']);
        $this->finalize($quiz1);

        // Candidate A2 is absent from Quiz 2 and has no score on the examination.
        $quiz2 = $this->createAssessment($this->quizzes, 'Quiz 2', '30', '2026-09-08');
        $this->app->make(ScoreRecordingService::class)->recordDraftScores($quiz2, [
            $this->candidateInA->id => ['score' => '21', 'comment' => null, 'expected_score' => null, 'expected_comment' => null],
            $this->secondInA->id => ['score' => null, 'comment' => 'Absent', 'expected_score' => null, 'expected_comment' => null],
        ], $this->alpha);
        $this->finalize($quiz2);

        $exam = $this->createAssessment($this->examinations, 'Midterm Examination', '100', '2026-09-15');
        $this->recordScores($exam, [$this->candidateInA->id => '77.5']);
        $this->finalize($exam);

        $this->actingAs($this->alpha)
            ->get($this->gradebookUrl($this->offeringA1))
            ->assertInertia(fn (Assert $page) => $page
                ->where('grades.data.0.result.grade', 77.7)
                ->where('grades.data.0.result.status.value', 'complete')
                // Only Quiz 1 counts for A2: 10 / 20 = 50% over the 40% assessed.
                // Treating the gaps as zero would give 8.00.
                ->where('grades.data.1.result.grade', 50)
                ->where('grades.data.1.result.assessedWeight', 40)
                ->where('grades.data.1.result.missingScores', 2)
                ->where('grades.data.1.result.pendingCategories', 0)
                ->where('grades.data.1.result.status', ['value' => 'missing_scores', 'label' => 'Missing Scores', 'tone' => 'warning'])
                ->where('grades.data.1.result.categories.0.earned', 10)
                ->where('grades.data.1.result.categories.0.possible', 20)
                ->where('grades.data.1.result.categories.0.scoredCount', 1)
                ->where('grades.data.1.result.categories.0.missingCount', 1)
                ->where('grades.data.1.result.categories.1.missingCount', 1)
                ->where('grades.data.1.result.categories.1.percentage', null));
    }

    public function test_subject_without_grading_scheme_reports_not_configured(): void
    {
        $this->actingAs($this->bravo)
            ->get($this->gradebookUrl($this->offeringA2))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('scheme', [])
                ->where('assessments', [])
                ->where('gradableCount', 2)
                ->has('grades.data', 2)
                ->where('grades.data.0.result.grade', null)
                ->where('grades.data.0.result.categories', [])
                ->where('grades.data.0.result.status', ['value' => 'not_configured', 'label' => 'Not Set Up', 'tone' => 'neutral'])
                ->where('grades.data.1.result.status.value', 'not_configured'));
    }

    // ------------------------------------------------------------------
    // Search and pagination
    // ------------------------------------------------------------------

    public function test_gradebook_can_be_searched_by_candidate_number_or_name(): void
    {
        $this->actingAs($this->alpha)
            ->get($this->gradebookUrl($this->offeringA1).'?search='.urlencode($this->candidateInA->candidate_number))
            ->assertInertia(fn (Assert $page) => $page
                ->where('filters.search', $this->candidateInA->candidate_number)
                ->has('grades.data', 1)
                ->where('grades.data.0.candidate.id', $this->candidateInA->id)
                // The class size is unaffected by the search.
                ->where('gradableCount', 2));

        $this->actingAs($this->alpha)
            ->get($this->gradebookUrl($this->offeringA1).'?search=A2')
            ->assertInertia(fn (Assert $page) => $page
                ->has('grades.data', 1)
                ->where('grades.data.0.candidate.name', 'Candidate A2'));

        $this->actingAs($this->alpha)
            ->get($this->gradebookUrl($this->offeringA1).'?search='.urlencode('Candidate A1'))
            ->assertInertia(fn (Assert $page) => $page
                ->has('grades.data', 1)
                ->where('grades.data.0.candidate.id', $this->candidateInA->id));

        // Candidates of other classes are never matched.
        $this->actingAs($this->alpha)
            ->get($this->gradebookUrl($this->offeringA1).'?search=B1')
            ->assertInertia(fn (Assert $page) => $page->has('grades.data', 0));
    }

    public function test_gradebook_shows_ten_candidates_per_page(): void
    {
        Candidate::factory()->count(55)->create(['class_batch_id' => $this->batchA->id]);

        $response = $this->actingAs($this->alpha)->get($this->gradebookUrl($this->offeringA1));
        $response->assertInertia(fn (Assert $page) => $page
            ->where('gradableCount', 57)
            ->has('grades.data', 10)
            ->where('grades.per_page', 10)
            ->where('grades.total', 57)
            ->where('grades.current_page', 1)
            ->where('grades.last_page', 6));

        $this->actingAs($this->alpha)
            ->get($this->gradebookUrl($this->offeringA1).'?page=6')
            ->assertInertia(fn (Assert $page) => $page
                ->has('grades.data', 7)
                ->where('grades.current_page', 6));

        // The search is kept when moving between pages.
        $searched = $this->actingAs($this->alpha)->get($this->gradebookUrl($this->offeringA1).'?search=Candidate');
        $searched->assertInertia(fn (Assert $page) => $page->where('grades.total', 57)->has('grades.data', 10));
        $this->assertStringContainsString('search=Candidate', (string) $searched->inertiaProps('grades.next_page_url'));
    }

    // ------------------------------------------------------------------
    // Candidate profile
    // ------------------------------------------------------------------

    public function test_instructor_sees_performance_only_for_subjects_they_teach(): void
    {
        $this->recordWorkedExample();
        $gradebookResult = $this->gradebookResultFor($this->alpha, $this->offeringA1, $this->candidateInA);

        $response = $this->actingAs($this->alpha)->get("/candidates/{$this->candidateInA->id}");
        $response->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('staff/candidates/show')
                ->has('performance', 1)
                ->where('performance.0.classSubjectId', $this->offeringA1->id)
                ->where('performance.0.code', 'SUBJ-1')
                ->where('performance.0.name', 'Subject 1')
                ->where('performance.0.canOpenGradebook', true)
                ->where('performance.0.result.grade', 77.7)
                // The existing subjects list is unchanged.
                ->where('subjects', [['code' => 'SUBJ-1', 'name' => 'Subject 1', 'instructors' => ['Instructor Alpha']]]));

        $this->assertSame($gradebookResult, $response->inertiaProps('performance.0.result'));

        // Bravo teaches only Subject 2 in this class.
        $this->actingAs($this->bravo)
            ->get("/candidates/{$this->candidateInA->id}")
            ->assertInertia(fn (Assert $page) => $page
                ->has('performance', 1)
                ->where('performance.0.classSubjectId', $this->offeringA2->id)
                ->where('performance.0.canOpenGradebook', true)
                ->where('performance.0.result.status.value', 'not_configured'));
    }

    public function test_administrator_sees_performance_for_every_subject_of_the_class(): void
    {
        $this->recordWorkedExample();
        $gradebookResult = $this->gradebookResultFor($this->alpha, $this->offeringA1, $this->secondInA);

        $response = $this->actingAs($this->academicAdmin)->get("/candidates/{$this->secondInA->id}");
        $response->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('performance', 2)
                ->where('performance.0.classSubjectId', $this->offeringA1->id)
                ->where('performance.0.name', 'Subject 1')
                ->where('performance.0.canOpenGradebook', false)
                ->where('performance.0.result.grade', 70.4)
                ->where('performance.1.classSubjectId', $this->offeringA2->id)
                ->where('performance.1.name', 'Subject 2')
                ->where('performance.1.canOpenGradebook', false)
                ->where('performance.1.result.status.value', 'not_configured')
                ->has('subjects', 2));

        $this->assertSame($gradebookResult, $response->inertiaProps('performance.0.result'));
    }

    public function test_teaching_viewer_with_view_all_opens_only_the_gradebooks_they_teach(): void
    {
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
                ->where('performance.0.classSubjectId', $this->offeringA1->id)
                ->where('performance.0.canOpenGradebook', true)
                ->where('performance.1.classSubjectId', $this->offeringA2->id)
                ->where('performance.1.canOpenGradebook', false));
    }

    public function test_candidate_without_a_class_has_no_performance(): void
    {
        $unassigned = Candidate::factory()->create(['class_batch_id' => null, 'last_name' => 'Unassigned']);

        $this->actingAs($this->academicAdmin)
            ->get("/candidates/{$unassigned->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('performance', [])->where('subjects', []));
    }

    // ------------------------------------------------------------------
    // Instructor dashboard
    // ------------------------------------------------------------------

    public function test_dashboard_assignments_identify_each_gradebook(): void
    {
        $this->actingAs($this->alpha)
            ->get('/dashboard')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('teaching.assignments', 1)
                ->where('teaching.assignments.0.classSubjectId', $this->offeringA1->id)
                ->where('teaching.upcomingAssessments', []));

        // Sorted by subject, then class: Subject 1 / Batch B, then Subject 2 / Batch A.
        $this->actingAs($this->bravo)
            ->get('/dashboard')
            ->assertInertia(fn (Assert $page) => $page
                ->has('teaching.assignments', 2)
                ->where('teaching.assignments.0.classSubjectId', $this->offeringB1->id)
                ->where('teaching.assignments.1.classSubjectId', $this->offeringA2->id));
    }

    public function test_dashboard_lists_the_next_five_upcoming_assessments_of_the_instructor(): void
    {
        config(['institution.timezone' => 'Asia/Manila']);
        // 17:00 UTC on 5 October is already 01:00 on 6 October in Manila.
        $this->travelTo(Carbon::parse('2026-10-05 17:00:00', 'UTC'));

        $this->createUpcomingFixtures();

        $response = $this->actingAs($this->alpha)->get('/dashboard');
        $response->assertOk();

        $upcoming = $response->inertiaProps('teaching.upcomingAssessments');
        $this->assertSame(
            ['Quiz Today', 'Quiz 3', 'Exam A', 'Exam B', 'Quiz 4'],
            array_column($upcoming, 'title'),
        );

        $today = Assessment::query()->where('title', 'Quiz Today')->sole();
        $this->assertSame([
            'id' => $today->id,
            'title' => 'Quiz Today',
            'assessedOn' => '2026-10-06',
            'subject' => 'Subject 1',
            'classBatch' => 'Sample Batch A',
            'status' => ['value' => 'finalized', 'label' => 'Finalized', 'tone' => 'success'],
        ], $upcoming[0]);
        $this->assertSame(['value' => 'draft', 'label' => 'Draft', 'tone' => 'neutral'], $upcoming[1]['status']);

        // Bravo sees only their own subject's assessment.
        $bravoUpcoming = $this->actingAs($this->bravo)->get('/dashboard')->inertiaProps('teaching.upcomingAssessments');
        $this->assertSame(['Bravo Quiz'], array_column($bravoUpcoming, 'title'));
        $this->assertSame('Subject 2', $bravoUpcoming[0]['subject']);
    }

    public function test_upcoming_assessments_use_the_institution_timezone_for_today(): void
    {
        $this->travelTo(Carbon::parse('2026-10-05 17:00:00', 'UTC'));
        $this->createUpcomingFixtures();

        // In UTC it is still 5 October, so the assessment dated that day is upcoming.
        config(['institution.timezone' => 'UTC']);
        $utc = $this->actingAs($this->alpha)->get('/dashboard')->inertiaProps('teaching.upcomingAssessments');
        $this->assertSame(
            ['Quiz Yesterday', 'Quiz Today', 'Quiz 3', 'Exam A', 'Exam B'],
            array_column($utc, 'title'),
        );

        // In Manila it is already 6 October.
        config(['institution.timezone' => 'Asia/Manila']);
        $manila = $this->actingAs($this->alpha)->get('/dashboard')->inertiaProps('teaching.upcomingAssessments');
        $this->assertNotContains('Quiz Yesterday', array_column($manila, 'title'));
        $this->assertSame('Quiz Today', $manila[0]['title']);
    }

    // ------------------------------------------------------------------
    // Teaching class page
    // ------------------------------------------------------------------

    public function test_teaching_class_page_identifies_the_gradebook_of_each_subject(): void
    {
        $this->actingAs($this->alpha)
            ->get("/my-classes/{$this->batchA->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('staff/teaching/classes/show')
                ->where('subjects', [['classSubjectId' => $this->offeringA1->id, 'code' => 'SUBJ-1', 'name' => 'Subject 1']]));

        $this->teach($this->bravo, $this->offeringA1);

        $this->actingAs($this->bravo)
            ->get("/my-classes/{$this->batchA->id}")
            ->assertInertia(fn (Assert $page) => $page
                ->where('subjects', [
                    ['classSubjectId' => $this->offeringA1->id, 'code' => 'SUBJ-1', 'name' => 'Subject 1'],
                    ['classSubjectId' => $this->offeringA2->id, 'code' => 'SUBJ-2', 'name' => 'Subject 2'],
                ]));
    }

    // ------------------------------------------------------------------
    // Demo seeder
    // ------------------------------------------------------------------

    public function test_demo_grading_seeder_creates_grading_data_once(): void
    {
        // Let the demo seeders create and activate their own period, so the
        // demo grading data is not mixed with the test fixtures.
        AcademicPeriod::query()->update(['is_active' => false]);

        // DatabaseSeeder runs the demo seeders in the testing environment.
        $this->seed(DatabaseSeeder::class);

        $demoPeriod = AcademicPeriod::query()->where('name', 'Academic Period 2026-1')->sole();
        $this->assertTrue($demoPeriod->is_active);

        $offerings = ClassSubject::query()
            ->whereHas('classBatch', fn ($classes) => $classes->where('academic_period_id', $demoPeriod->id))
            ->whereHas('instructors')
            ->with(['assessmentCategories', 'assessments.scores'])
            ->get();
        $this->assertCount(8, $offerings);

        $absences = 0;
        foreach ($offerings as $offering) {
            $this->assertSame(
                ['Quizzes', 'Examinations', 'Practical Exercises', 'Other Requirements'],
                $offering->assessmentCategories->pluck('name')->all(),
            );
            $this->assertSame(10000, $offering->assessmentCategories->sum(fn (AssessmentCategory $category): int => (int) round((float) $category->weight * 100)));

            $assessments = $offering->assessments->keyBy('title');
            $this->assertEqualsCanonicalizing(['Quiz 1', 'Practical Exercise 1', 'Quiz 2', 'Midterm Examination'], $assessments->keys()->all());
            $this->assertTrue($assessments['Quiz 1']->isFinalized());
            $this->assertTrue($assessments['Practical Exercise 1']->isFinalized());
            $this->assertTrue($assessments['Quiz 2']->isDraft());
            $this->assertTrue($assessments['Midterm Examination']->isDraft());

            $gradable = Candidate::query()->gradableIn($offering->class_batch_id)->count();
            $this->assertGreaterThan(0, $gradable);
            $scored = fn (Assessment $assessment): int => $assessment->scores->whereNotNull('score')->count();
            $this->assertSame($gradable, $scored($assessments['Quiz 1']));
            $this->assertSame($gradable, $assessments['Practical Exercise 1']->scores->count());
            $this->assertSame((int) round($gradable * 0.6), $scored($assessments['Quiz 2']));
            $this->assertSame(0, $assessments['Midterm Examination']->scores->count());

            foreach ($offering->assessments as $assessment) {
                foreach ($assessment->scores->whereNotNull('score') as $score) {
                    $this->assertGreaterThanOrEqual(0, (float) $score->score);
                    $this->assertLessThanOrEqual((float) $assessment->max_score, (float) $score->score);
                }
            }

            $absences += $assessments['Practical Exercise 1']->scores->whereNull('score')->count();
        }
        // One excused absence gives the demo a Missing Scores status.
        $this->assertSame(1, $absences);

        // Scores were written through the services, so their history exists.
        $this->assertSame(AssessmentScore::query()->count(), AssessmentScoreRevision::query()->where('kind', 'recorded')->count());
        $this->assertSame(16, AuditLog::query()->where('action', 'assessment.finalized')->count());

        // The fixture subjects of the (now inactive) test period are untouched.
        $this->assertSame(0, $this->offeringA2->assessmentCategories()->count());
        $this->assertSame(0, Assessment::query()->where('class_subject_id', $this->offeringA1->id)->count());

        // Running the seeders again changes nothing.
        $before = $this->gradingCounts();
        $this->seed([DemoAccountsSeeder::class, DemoAcademicSeeder::class, DemoGradingSeeder::class]);
        $this->assertSame($before, $this->gradingCounts());
    }

    public function test_demo_grading_seeder_never_runs_in_production(): void
    {
        $before = $this->gradingCounts();
        $this->app->detectEnvironment(fn (): string => 'production');

        try {
            $this->app->make(DemoGradingSeeder::class)->run();
            $this->fail('The demo grading seeder ran in production.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('production', $exception->getMessage());
        }

        $this->assertSame($before, $this->gradingCounts());
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function gradebookUrl(ClassSubject $offering): string
    {
        return "/my-classes/{$offering->class_batch_id}/subjects/{$offering->id}";
    }

    private function oldOffering(): ClassSubject
    {
        return $this->offering($this->batchOld, Subject::query()->where('code', 'SUBJ-1')->sole());
    }

    /**
     * Candidate A1: Quiz 1 18/20, Quiz 2 21/30, Midterm 77.5/100.
     * Candidate A2: Quiz 1 10/20, Quiz 2 30/30, Midterm 64/100.
     * All finalized.
     */
    private function recordWorkedExample(): void
    {
        $quiz1 = $this->createAssessment($this->quizzes, 'Quiz 1', '20', '2026-09-01');
        $this->recordScores($quiz1, [$this->candidateInA->id => '18', $this->secondInA->id => '10']);
        $this->finalize($quiz1);

        $quiz2 = $this->createAssessment($this->quizzes, 'Quiz 2', '30', '2026-09-08');
        $this->recordScores($quiz2, [$this->candidateInA->id => '21', $this->secondInA->id => '30']);
        $this->finalize($quiz2);

        $exam = $this->createAssessment($this->examinations, 'Midterm Examination', '100', '2026-09-15');
        $this->recordScores($exam, [$this->candidateInA->id => '77.5', $this->secondInA->id => '64']);
        $this->finalize($exam);
    }

    /**
     * Assessments around "today" (6 October in Manila, 5 October in UTC).
     */
    private function createUpcomingFixtures(): void
    {
        $this->createAssessment($this->quizzes, 'Quiz Past', '20', '2026-09-20');
        $this->createAssessment($this->quizzes, 'Quiz Yesterday', '20', '2026-10-05');
        $today = $this->createAssessment($this->quizzes, 'Quiz Today', '20', '2026-10-06');
        $this->recordScores($today, [$this->candidateInA->id => '15']);
        $this->finalize($today);
        $this->createAssessment($this->quizzes, 'Undated Quiz', '20');
        // Same date: ordered by title, not by creation.
        $this->createAssessment($this->examinations, 'Exam B', '100', '2026-10-10');
        $this->createAssessment($this->examinations, 'Exam A', '100', '2026-10-10');
        $this->createAssessment($this->quizzes, 'Quiz 3', '20', '2026-10-08');
        $this->createAssessment($this->quizzes, 'Quiz 4', '20', '2026-10-20');
        // Sixth upcoming assessment: beyond the limit of five.
        $this->createAssessment($this->quizzes, 'Quiz 5', '20', '2026-10-25');

        // Alpha's past-period subject: not part of the dashboard.
        $oldOffering = $this->oldOffering();
        $this->setScheme($oldOffering, ['Quizzes' => '100']);
        $this->createAssessment($oldOffering->assessmentCategories()->sole(), 'Old Quiz', '20', '2026-10-07');

        // Bravo's subject in the same class.
        $this->setScheme($this->offeringA2, ['Quizzes' => '100']);
        $this->createAssessment($this->offeringA2->assessmentCategories()->sole(), 'Bravo Quiz', '20', '2026-10-07', $this->bravo);
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

    /**
     * @return array{categories: int, assessments: int, scores: int, revisions: int, audit: int}
     */
    private function gradingCounts(): array
    {
        return [
            'categories' => AssessmentCategory::query()->count(),
            'assessments' => Assessment::query()->count(),
            'scores' => AssessmentScore::query()->count(),
            'revisions' => AssessmentScoreRevision::query()->count(),
            'audit' => (int) DB::table('audit_logs')->count(),
        ];
    }
}
