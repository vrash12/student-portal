<?php

namespace Tests\Feature\Reporting;

use App\Enums\CandidateStatus;
use App\Enums\Permission as PermissionCode;
use App\Enums\SystemRole;
use App\Models\AcademicPeriod;
use App\Models\Candidate;
use App\Models\Subject;
use App\Models\User;
use App\Services\Monitoring\AcademicMonitoring;
use App\Services\Monitoring\MonitoringScope;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Milestone 14 administrator dashboard (AdministratorDashboardService):
 * institution-wide activity of the active period, from real data only.
 */
class AdministratorDashboardTest extends TestCase
{
    use BuildsReportingFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-09-10 08:00:00'));
    }

    public function test_totals_and_standings_reconcile_with_the_monitoring_engine(): void
    {
        $this->buildReportingFixtures();
        $props = $this->dashboardProps($this->academicAdmin);

        $overview = $props['administratorOverview'];
        $this->assertSame(['id' => $this->activePeriod->id, 'name' => 'Period Current'], $overview['period']);
        // A1, A2, B1: the withdrawn A3 and the past-period candidate are not counted.
        $this->assertSame(3, $overview['totalCandidates']);
        $this->assertSame($props['academicOverview']['counts']['monitored'], $overview['totalCandidates']);

        $engine = $this->app->make(AcademicMonitoring::class)->activePeriodSummary(MonitoringScope::for($this->academicAdmin), 5);
        $this->assertEquals($engine['counts'], $props['academicOverview']['counts']);
        $this->assertEquals(['monitored' => 3, 'failing' => 2, 'atRisk' => 1, 'incomplete' => 0, 'passing' => 0, 'noStanding' => 0], $props['academicOverview']['counts']);
    }

    public function test_problem_candidates_are_prioritized_most_serious_first(): void
    {
        $this->buildReportingFixtures();
        $attention = $this->dashboardProps($this->academicAdmin)['academicOverview']['requiringAttention'];

        // Failing by lowest grade (A2 60, A1 70), then At Risk (B1 77).
        $this->assertSame(
            [$this->secondInA->id, $this->candidateInA->id, $this->candidateInB->id],
            array_map(fn (array $entry): int => $entry['candidate']['id'], $attention),
        );
        $this->assertSame(['failing', 'failing', 'at_risk'], array_map(fn (array $entry): string => $entry['standing']['value'], $attention));
    }

    public function test_on_leave_candidates_are_counted_and_withdrawn_candidates_are_not(): void
    {
        $this->buildReportingFixtures();
        $this->makeCandidate($this->batchB, '001', ['status' => CandidateStatus::OnLeave->value]);
        $this->makeCandidate($this->batchB, '002', ['status' => CandidateStatus::Withdrawn->value]);

        $props = $this->dashboardProps($this->academicAdmin);

        $this->assertSame(4, $props['administratorOverview']['totalCandidates']);
        $this->assertSame(4, $props['academicOverview']['counts']['monitored']);
    }

    public function test_subject_performance_uses_grade_engine_averages(): void
    {
        $this->buildReportingFixtures();
        $rows = $this->dashboardProps($this->academicAdmin)['administratorOverview']['subjectPerformance'];

        // Most failing first, then subject name.
        $this->assertSame([$this->offeringA1->id, $this->offeringA2->id, $this->offeringB1->id], array_column($rows, 'id'));
        $byId = array_column($rows, null, 'id');

        $this->assertEquals(75.0, $byId[$this->offeringA1->id]['average']);
        $this->assertSame(2, $byId[$this->offeringA1->id]['candidateCount']);
        $this->assertSame(2, $byId[$this->offeringA1->id]['gradedCount']);
        $this->assertSame([1, 0, 1, 0], $this->standingCounts($byId[$this->offeringA1->id]));

        // A2 has no score in Subject 2: only A1's 70 is averaged.
        $this->assertEquals(70.0, $byId[$this->offeringA2->id]['average']);
        $this->assertSame(1, $byId[$this->offeringA2->id]['gradedCount']);
        $this->assertSame(2, $byId[$this->offeringA2->id]['candidateCount']);

        $this->assertEquals(77.0, $byId[$this->offeringB1->id]['average']);
        $this->assertSame([0, 1, 0, 0], $this->standingCounts($byId[$this->offeringB1->id]));
        $this->assertSame('Sample Batch B', $byId[$this->offeringB1->id]['classBatch']);
    }

    public function test_recent_examinations_are_the_five_newest_of_the_active_period(): void
    {
        $this->buildReportingFixtures();
        $titles = [];
        for ($index = 1; $index <= 6; $index++) {
            $this->travel(1)->hours();
            $titles[$index] = "Exam {$index}";
            $exam = $this->makeExamination($this->offeringA1, $titles[$index], ['access_code' => 'SECRET-CODE-'.$index]);
        }
        // The newest one gets submitted, in-progress and expired attempts.
        $this->makeAttempt($exam, $this->candidateInA);
        $this->makeAttempt($exam, $this->secondInA, ['status' => 'in_progress', 'submitted_at' => null, 'result_status' => null]);
        $this->makeAttempt($exam, $this->withdrawnInA, ['status' => 'expired']);
        // A newer examination of the past period is excluded.
        $this->travel(1)->hours();
        $this->makeExamination($this->offering($this->batchOld, Subject::query()->where('code', 'SUBJ-1')->sole()), 'Past Exam');

        $props = $this->dashboardProps($this->academicAdmin);
        $recent = $props['administratorOverview']['recentExaminations'];

        $this->assertSame(['Exam 6', 'Exam 5', 'Exam 4', 'Exam 3', 'Exam 2'], array_column($recent, 'title'));
        $this->assertSame(1, $recent[0]['submittedCount']);
        $this->assertSame(0, $recent[1]['submittedCount']);
        $this->assertSame('Subject 1', $recent[0]['subject']);
        $this->assertSame('Sample Batch A', $recent[0]['classBatch']);
        $this->assertSame(['id', 'title', 'subject', 'classBatch', 'status', 'submittedCount'], array_keys($recent[0]));
        $this->assertStringNotContainsString('SECRET-CODE', json_encode($props));
    }

    public function test_recent_examinations_show_the_lifecycle_state(): void
    {
        $this->buildReportingFixtures();
        $this->makeExamination($this->offeringA1, 'Draft Exam', ['status' => 'draft']);
        $this->travel(1)->minutes();
        $this->makeExamination($this->offeringA1, 'Ended Exam', ['closes_at' => now()->subMinute()]);
        $this->travel(1)->minutes();
        $this->makeExamination($this->offeringA1, 'Active Exam', ['opens_at' => now()->subMinute(), 'closes_at' => now()->addHour()]);

        $recent = $this->dashboardProps($this->academicAdmin)['administratorOverview']['recentExaminations'];
        $states = array_map(fn (array $exam): string => $exam['status']['value'], $recent);

        $this->assertSame(['Active Exam', 'Ended Exam', 'Draft Exam'], array_column($recent, 'title'));
        $this->assertSame(['active', 'ended', 'draft'], $states);
    }

    public function test_recent_activity_lists_finalized_assessments_of_the_active_period_newest_first(): void
    {
        $this->buildReportingFixtures();
        // A draft assessment and a finalized past-period assessment are excluded.
        $this->createAssessment($this->quizzes, 'Draft Quiz', '100');
        $offeringOld = $this->offering($this->batchOld, Subject::query()->where('code', 'SUBJ-1')->sole());
        $this->setScheme($offeringOld, ['Quizzes' => '100']);
        $this->travel(1)->hours();
        $past = $this->createAssessment($offeringOld->assessmentCategories()->sole(), 'Past Quiz', '100');
        $this->recordScores($past, [Candidate::query()->where('class_batch_id', $this->batchOld->id)->sole()->id => '50']);
        $this->finalize($past);

        $activity = $this->dashboardProps($this->academicAdmin)['administratorOverview']['recentActivity'];

        $this->assertSame(['Batch B Quiz', 'Subject 2 Examination', 'Quiz 1'], array_column($activity, 'title'));
        $this->assertSame([1, 1, 2], array_column($activity, 'scoredCount'));
        $this->assertNotNull($activity[0]['finalizedAt']);
    }

    public function test_recent_activity_is_limited_to_eight_entries(): void
    {
        $this->buildReportingFixtures(withScores: false);
        for ($index = 1; $index <= 10; $index++) {
            $this->travel(1)->minutes();
            $assessment = $this->createAssessment($this->quizzes, "Quiz {$index}", '100');
            $this->recordScores($assessment, [$this->candidateInA->id => '80']);
            $this->finalize($assessment);
        }

        $activity = $this->dashboardProps($this->academicAdmin)['administratorOverview']['recentActivity'];

        $this->assertCount(8, $activity);
        $this->assertSame('Quiz 10', $activity[0]['title']);
        $this->assertSame('Quiz 3', $activity[7]['title']);
    }

    public function test_instructor_coverage_lists_active_period_assignments_only(): void
    {
        $this->buildReportingFixtures();
        $instructors = $this->dashboardProps($this->academicAdmin)['administratorOverview']['instructors'];

        $this->assertSame([
            ['id' => $this->alpha->id, 'name' => 'Instructor Alpha', 'classes' => ['Sample Batch A'], 'subjects' => ['Subject 1']],
            ['id' => $this->bravo->id, 'name' => 'Instructor Bravo', 'classes' => ['Sample Batch A', 'Sample Batch B'], 'subjects' => ['Subject 1', 'Subject 2']],
        ], $instructors);
    }

    public function test_without_an_active_period_the_overview_is_empty_not_invented(): void
    {
        $this->buildReportingFixtures();
        AcademicPeriod::query()->update(['is_active' => false]);

        $props = $this->dashboardProps($this->academicAdmin);

        $this->assertSame([
            'period' => null, 'totalCandidates' => 0, 'recentExaminations' => [],
            'recentActivity' => [], 'subjectPerformance' => [], 'gradeDistribution' => [],
            'thresholds' => null, 'instructors' => [],
        ], $props['administratorOverview']);
        $this->assertNull($props['academicOverview']);
        $this->assertNull($props['thresholdSetup']);
    }

    public function test_an_empty_active_period_shows_zeroes_and_empty_lists(): void
    {
        $period = AcademicPeriod::factory()->active()->create(['name' => 'Empty Period']);
        $admin = $this->userWithRole(SystemRole::AcademicAdministrator);

        $overview = $this->dashboardProps($admin)['administratorOverview'];

        $this->assertSame(['id' => $period->id, 'name' => 'Empty Period'], $overview['period']);
        $this->assertSame(0, $overview['totalCandidates']);
        $this->assertSame([], $overview['recentExaminations']);
        $this->assertSame([], $overview['recentActivity']);
        $this->assertSame([], $overview['subjectPerformance']);
        $this->assertSame([], $overview['instructors']);
    }

    public function test_without_thresholds_no_standing_is_invented(): void
    {
        $this->buildReportingFixtures(withThresholds: false);

        $props = $this->dashboardProps($this->academicAdmin);

        $this->assertSame(['periodId' => $this->activePeriod->id, 'periodName' => 'Period Current'], $props['thresholdSetup']);
        $this->assertFalse($props['academicOverview']['hasThresholds']);
        $this->assertSame([], $props['academicOverview']['requiringAttention']);
        $this->assertSame(3, $props['administratorOverview']['totalCandidates']);
        foreach ($props['administratorOverview']['subjectPerformance'] as $row) {
            $this->assertSame([0, 0, 0, 0], $this->standingCounts($row));
            $this->assertSame($row['candidateCount'], $row['none']);
        }
    }

    public function test_instructors_do_not_get_administrator_sections(): void
    {
        $this->buildReportingFixtures();

        $props = $this->dashboardProps($this->alpha);

        $this->assertNull($props['administratorOverview']);
        $this->assertNull($props['academicOverview']);
        $this->assertNull($props['accountSummary']);
        $this->assertNull($props['thresholdSetup']);
        $this->assertNotNull($props['teaching']);
        $this->assertNotNull($props['academicAlerts']);
    }

    public function test_administrator_sections_follow_permissions_not_role_names(): void
    {
        $this->buildReportingFixtures();
        $officer = $this->staffWithPermissions('records_officer', [PermissionCode::ViewAllCandidates, PermissionCode::ViewAcademicMonitoring]);
        $viewerOnly = $this->staffWithPermissions('records_viewer', [PermissionCode::ViewAllCandidates]);

        $this->assertSame(3, $this->dashboardProps($officer)['administratorOverview']['totalCandidates']);
        $this->assertNull($this->dashboardProps($viewerOnly)['administratorOverview']);
    }

    public function test_candidates_guests_and_deactivated_staff_cannot_open_the_dashboard(): void
    {
        $this->buildReportingFixtures();

        $this->actingAs($this->candidateInA->user)->get('/dashboard')->assertForbidden();
        auth()->logout();
        $this->get('/dashboard')->assertRedirect('/login');

        $admin = User::factory()->withRole(SystemRole::AcademicAdministrator)->create(['is_active' => false]);
        $this->actingAs($admin)->get('/dashboard')->assertRedirect('/login');
    }

    /**
     * @return array<string, mixed>
     */
    private function dashboardProps(User $user): array
    {
        return $this->actingAs($user)->get('/dashboard')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('staff/dashboard'))
            ->inertiaProps();
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array{0: int, 1: int, 2: int, 3: int} failing, at risk, passing, incomplete
     */
    private function standingCounts(array $row): array
    {
        return [$row['failing'], $row['at_risk'], $row['passing'], $row['incomplete']];
    }
}
