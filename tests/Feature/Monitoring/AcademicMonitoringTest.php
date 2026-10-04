<?php

namespace Tests\Feature\Monitoring;

use App\Enums\CandidateStatus;
use App\Enums\Permission as PermissionCode;
use App\Enums\SystemRole;
use App\Models\Candidate;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Subject;
use App\Services\Grading\GradingThresholdService;
use App\Services\Grading\ScoreRecordingService;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Grading\BuildsGradingFixtures;
use Tests\TestCase;

/**
 * Milestone 6: the academic monitoring page, the dashboard panels, and the
 * candidate profile additions.
 *
 * On top of the grading fixtures (see BuildsGradingFixtures), with passing
 * 75 / warning 80 in the active period:
 *   Batch A Subject 1 (Alpha): Quiz 1 (/10) finalized: A1 9 (90, Passing), A2 6 (60, Failing)
 *   Batch A Subject 2 (Bravo): Exam (/100) finalized: A1 70 (Failing), A2 missing (Incomplete)
 *   Batch B Subject 1 (Bravo): Quiz (/10) finalized: B1 7.7 (77, Needs Improvement)
 */
class AcademicMonitoringTest extends TestCase
{
    use BuildsGradingFixtures;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildGradingFixtures();
        $this->app->make(GradingThresholdService::class)->save($this->activePeriod, '75', '80', null);

        $quiz = $this->createAssessment($this->quizzes, 'Quiz 1', '10');
        $this->recordScores($quiz, [$this->candidateInA->id => '9', $this->secondInA->id => '6']);
        $this->finalize($quiz);

        $this->setScheme($this->offeringA2, ['Examinations' => '100']);
        $exam = $this->createAssessment($this->offeringA2->assessmentCategories()->sole(), 'Subject 2 Exam', '100', null, $this->bravo);
        $this->recordScores($exam, [$this->candidateInA->id => '70'], $this->bravo);
        $this->finalize($exam, $this->bravo);

        $this->setScheme($this->offeringB1, ['Quizzes' => '100']);
        $quizB = $this->createAssessment($this->offeringB1->assessmentCategories()->sole(), 'Batch B Quiz', '10', null, $this->bravo);
        $this->recordScores($quizB, [$this->candidateInB->id => '7.7'], $this->bravo);
        $this->finalize($quizB, $this->bravo);
    }

    // ------------------------------------------------------------------
    // Access and scope
    // ------------------------------------------------------------------

    public function test_administrator_monitors_every_candidate_of_the_active_period(): void
    {
        $this->actingAs($this->academicAdmin)
            ->get('/monitoring')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('staff/monitoring/index')
                ->where('scope', 'all')
                ->where('period.id', $this->activePeriod->id)
                ->where('thresholds', ['passingGrade' => 75, 'warningGrade' => 80])
                // A1: Failing (Subject 2) · A2: Failing (Subject 1) · B1: Needs Improvement. Withdrawn A3 excluded.
                ->where('counts', ['monitored' => 3, 'failing' => 2, 'atRisk' => 1, 'incomplete' => 0, 'passing' => 0, 'noStanding' => 0])
                ->has('candidates.data', 3)
                ->where('view', 'overall'));
    }

    public function test_instructor_standings_cover_only_the_subjects_they_teach(): void
    {
        // Alpha teaches only Subject 1 in Batch A: A1 is Passing there, even
        // though A1 is Failing Bravo's Subject 2.
        $this->actingAs($this->alpha)
            ->get('/monitoring')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('scope', 'taught')
                ->where('counts', ['monitored' => 2, 'failing' => 1, 'atRisk' => 0, 'incomplete' => 0, 'passing' => 1, 'noStanding' => 0])
                ->where('classOptions', [['id' => $this->batchA->id, 'name' => 'Sample Batch A']])
                ->where('subjectOptions', [['id' => $this->offeringA1->subject_id, 'code' => 'SUBJ-1', 'name' => 'Subject 1']])
                ->where('candidates.data.0.candidate.id', $this->secondInA->id)
                ->where('candidates.data.1.candidate.id', $this->candidateInA->id)
                ->where('candidates.data.1.standing.value', 'passing')
                ->where('candidates.data.1.overall.basedOnSubjects', 1)
                ->where('candidates.data.1.overall.totalSubjects', 1));
    }

    /**
     * An instructor teaching Subject 1 in one class must not see Subject 1
     * in another class taught by someone else (design critique finding).
     */
    public function test_a_subject_filter_never_reaches_another_instructors_class(): void
    {
        $subject1 = $this->offeringA1->subject_id;

        $this->actingAs($this->alpha)
            ->get("/monitoring?subject={$subject1}")
            ->assertInertia(fn (Assert $page) => $page
                ->where('view', 'subject')
                ->where('counts.monitored', 2)
                ->has('candidates.data', 2)
                ->where('candidates.data', fn ($rows) => collect($rows)->every(fn ($row) => $row['classBatch']['id'] === $this->batchA->id)));

        // Batch B (Bravo's) and Subject 2 are not options for Alpha, so they are ignored.
        $this->actingAs($this->alpha)
            ->get("/monitoring?class={$this->batchB->id}&subject={$this->offeringA2->subject_id}")
            ->assertInertia(fn (Assert $page) => $page
                ->where('filters.class', '')
                ->where('filters.subject', '')
                ->where('counts.monitored', 2));
    }

    public function test_users_without_the_permission_cannot_open_monitoring(): void
    {
        $this->get('/monitoring')->assertRedirect('/login');
        $this->actingAs($this->candidateInA->user)->get('/monitoring')->assertForbidden();

        $role = Role::query()->create(['code' => 'records_clerk', 'name' => 'Records Clerk']);
        $role->permissions()->sync(Permission::query()->whereIn('code', [
            PermissionCode::AccessStaffArea->value,
            PermissionCode::ViewAllCandidates->value,
        ])->pluck('id'));
        $clerk = $this->userWithRole(SystemRole::Instructor);
        $clerk->role()->associate($role)->save();

        $this->actingAs($clerk->fresh())->get('/monitoring')->assertForbidden();
        $this->actingAs($clerk->fresh())
            ->get('/dashboard')
            ->assertInertia(fn (Assert $page) => $page->where('showAcademicOverview', false)->where('academicOverview', null));
    }

    public function test_the_permission_alone_gives_an_empty_scope(): void
    {
        $role = Role::query()->create(['code' => 'observer', 'name' => 'Observer']);
        $role->permissions()->sync(Permission::query()->whereIn('code', [
            PermissionCode::AccessStaffArea->value,
            PermissionCode::ViewAcademicMonitoring->value,
        ])->pluck('id'));
        // Leftover assignments of a former instructor grant nothing without classes.teach.
        $former = $this->alpha;
        $former->role()->associate($role)->save();

        $this->actingAs($former->fresh())
            ->get('/monitoring')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('scope', 'none')
                ->where('period', null)
                ->where('periods', [])
                ->has('candidates.data', 0));
    }

    public function test_instructors_can_only_select_periods_they_teach_in(): void
    {
        // Bravo teaches nothing in the past period.
        $this->actingAs($this->bravo)
            ->get("/monitoring?period={$this->pastPeriod->id}")
            ->assertInertia(fn (Assert $page) => $page
                ->where('period.id', $this->activePeriod->id)
                ->where('filters.period', '')
                ->where('periods', [['id' => $this->activePeriod->id, 'name' => 'Period Current', 'isActive' => true]]));

        // Alpha taught in the past period, which has no thresholds.
        $this->actingAs($this->alpha)
            ->get("/monitoring?period={$this->pastPeriod->id}")
            ->assertInertia(fn (Assert $page) => $page
                ->where('period.id', $this->pastPeriod->id)
                ->where('thresholds', null)
                ->where('counts.monitored', 1)
                ->where('counts.noStanding', 1));
    }

    // ------------------------------------------------------------------
    // Filters, sorting, pagination
    // ------------------------------------------------------------------

    public function test_standing_filter_and_search_narrow_the_list_but_not_the_counts(): void
    {
        $this->actingAs($this->academicAdmin)
            ->get('/monitoring?standing=at_risk')
            ->assertInertia(fn (Assert $page) => $page
                ->where('counts.monitored', 3)
                ->has('candidates.data', 1)
                ->where('candidates.data.0.candidate.id', $this->candidateInB->id));

        $this->candidateInB->forceFill(['first_name' => 'José'])->save();

        // Accent-insensitive, like every other candidate search.
        $this->actingAs($this->academicAdmin)
            ->get('/monitoring?search=jose')
            ->assertInertia(fn (Assert $page) => $page
                ->where('counts.monitored', 3)
                ->has('candidates.data', 1)
                ->where('candidates.data.0.candidate.id', $this->candidateInB->id));
    }

    public function test_default_sort_is_most_serious_first_and_ungraded_candidates_come_last(): void
    {
        // A Batch B candidate added after the quiz was finalized has a missing
        // score: Incomplete, with no grade at all.
        $ungraded = Candidate::factory()->create(['class_batch_id' => $this->batchB->id, 'last_name' => 'AAA']);

        $this->actingAs($this->academicAdmin)
            ->get('/monitoring')
            ->assertInertia(fn (Assert $page) => $page
                ->where('counts.incomplete', 1)
                ->where('candidates.data.3.lowest', null)
                ->where('candidates.data', fn ($rows) => collect($rows)->pluck('candidate.id')->all() === [
                    // Both Failing: A2 (60.00) before A1 (lowest 70.00); then B1 Needs Improvement; then Incomplete.
                    $this->secondInA->id, $this->candidateInA->id, $this->candidateInB->id, $ungraded->id,
                ]));

        $this->actingAs($this->academicAdmin)
            ->get('/monitoring?sort=highest')
            ->assertInertia(fn (Assert $page) => $page
                ->where('candidates.data', fn ($rows) => collect($rows)->pluck('candidate.id')->last() === $ungraded->id));
    }

    public function test_invalid_page_numbers_do_not_fail(): void
    {
        foreach (['-1', 'abc', '999999', '99999999999999999999'] as $page) {
            $this->actingAs($this->academicAdmin)->get("/monitoring?page={$page}")->assertOk();
        }
    }

    public function test_subjects_requiring_attention_count_each_class_subject(): void
    {
        $this->actingAs($this->academicAdmin)
            ->get('/monitoring')
            ->assertInertia(fn (Assert $page) => $page
                ->has('subjectsRequiringAttention', 3)
                ->where('subjectsRequiringAttention.0.classSubjectId', $this->offeringA1->id)
                ->where('subjectsRequiringAttention.0.failing', 1)
                ->where('subjectsRequiringAttention.1.classSubjectId', $this->offeringA2->id)
                ->where('subjectsRequiringAttention.1.failing', 1)
                ->where('subjectsRequiringAttention.1.incomplete', 1)
                ->where('subjectsRequiringAttention.2.classSubjectId', $this->offeringB1->id)
                ->where('subjectsRequiringAttention.2.atRisk', 1));
    }

    public function test_monitoring_uses_a_fixed_number_of_queries(): void
    {
        $count = function (): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->actingAs($this->academicAdmin)->get('/monitoring')->assertOk();

            return count(DB::getQueryLog());
        };

        $count();
        $before = $count();
        Candidate::factory()->count(12)->create(['class_batch_id' => $this->batchA->id]);
        $after = $count();

        $this->assertSame($before, $after);
    }

    // ------------------------------------------------------------------
    // Dashboards
    // ------------------------------------------------------------------

    public function test_administrator_dashboard_shows_counts_and_candidates_requiring_attention(): void
    {
        $this->actingAs($this->academicAdmin)
            ->get('/dashboard')
            ->assertInertia(fn (Assert $page) => $page
                ->where('showAcademicOverview', true)
                ->where('academicOverview.hasThresholds', true)
                ->where('academicOverview.counts.failing', 2)
                ->has('academicOverview.requiringAttention', 3)
                ->where('academicOverview.requiringAttention.0.candidate', [
                    'id' => $this->secondInA->id,
                    'candidateNumber' => $this->secondInA->candidate_number,
                    'name' => $this->secondInA->full_name,
                    'status' => null,
                ])
                // Only summary fields: no scores, comments, or reasons.
                ->where('academicOverview.requiringAttention.0', fn ($row) => array_keys($row->toArray()) === ['candidate', 'classBatch', 'standing', 'lowest', 'mostSerious']));
    }

    public function test_instructor_dashboard_alerts_cover_their_own_subjects(): void
    {
        $this->actingAs($this->bravo)
            ->get('/dashboard')
            ->assertInertia(fn (Assert $page) => $page
                ->where('showAcademicAlerts', true)
                ->where('academicAlerts.scope', 'taught')
                // A1 Failing (Subject 2), A2 Incomplete (Subject 2), B1 Needs Improvement.
                ->where('academicAlerts.counts', ['monitored' => 3, 'failing' => 1, 'atRisk' => 1, 'incomplete' => 1, 'passing' => 0, 'noStanding' => 0])
                ->where('academicAlerts.requiringAttention.0.mostSerious.canOpenGradebook', true));
    }

    // ------------------------------------------------------------------
    // Candidate profile
    // ------------------------------------------------------------------

    public function test_profile_lists_warnings_results_and_activity(): void
    {
        $quiz = $this->offeringA1->assessments()->where('title', 'Quiz 1')->sole();
        $this->app->make(ScoreRecordingService::class)->correctFinalizedScore(
            $quiz, $this->secondInA->id, '6.5', null, 'Marking error on item 3.', '6.00', null, $this->alpha,
        );

        $this->actingAs($this->academicAdmin)
            ->get("/candidates/{$this->secondInA->id}")
            ->assertInertia(fn (Assert $page) => $page
                // Failing Subject 1 first, then the missing Subject 2 exam (Incomplete).
                ->where('warnings.0.subject', 'Subject 1')
                ->where('warnings.0.standing.value', 'failing')
                ->where('warnings.1.subject', 'Subject 2')
                ->where('warnings.1.missingScores', 1)
                ->where('assessmentResults.1.assessments.0.score', null)
                ->where('assessmentResults.0.assessments.0.score', '6.5')
                ->where('recentActivity.0.type', 'corrected')
                ->where('recentActivity.0.reason', 'Marking error on item 3.'));

        // The instructor of Subject 1 sees nothing of Bravo's Subject 2.
        $this->actingAs($this->alpha)
            ->get("/candidates/{$this->secondInA->id}")
            ->assertInertia(fn (Assert $page) => $page
                ->has('warnings', 1)
                ->has('assessmentResults', 1)
                ->where('recentActivity', fn ($entries) => collect($entries)->every(fn ($entry) => $entry['subject'] === 'Subject 1')));
    }

    public function test_withdrawn_candidates_have_no_warnings_and_only_recorded_results(): void
    {
        $this->secondInA->forceFill(['status' => CandidateStatus::Withdrawn->value])->save();

        $this->actingAs($this->academicAdmin)
            ->get("/candidates/{$this->secondInA->id}")
            ->assertInertia(fn (Assert $page) => $page
                ->where('warnings', [])
                ->has('assessmentResults.0.assessments', 1)
                // The Subject 2 exam has no score row for them, so it is not listed.
                ->has('assessmentResults.1.assessments', 0));
    }
}
