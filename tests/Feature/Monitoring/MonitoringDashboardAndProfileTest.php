<?php

namespace Tests\Feature\Monitoring;

use App\Enums\CandidateStatus;
use App\Enums\Permission as PermissionCode;
use App\Enums\SystemRole;
use App\Models\Assessment;
use App\Models\AssessmentScore;
use App\Models\Candidate;
use App\Models\ClassBatch;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Subject;
use App\Models\User;
use App\Services\AcademicPeriodService;
use App\Services\Grading\GradingThresholdService;
use App\Services\Grading\ScoreRecordingService;
use App\Support\DecimalValue;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Grading\BuildsGradingFixtures;
use Tests\TestCase;

/**
 * Independent tests of the Milestone 6 dashboard panels (administrator
 * Academic Overview, instructor Academic Alerts) and the candidate profile
 * additions (Current Warnings, Recent Assessments, Recent Academic Activity).
 *
 * On top of the grading fixtures (see BuildsGradingFixtures), with passing 75
 * and warning 80 in the active period. Every assessment is out of 100 and is
 * the only finalized one of its subject, so a subject grade equals the score:
 *
 *   Batch A, Subject 1 (Alpha)   Quiz 1, finalized 2026-09-01 08:00 (dated 2026-08-20)
 *       A1 90 (Passing, provisional)   A2 60 (Failing, score has a comment)
 *       A3 40, scored while enrolled and withdrawn afterwards
 *   Batch A, Subject 2 (Bravo)   Subject 2 Examination, finalized 2026-09-02 08:00
 *       A1 70 (Failing)   A2 no score (Incomplete)
 *   Batch B, Subject 1 (Bravo)   Batch B Quiz, finalized 2026-09-03 08:00
 *       B1 77 (Needs Improvement)   Candidate 101: 85 (Passing)   Candidate 102: no score (Incomplete)
 *   Past period (no thresholds), Batch Old, Subject 1 (Alpha)   Past Quiz: Old1 50
 *
 * Overall standing over every subject of the active period:
 *   A1 Failing (lowest 70, Subject 2), A2 Failing (lowest 60, Subject 1),
 *   B1 Needs Improvement, Candidate 101 Passing, Candidate 102 Incomplete.
 */
class MonitoringDashboardAndProfileTest extends TestCase
{
    use BuildsGradingFixtures;

    private const SCORE_COMMENT = 'Private instructor note about Quiz 1.';

    private const SUMMARY_KEYS = ['period', 'scope', 'hasThresholds', 'counts', 'requiringAttention'];

    private const ENTRY_KEYS = ['candidate', 'classBatch', 'standing', 'lowest', 'mostSerious'];

    private const WARNING_KEYS = ['classSubjectId', 'subject', 'grade', 'standing', 'missingScores', 'isProvisional'];

    private Assessment $quiz1;

    private Assessment $exam2;

    private Candidate $passingInB;

    private Candidate $unscoredInB;

    private Candidate $oldCandidate;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-09-01 08:00:00'));
        $this->buildGradingFixtures();
        $this->app->make(GradingThresholdService::class)->save($this->activePeriod, '75', '80', null);

        $this->oldCandidate = Candidate::query()->where('class_batch_id', $this->batchOld->id)->sole();
        $this->passingInB = $this->candidate($this->batchB, '101');
        $this->unscoredInB = $this->candidate($this->batchB, '102');

        // Batch A, Subject 1 (Alpha). A3 is scored while enrolled, then withdraws.
        $this->withdrawnInA->forceFill(['status' => CandidateStatus::Enrolled->value])->save();
        $this->quiz1 = $this->createAssessment($this->quizzes, 'Quiz 1', '100', '2026-08-20');
        $this->recordScores($this->quiz1, [$this->candidateInA->id => '90', $this->withdrawnInA->id => '40']);
        $this->recordScoreWithComment($this->quiz1, $this->secondInA, '60', self::SCORE_COMMENT, $this->alpha);
        $this->finalize($this->quiz1);
        $this->withdrawnInA->forceFill(['status' => CandidateStatus::Withdrawn->value])->save();

        // Batch A, Subject 2 (Bravo).
        $this->travelTo(CarbonImmutable::parse('2026-09-02 08:00:00'));
        $this->setScheme($this->offeringA2, ['Examinations' => '100']);
        $this->exam2 = $this->createAssessment($this->offeringA2->assessmentCategories()->sole(), 'Subject 2 Examination', '100', '2026-08-25', $this->bravo);
        $this->recordScores($this->exam2, [$this->candidateInA->id => '70'], $this->bravo);
        $this->finalize($this->exam2, $this->bravo);

        // Batch B, Subject 1 (Bravo).
        $this->travelTo(CarbonImmutable::parse('2026-09-03 08:00:00'));
        $this->setScheme($this->offeringB1, ['Quizzes' => '100']);
        $quizB = $this->createAssessment($this->offeringB1->assessmentCategories()->sole(), 'Batch B Quiz', '100', '2026-08-27', $this->bravo);
        $this->recordScores($quizB, [$this->candidateInB->id => '77', $this->passingInB->id => '85'], $this->bravo);
        $this->finalize($quizB, $this->bravo);

        // Past period, Batch Old, Subject 1 (Alpha). The past period has no thresholds.
        $offeringOld = $this->offering($this->batchOld, Subject::query()->where('code', 'SUBJ-1')->sole());
        $this->setScheme($offeringOld, ['Quizzes' => '100']);
        $pastQuiz = $this->createAssessment($offeringOld->assessmentCategories()->sole(), 'Past Quiz', '100', '2026-03-10');
        $this->recordScores($pastQuiz, [$this->oldCandidate->id => '50']);
        $this->finalize($pastQuiz);
    }

    // ------------------------------------------------------------------
    // Dashboard: who gets which panel
    // ------------------------------------------------------------------

    public function test_administrators_get_the_academic_overview_of_the_active_period_but_no_teaching_alerts(): void
    {
        $superAdmin = $this->userWithRole(SystemRole::SuperAdministrator, ['name' => 'Super Admin']);

        foreach ([$this->academicAdmin, $superAdmin] as $administrator) {
            $props = $this->dashboardProps($administrator);

            $this->assertTrue($props['showAcademicOverview']);
            $this->assertFalse($props['showAcademicAlerts']);
            $this->assertNull($props['academicAlerts']);
            $this->assertNull($props['teaching']);

            $overview = $props['academicOverview'];
            $this->assertSame(['id' => $this->activePeriod->id, 'name' => 'Period Current'], $overview['period']);
            $this->assertSame('all', $overview['scope']);
            $this->assertTrue($overview['hasThresholds']);
            // A3 (withdrawn) and Old1 (past period) are not monitored.
            $this->assertCounts([5, 2, 1, 1, 1, 0], $overview['counts']);
            // Failing by lowest grade (A2 60, A1 70), then Needs Improvement (B1).
            $this->assertSame(
                [$this->secondInA->id, $this->candidateInA->id, $this->candidateInB->id],
                $this->attentionIds($overview),
            );
            // Administrators do not teach, so no gradebook links.
            $this->assertFalse($overview['requiringAttention'][0]['mostSerious']['canOpenGradebook']);
        }
    }

    public function test_instructors_get_academic_alerts_for_their_own_subjects_but_no_overview(): void
    {
        $alpha = $this->dashboardProps($this->alpha);

        $this->assertFalse($alpha['showAcademicOverview']);
        $this->assertNull($alpha['academicOverview']);
        $this->assertTrue($alpha['showAcademicAlerts']);
        $this->assertSame('taught', $alpha['academicAlerts']['scope']);
        $this->assertSame(['id' => $this->activePeriod->id, 'name' => 'Period Current'], $alpha['academicAlerts']['period']);
        $this->assertTrue($alpha['academicAlerts']['hasThresholds']);
        // Over Subject 1 of Batch A only: A1 Passing (90), A2 Failing (60).
        $this->assertCounts([2, 1, 0, 0, 1, 0], $alpha['academicAlerts']['counts']);
        $this->assertSame([$this->secondInA->id], $this->attentionIds($alpha['academicAlerts']));

        $entry = $alpha['academicAlerts']['requiringAttention'][0];
        $this->assertSame('failing', $entry['standing']['value']);
        $this->assertSame(['id' => $this->batchA->id, 'name' => 'Sample Batch A'], $entry['classBatch']);
        $this->assertEquals(['grade' => 60.0, 'subject' => 'Subject 1', 'isProvisional' => true], $entry['lowest']);
        $this->assertSame($this->offeringA1->id, $entry['mostSerious']['classSubjectId']);
        $this->assertTrue($entry['mostSerious']['canOpenGradebook']);

        $bravo = $this->dashboardProps($this->bravo);

        $this->assertFalse($bravo['showAcademicOverview']);
        $this->assertNull($bravo['academicOverview']);
        // Subject 2 of Batch A and Subject 1 of Batch B: A1 Failing, A2 Incomplete,
        // B1 Needs Improvement, Candidate 101 Passing, Candidate 102 Incomplete.
        $this->assertCounts([5, 1, 1, 2, 1, 0], $bravo['academicAlerts']['counts']);
        // A2 is Failing only in Alpha's subject, so Bravo is not alerted about them.
        $this->assertSame([$this->candidateInA->id, $this->candidateInB->id], $this->attentionIds($bravo['academicAlerts']));

        // Every candidate in the alerts has a profile the instructor may open.
        foreach ([[$this->alpha, $alpha], [$this->bravo, $bravo]] as [$instructor, $props]) {
            foreach ($this->attentionIds($props['academicAlerts']) as $candidateId) {
                $this->actingAs($instructor)->get("/candidates/{$candidateId}")->assertOk();
            }
        }
    }

    public function test_panels_follow_permissions_not_role_names(): void
    {
        $observer = $this->staffWithPermissions('monitoring_observer', 'Observer Echo', [PermissionCode::ViewAcademicMonitoring]);
        $recordsOfficer = $this->staffWithPermissions('records_officer', 'Records Officer Foxtrot', [
            PermissionCode::ViewAllCandidates,
            PermissionCode::ViewAcademicMonitoring,
        ]);
        $assistant = $this->staffWithPermissions('teaching_assistant', 'Assistant Golf', [PermissionCode::TeachClasses]);
        $coordinator = $this->staffWithPermissions('teaching_coordinator', 'Coordinator Hotel', [
            PermissionCode::ViewAllCandidates,
            PermissionCode::TeachClasses,
            PermissionCode::ViewAcademicMonitoring,
        ]);
        $this->teach($assistant, $this->offeringA1);
        $this->teach($coordinator, $this->offeringB1);

        // Monitoring permission alone: no candidates to monitor on either panel.
        $props = $this->dashboardProps($observer);
        $this->assertFalse($props['showAcademicOverview']);
        $this->assertNull($props['academicOverview']);
        $this->assertFalse($props['showAcademicAlerts']);
        $this->assertNull($props['academicAlerts']);

        // View all + monitoring, without teaching: the overview only.
        $props = $this->dashboardProps($recordsOfficer);
        $this->assertTrue($props['showAcademicOverview']);
        $this->assertSame('all', $props['academicOverview']['scope']);
        $this->assertCounts([5, 2, 1, 1, 1, 0], $props['academicOverview']['counts']);
        $this->assertFalse($props['showAcademicAlerts']);
        $this->assertNull($props['academicAlerts']);

        // Teaching without the monitoring permission: teaching section, no alerts.
        $props = $this->dashboardProps($assistant);
        $this->assertNotNull($props['teaching']);
        $this->assertFalse($props['showAcademicAlerts']);
        $this->assertNull($props['academicAlerts']);
        $this->assertFalse($props['showAcademicOverview']);
        $this->assertNull($props['academicOverview']);

        // View all + teaching + monitoring: the overview covers everything, the
        // alerts only the subject this user teaches (Batch B, Subject 1).
        $props = $this->dashboardProps($coordinator);
        $this->assertTrue($props['showAcademicOverview']);
        $this->assertTrue($props['showAcademicAlerts']);
        $this->assertSame('all', $props['academicOverview']['scope']);
        $this->assertCounts([5, 2, 1, 1, 1, 0], $props['academicOverview']['counts']);
        $this->assertSame('taught', $props['academicAlerts']['scope']);
        $this->assertCounts([3, 0, 1, 1, 1, 0], $props['academicAlerts']['counts']);
        $this->assertSame([$this->candidateInB->id], $this->attentionIds($props['academicAlerts']));
        $this->assertTrue($props['academicAlerts']['requiringAttention'][0]['mostSerious']['canOpenGradebook']);

        // Gradebook links in the overview only for the subject this user teaches.
        $links = [];
        foreach ($props['academicOverview']['requiringAttention'] as $entry) {
            $links[$entry['candidate']['id']] = $entry['mostSerious']['canOpenGradebook'];
        }
        $this->assertSame([$this->secondInA->id => false, $this->candidateInA->id => false, $this->candidateInB->id => true], $links);
    }

    public function test_an_instructor_without_subjects_in_the_active_period_gets_empty_alerts(): void
    {
        $delta = $this->userWithRole(SystemRole::Instructor, ['name' => 'Instructor Delta']);

        $props = $this->dashboardProps($delta);

        $this->assertTrue($props['showAcademicAlerts']);
        $this->assertSame('taught', $props['academicAlerts']['scope']);
        $this->assertCounts([0, 0, 0, 0, 0, 0], $props['academicAlerts']['counts']);
        $this->assertSame([], $props['academicAlerts']['requiringAttention']);
    }

    // ------------------------------------------------------------------
    // Dashboard: active period only
    // ------------------------------------------------------------------

    public function test_dashboards_count_only_the_active_period(): void
    {
        // With thresholds in the past period, Old1 (50) would be Failing there.
        $this->app->make(GradingThresholdService::class)->save($this->pastPeriod, '75', '80', null);

        $overview = $this->dashboardProps($this->academicAdmin)['academicOverview'];
        $this->assertSame($this->activePeriod->id, $overview['period']['id']);
        $this->assertCounts([5, 2, 1, 1, 1, 0], $overview['counts']);
        $this->assertNotContains($this->oldCandidate->id, $this->attentionIds($overview));

        // Alpha also taught Batch Old in the past period; it is not part of the alerts.
        $alerts = $this->dashboardProps($this->alpha)['academicAlerts'];
        $this->assertCounts([2, 1, 0, 0, 1, 0], $alerts['counts']);
        $this->assertSame([$this->secondInA->id], $this->attentionIds($alerts));

        // Once the past period is the active one, the panels follow it.
        $this->app->make(AcademicPeriodService::class)->activate($this->pastPeriod);

        $overview = $this->dashboardProps($this->academicAdmin)['academicOverview'];
        $this->assertSame(['id' => $this->pastPeriod->id, 'name' => 'Period Past'], $overview['period']);
        $this->assertCounts([1, 1, 0, 0, 0, 0], $overview['counts']);
        $this->assertSame([$this->oldCandidate->id], $this->attentionIds($overview));
        $this->assertSame('Completed', $overview['requiringAttention'][0]['candidate']['status']);
        $this->assertSame('Sample Batch Old', $overview['requiringAttention'][0]['classBatch']['name']);

        $alerts = $this->dashboardProps($this->alpha)['academicAlerts'];
        $this->assertSame($this->pastPeriod->id, $alerts['period']['id']);
        $this->assertCounts([1, 1, 0, 0, 0, 0], $alerts['counts']);
        $this->assertSame([$this->oldCandidate->id], $this->attentionIds($alerts));

        // Bravo teaches nothing in that period.
        $alerts = $this->dashboardProps($this->bravo)['academicAlerts'];
        $this->assertSame($this->pastPeriod->id, $alerts['period']['id']);
        $this->assertCounts([0, 0, 0, 0, 0, 0], $alerts['counts']);
        $this->assertSame([], $alerts['requiringAttention']);
    }

    public function test_dashboards_have_no_monitoring_data_without_an_active_period(): void
    {
        $this->activePeriod->forceFill(['is_active' => false])->save();

        $props = $this->dashboardProps($this->academicAdmin);
        $this->assertTrue($props['showAcademicOverview']);
        $this->assertNull($props['academicOverview']);
        $this->assertNull($props['thresholdSetup']);

        $props = $this->dashboardProps($this->alpha);
        $this->assertTrue($props['showAcademicAlerts']);
        $this->assertNull($props['academicAlerts']);
        $this->assertNull($props['teaching']['period']);
    }

    public function test_without_thresholds_the_dashboards_show_no_standings_and_nobody_requiring_attention(): void
    {
        // The past period has a failing-level score (Old1 50) but no thresholds.
        $this->app->make(AcademicPeriodService::class)->activate($this->pastPeriod);

        $props = $this->dashboardProps($this->academicAdmin);
        $this->assertFalse($props['academicOverview']['hasThresholds']);
        $this->assertCounts([1, 0, 0, 0, 0, 1], $props['academicOverview']['counts']);
        $this->assertSame([], $props['academicOverview']['requiringAttention']);
        $this->assertSame(['periodId' => $this->pastPeriod->id, 'periodName' => 'Period Past'], $props['thresholdSetup']);

        $props = $this->dashboardProps($this->alpha);
        $this->assertFalse($props['academicAlerts']['hasThresholds']);
        $this->assertCounts([1, 0, 0, 0, 0, 1], $props['academicAlerts']['counts']);
        $this->assertSame([], $props['academicAlerts']['requiringAttention']);
        // Instructors cannot set thresholds, so they get no setup link.
        $this->assertNull($props['thresholdSetup']);
    }

    // ------------------------------------------------------------------
    // Dashboard: the attention list
    // ------------------------------------------------------------------

    public function test_the_attention_list_holds_at_most_five_candidates_most_serious_first(): void
    {
        // Batch C, Subject 3 (Charlie). Candidate 205 is created before 201 so
        // that the tie at 50 is settled by name, not by creation order.
        $batchC = ClassBatch::factory()->for($this->activePeriod)->create(['name' => 'Sample Batch C']);
        $offeringC = $this->offering($batchC, Subject::factory()->create(['code' => 'SUBJ-3', 'name' => 'Subject 3']));
        $charlie = $this->userWithRole(SystemRole::Instructor, ['name' => 'Instructor Charlie']);
        $this->teach($charlie, $offeringC);
        $this->setScheme($offeringC, ['Quizzes' => '100']);

        // Candidate number => score (207 has none: Incomplete).
        $scores = [205 => '50', 201 => '50', 202 => '74.99', 203 => '79.99', 204 => '75', 206 => '95', 207 => null, 208 => '65'];
        $inC = [];
        $toRecord = [];
        foreach ($scores as $number => $score) {
            $inC[$number] = $this->candidate($batchC, (string) $number);
            if ($score !== null) {
                $toRecord[$inC[$number]->id] = $score;
            }
        }
        $quizC = $this->createAssessment($offeringC->assessmentCategories()->sole(), 'Batch C Quiz', '100', '2026-08-28', $charlie);
        $this->recordScores($quizC, $toRecord, $charlie);
        $this->finalize($quizC, $charlie);

        // Charlie: Failing 201 (50), 205 (50), 208 (65), 202 (74.99); Needs Improvement 204 (75), 203 (79.99).
        $alerts = $this->dashboardProps($charlie)['academicAlerts'];
        $this->assertCounts([8, 4, 2, 1, 1, 0], $alerts['counts']);
        $this->assertSame(
            [$inC[201]->id, $inC[205]->id, $inC[208]->id, $inC[202]->id, $inC[204]->id],
            $this->attentionIds($alerts),
        );
        $this->assertSame(
            ['failing', 'failing', 'failing', 'failing', 'at_risk'],
            array_map(fn (array $entry): string => $entry['standing']['value'], $alerts['requiringAttention']),
        );
        $this->assertEquals(
            [50.0, 50.0, 65.0, 74.99, 75.0],
            array_map(fn (array $entry): float => $entry['lowest']['grade'], $alerts['requiringAttention']),
        );

        // Administrators: all Failing candidates of the period come before any Needs Improvement one.
        $overview = $this->dashboardProps($this->academicAdmin)['academicOverview'];
        $this->assertCounts([13, 6, 3, 2, 2, 0], $overview['counts']);
        $this->assertSame(
            [$inC[201]->id, $inC[205]->id, $this->secondInA->id, $inC[208]->id, $this->candidateInA->id],
            $this->attentionIds($overview),
        );
    }

    public function test_the_attention_list_excludes_withdrawn_candidates_and_keeps_those_on_leave(): void
    {
        $this->candidateInB->forceFill(['status' => CandidateStatus::OnLeave->value])->save();
        // Not assigned to any class: not monitored.
        $this->candidate(null, '999');

        $overview = $this->dashboardProps($this->academicAdmin)['academicOverview'];

        // A3 scored 40 before withdrawing, but is not monitored any more.
        $this->assertCounts([5, 2, 1, 1, 1, 0], $overview['counts']);
        $this->assertNotContains($this->withdrawnInA->id, $this->attentionIds($overview));
        $this->assertSame([$this->secondInA->id, $this->candidateInA->id, $this->candidateInB->id], $this->attentionIds($overview));

        $statuses = array_map(fn (array $entry): ?string => $entry['candidate']['status'], $overview['requiringAttention']);
        $this->assertSame([null, null, 'On Leave'], $statuses);
    }

    public function test_alerts_never_reveal_results_of_subjects_the_instructor_does_not_teach(): void
    {
        // A1 drops to 50 in Alpha's Subject 1; they are also Failing Bravo's Subject 2 (70).
        $this->travelTo(CarbonImmutable::parse('2026-09-04 09:00:00'));
        $this->correct($this->quiz1, $this->candidateInA, '50', 'Rechecked the answer sheet.', $this->alpha);

        $overview = $this->dashboardProps($this->academicAdmin)['academicOverview'];
        $this->assertSame($this->candidateInA->id, $overview['requiringAttention'][0]['candidate']['id']);
        $this->assertEquals(['grade' => 50.0, 'subject' => 'Subject 1', 'isProvisional' => true], $overview['requiringAttention'][0]['lowest']);

        // Bravo's alerts are unchanged: counts, order, and A1's lowest grade and
        // subject come from Subject 2 only.
        $bravo = $this->dashboardProps($this->bravo)['academicAlerts'];
        $this->assertCounts([5, 1, 1, 2, 1, 0], $bravo['counts']);
        $this->assertSame([$this->candidateInA->id, $this->candidateInB->id], $this->attentionIds($bravo));
        $a1 = $bravo['requiringAttention'][0];
        $this->assertEquals(['grade' => 70.0, 'subject' => 'Subject 2', 'isProvisional' => false], $a1['lowest']);
        $this->assertSame($this->offeringA2->id, $a1['mostSerious']['classSubjectId']);
        $this->assertEquals(70.0, $a1['mostSerious']['grade']);
        foreach ($bravo['requiringAttention'] as $entry) {
            $this->assertContains($entry['mostSerious']['classSubjectId'], [$this->offeringA2->id, $this->offeringB1->id]);
        }

        // Alpha now sees A1 as Failing too, from Subject 1 only.
        $alpha = $this->dashboardProps($this->alpha)['academicAlerts'];
        $this->assertCounts([2, 2, 0, 0, 0, 0], $alpha['counts']);
        $this->assertSame([$this->candidateInA->id, $this->secondInA->id], $this->attentionIds($alpha));
        foreach ($alpha['requiringAttention'] as $entry) {
            $this->assertSame('Subject 1', $entry['lowest']['subject']);
            $this->assertSame($this->offeringA1->id, $entry['mostSerious']['classSubjectId']);
        }
    }

    public function test_dashboard_entries_contain_only_summary_fields(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-04 09:00:00'));
        $this->correct($this->quiz1, $this->secondInA, '58', 'Marking error on item 4.', $this->alpha);
        $draft = $this->createAssessment($this->quizzes, 'Quiz 2', '100', '2026-09-10');
        $this->recordScoreWithComment($draft, $this->candidateInA, '40', 'Draft note about a retake.', $this->alpha);

        $summaries = [
            'overview' => $this->dashboardProps($this->academicAdmin)['academicOverview'],
            'alpha' => $this->dashboardProps($this->alpha)['academicAlerts'],
            'bravo' => $this->dashboardProps($this->bravo)['academicAlerts'],
        ];

        foreach ($summaries as $label => $summary) {
            // Teaching alerts also break each taught subject down by standing (aggregates only).
            $expectedKeys = $label === 'overview' ? self::SUMMARY_KEYS : [...self::SUMMARY_KEYS, 'subjects'];
            $this->assertEqualsCanonicalizing($expectedKeys, array_keys($summary), $label);
            $this->assertNotEmpty($summary['requiringAttention'], $label);
            foreach ($summary['subjects'] ?? [] as $subject) {
                $this->assertEqualsCanonicalizing(['classSubjectId', 'classId', 'subject', 'classBatch', 'average', 'counts'], array_keys($subject), $label);
            }

            foreach ($summary['requiringAttention'] as $entry) {
                $this->assertEqualsCanonicalizing(self::ENTRY_KEYS, array_keys($entry), $label);
                $this->assertEqualsCanonicalizing(['id', 'candidateNumber', 'name', 'status'], array_keys($entry['candidate']), $label);
                $this->assertEqualsCanonicalizing(['id', 'name'], array_keys($entry['classBatch']), $label);
                $this->assertEqualsCanonicalizing(['value', 'label', 'tone'], array_keys($entry['standing']), $label);
                $this->assertEqualsCanonicalizing(['grade', 'subject', 'isProvisional'], array_keys($entry['lowest']), $label);
                $this->assertEqualsCanonicalizing([...self::WARNING_KEYS, 'canOpenGradebook'], array_keys($entry['mostSerious']), $label);
            }

            // No individual scores, comments, reasons, or assessment titles.
            $keys = $this->keysIn($summary);
            foreach (['score', 'scores', 'comment', 'reason', 'assessment', 'assessments', 'categories', 'username'] as $forbidden) {
                $this->assertNotContains($forbidden, $keys, "{$label}: {$forbidden}");
            }
            $json = (string) json_encode($summary);
            foreach ([self::SCORE_COMMENT, 'Draft note about a retake.', 'Marking error on item 4.', 'Quiz 1', 'Quiz 2'] as $text) {
                $this->assertStringNotContainsString($text, $json, $label);
            }
        }

        $a2 = $summaries['overview']['requiringAttention'][0];
        $this->assertSame([
            'id' => $this->secondInA->id,
            'candidateNumber' => $this->secondInA->candidate_number,
            'name' => $this->secondInA->full_name,
            'status' => null,
        ], $a2['candidate']);
        $this->assertEquals(58.0, $a2['lowest']['grade']);
    }

    public function test_dashboards_use_a_fixed_number_of_queries_however_many_candidates_need_attention(): void
    {
        $count = function (User $user): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->dashboardProps($user);
            $queries = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $queries;
        };

        $count($this->academicAdmin);
        $count($this->alpha);
        $adminBefore = $count($this->academicAdmin);
        $alphaBefore = $count($this->alpha);

        // Six more Failing candidates in Batch A (scored 10 on Quiz 1 through corrections).
        $this->travelTo(CarbonImmutable::parse('2026-09-04 09:00:00'));
        foreach (range(301, 306) as $number) {
            $this->correct($this->quiz1, $this->candidate($this->batchA, (string) $number), '10', 'Score entered after finalization.', $this->alpha);
        }

        $overview = $this->dashboardProps($this->academicAdmin)['academicOverview'];
        $this->assertCount(5, $overview['requiringAttention']);
        $this->assertSame($adminBefore, $count($this->academicAdmin));
        $this->assertSame($alphaBefore, $count($this->alpha));
    }

    // ------------------------------------------------------------------
    // Candidate profile: Current Warnings
    // ------------------------------------------------------------------

    public function test_administrator_profile_warnings_cover_every_subject_most_serious_first(): void
    {
        $props = $this->profileProps($this->academicAdmin, $this->secondInA);

        $this->assertSame('all', $props['standing']['scope']);
        $this->assertSame([
            [
                'classSubjectId' => $this->offeringA1->id,
                'subject' => 'Subject 1',
                'standing' => 'failing',
                'grade' => 60.0,
                'missingScores' => 0,
                'isProvisional' => true,
            ],
            [
                'classSubjectId' => $this->offeringA2->id,
                'subject' => 'Subject 2',
                'standing' => 'incomplete',
                'grade' => null,
                'missingScores' => 1,
                'isProvisional' => false,
            ],
        ], $this->warningsSummary($props['warnings']));
        foreach ($props['warnings'] as $warning) {
            $this->assertEqualsCanonicalizing(self::WARNING_KEYS, array_keys($warning));
        }

        // A1 passes Subject 1 (90) and fails Subject 2 (70): only Subject 2 is a warning.
        $warnings = $this->profileProps($this->academicAdmin, $this->candidateInA)['warnings'];
        $this->assertSame(['Subject 2'], array_column($warnings, 'subject'));
        $this->assertSame('failing', $warnings[0]['standing']['value']);
        $this->assertSame('Failing', $warnings[0]['standing']['label']);

        $warnings = $this->profileProps($this->academicAdmin, $this->candidateInB)['warnings'];
        $this->assertSame([['Subject 1', 'at_risk']], array_map(fn (array $warning): array => [$warning['subject'], $warning['standing']['value']], $warnings));

        $this->assertSame([], $this->profileProps($this->academicAdmin, $this->passingInB)['warnings']);

        $warnings = $this->profileProps($this->academicAdmin, $this->unscoredInB)['warnings'];
        $this->assertSame([['Subject 1', 'incomplete', 1]], array_map(
            fn (array $warning): array => [$warning['subject'], $warning['standing']['value'], $warning['missingScores']],
            $warnings,
        ));
    }

    public function test_warnings_list_the_lowest_grade_first_among_equally_serious_subjects(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-04 09:00:00'));
        $this->correct($this->quiz1, $this->candidateInA, '50', 'Rechecked the answer sheet.', $this->alpha);

        $warnings = $this->profileProps($this->academicAdmin, $this->candidateInA)['warnings'];

        // Both Failing: Subject 1 (50) before Subject 2 (70).
        $this->assertSame(['Subject 1', 'Subject 2'], array_column($warnings, 'subject'));
        $this->assertEquals([50.0, 70.0], array_column($warnings, 'grade'));
    }

    public function test_instructor_profile_warnings_cover_only_the_subjects_they_teach(): void
    {
        // A1 is Failing Bravo's Subject 2 but Passing Alpha's Subject 1.
        $props = $this->profileProps($this->alpha, $this->candidateInA);
        $this->assertSame('taught', $props['standing']['scope']);
        $this->assertSame('passing', $props['standing']['overall']['standing']['value']);
        $this->assertSame([], $props['warnings']);

        $warnings = $this->profileProps($this->alpha, $this->secondInA)['warnings'];
        $this->assertSame([[$this->offeringA1->id, 'failing']], array_map(
            fn (array $warning): array => [$warning['classSubjectId'], $warning['standing']['value']],
            $warnings,
        ));

        // Bravo does not learn about A2's Failing grade in Alpha's subject.
        $warnings = $this->profileProps($this->bravo, $this->secondInA)['warnings'];
        $this->assertSame([[$this->offeringA2->id, 'incomplete']], array_map(
            fn (array $warning): array => [$warning['classSubjectId'], $warning['standing']['value']],
            $warnings,
        ));

        // Alpha does not teach Batch B.
        $this->actingAs($this->alpha)->get("/candidates/{$this->candidateInB->id}")->assertForbidden();
    }

    public function test_profile_warnings_use_the_same_definition_as_academic_monitoring(): void
    {
        foreach ([$this->academicAdmin, $this->alpha, $this->bravo] as $viewer) {
            $rows = $this->actingAs($viewer)->get('/monitoring')->assertOk()->inertiaProps('candidates.data');

            foreach ($rows as $row) {
                $candidate = Candidate::query()->findOrFail($row['candidate']['id']);
                $warnings = $this->profileProps($viewer, $candidate)['warnings'];

                $this->assertSame($row['concerns'], $warnings, "{$viewer->name}: {$candidate->full_name}");
            }
        }
    }

    public function test_profile_warnings_need_the_thresholds_of_the_candidates_period(): void
    {
        // Old1 has 50 in the past period, which has no passing and warning grades.
        $props = $this->profileProps($this->academicAdmin, $this->oldCandidate);
        $this->assertSame([], $props['warnings']);
        $this->assertNull($props['standing']['thresholds']);

        $this->app->make(GradingThresholdService::class)->save($this->pastPeriod, '75', '80', null);

        $warnings = $this->profileProps($this->academicAdmin, $this->oldCandidate)['warnings'];
        $this->assertSame([['Subject 1', 'failing']], array_map(
            fn (array $warning): array => [$warning['subject'], $warning['standing']['value']],
            $warnings,
        ));
    }

    public function test_withdrawn_candidates_keep_their_results_but_have_no_warnings(): void
    {
        // A3 scored 40 (below passing) before withdrawing.
        $props = $this->profileProps($this->academicAdmin, $this->withdrawnInA);

        $this->assertFalse($props['standing']['monitored']);
        $this->assertSame([], $props['warnings']);
        $this->assertNull($props['standing']['overall']['standing']);
        $this->assertSame(['Quiz 1'], array_column(array_column($props['assessmentResults'][0]['assessments'], 'assessment'), 'title'));
        $this->assertSame('40', $props['assessmentResults'][0]['assessments'][0]['score']);
        // No result was recorded for them on the Subject 2 examination.
        $this->assertSame([], $props['assessmentResults'][1]['assessments']);
    }

    // ------------------------------------------------------------------
    // Candidate profile: Recent Assessments and Recent Academic Activity
    // ------------------------------------------------------------------

    public function test_recent_assessments_list_only_finalized_assessments_newest_first_without_comments(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-06 08:00:00'));
        $quiz3 = $this->createAssessment($this->quizzes, 'Quiz 3', '100', '2026-09-05');
        $this->recordScores($quiz3, [$this->candidateInA->id => '80', $this->secondInA->id => '70']);
        $this->finalize($quiz3);
        // A draft dated later than every finalized assessment, with a score and a comment.
        $draft = $this->createAssessment($this->quizzes, 'Quiz 2', '100', '2026-09-10');
        $this->recordScoreWithComment($draft, $this->secondInA, '45', 'Draft note about a retake.', $this->alpha);

        foreach ([$this->academicAdmin, $this->alpha] as $viewer) {
            $props = $this->profileProps($viewer, $this->secondInA);
            $subject1 = $props['assessmentResults'][0];

            $this->assertSame($this->offeringA1->id, $subject1['classSubjectId']);
            $this->assertSame(['code' => 'SUBJ-1', 'name' => 'Subject 1'], $subject1['subject']);
            $this->assertSame(['Quiz 3', 'Quiz 1'], array_column(array_column($subject1['assessments'], 'assessment'), 'title'), $viewer->name);
            $this->assertEquals([
                'assessment' => ['id' => $this->quiz1->id, 'title' => 'Quiz 1'],
                'subject' => 'Subject 1',
                'category' => 'Quizzes',
                'date' => '2026-08-20',
                'maxScore' => '100',
                'score' => '60',
                'percentage' => 60.0,
            ], $subject1['assessments'][1]);

            foreach ($props['recentActivity'] as $entry) {
                $this->assertNotSame('Quiz 2', $entry['assessment']['title'], $viewer->name);
            }

            $this->assertNotContains('comment', $this->keysIn($props['assessmentResults']));
            $this->assertNotContains('comment', $this->keysIn($props['recentActivity']));
            $json = (string) json_encode($props);
            $this->assertStringNotContainsString(self::SCORE_COMMENT, $json, $viewer->name);
            $this->assertStringNotContainsString('Draft note about a retake.', $json, $viewer->name);
        }

        // Administrators also see Subject 2, where A2's examination result is missing.
        $subject2 = $this->profileProps($this->academicAdmin, $this->secondInA)['assessmentResults'][1];
        $this->assertSame($this->offeringA2->id, $subject2['classSubjectId']);
        $this->assertCount(1, $subject2['assessments']);
        $this->assertSame('Subject 2 Examination', $subject2['assessments'][0]['assessment']['title']);
        $this->assertNull($subject2['assessments'][0]['score']);
        $this->assertNull($subject2['assessments'][0]['percentage']);
    }

    /**
     * Undated assessments show the finalization day in the institution's
     * timezone, so "newest first" must follow that day too.
     *
     * Application bug found by this test: CandidateAcademicRecord orders by
     * coalesce(assessed_on, date(finalized_at)), where date(finalized_at) is
     * the UTC day, while the displayed date uses INSTITUTION_TIMEZONE.
     */
    public function test_recent_assessments_stay_newest_first_by_the_displayed_date_outside_utc(): void
    {
        config(['institution.timezone' => 'Asia/Manila']);

        // Undated, finalized 2026-09-05 17:00 UTC, which is 2026-09-06 01:00 in Manila.
        $this->travelTo(CarbonImmutable::parse('2026-09-05 17:00:00'));
        $undated = $this->createAssessment($this->quizzes, 'Quiz 8', '100');
        $this->recordScores($undated, [$this->secondInA->id => '70']);
        $this->finalize($undated);

        // Dated 2026-09-05, finalized later (2026-09-06 03:00 UTC).
        $this->travelTo(CarbonImmutable::parse('2026-09-06 03:00:00'));
        $dated = $this->createAssessment($this->quizzes, 'Quiz 7', '100', '2026-09-05');
        $this->recordScores($dated, [$this->secondInA->id => '72']);
        $this->finalize($dated);

        $rows = $this->profileProps($this->academicAdmin, $this->secondInA)['assessmentResults'][0]['assessments'];
        $byTitle = array_combine(array_column(array_column($rows, 'assessment'), 'title'), array_column($rows, 'date'));

        $this->assertSame(['Quiz 8' => '2026-09-06', 'Quiz 7' => '2026-09-05', 'Quiz 1' => '2026-08-20'], [
            'Quiz 8' => $byTitle['Quiz 8'],
            'Quiz 7' => $byTitle['Quiz 7'],
            'Quiz 1' => $byTitle['Quiz 1'],
        ]);
        $this->assertSame(
            ['Quiz 8', 'Quiz 7', 'Quiz 1'],
            array_column(array_column($rows, 'assessment'), 'title'),
            'Listed in the order of the dates shown: '.json_encode($byTitle),
        );
    }

    public function test_recent_activity_lists_corrections_with_their_reasons_and_never_draft_changes(): void
    {
        // Draft work: a recorded and then updated score on an unfinalized quiz.
        $this->travelTo(CarbonImmutable::parse('2026-09-04 09:00:00'));
        $draft = $this->createAssessment($this->quizzes, 'Quiz 2', '100', '2026-09-04');
        $this->recordScores($draft, [$this->secondInA->id => '40']);
        $this->travelTo(CarbonImmutable::parse('2026-09-04 09:05:00'));
        $this->recordScores($draft, [$this->secondInA->id => '45']);

        $this->travelTo(CarbonImmutable::parse('2026-09-05 10:00:00'));
        $this->correct($this->quiz1, $this->secondInA, '62', 'Marking error on item 4.', $this->alpha);
        // Another candidate's correction does not belong on A2's profile.
        $this->travelTo(CarbonImmutable::parse('2026-09-05 11:00:00'));
        $this->correct($this->quiz1, $this->candidateInA, '88', 'Tally error for another candidate.', $this->alpha);

        $props = $this->profileProps($this->academicAdmin, $this->secondInA);
        $activity = $props['recentActivity'];

        $this->assertNotEmpty($activity);
        $corrections = array_values(array_filter($activity, fn (array $entry): bool => $entry['type'] === 'corrected'));
        $this->assertCount(1, $corrections);
        $this->assertSame($corrections[0], $activity[0], 'The correction is the newest activity.');

        $correction = $corrections[0];
        $this->assertEqualsCanonicalizing(
            ['type', 'id', 'at', 'assessment', 'subject', 'previousScore', 'newScore', 'maxScore', 'reason', 'changedBy'],
            array_keys($correction),
        );
        $this->assertSame(['id' => $this->quiz1->id, 'title' => 'Quiz 1'], $correction['assessment']);
        $this->assertSame('Subject 1', $correction['subject']);
        $this->assertSame('60', $correction['previousScore']);
        $this->assertSame('62', $correction['newScore']);
        $this->assertSame('100', $correction['maxScore']);
        $this->assertSame('Marking error on item 4.', $correction['reason']);
        $this->assertSame('Instructor Alpha', $correction['changedBy']);
        $this->assertSame('2026-09-05T10:00:00+00:00', $correction['at']);

        // Only finalized results and corrections; never draft work; newest first.
        $previous = null;
        foreach ($activity as $entry) {
            $this->assertContains($entry['type'], ['finalized', 'corrected']);
            $this->assertNotSame($draft->id, $entry['assessment']['id']);
            $this->assertNotSame('Quiz 2', $entry['assessment']['title']);
            if ($previous !== null) {
                $this->assertGreaterThanOrEqual(strtotime($entry['at']), strtotime($previous));
            }
            $previous = $entry['at'];
        }

        $this->assertNotContains('comment', $this->keysIn($activity));
        $json = (string) json_encode($activity);
        $this->assertStringNotContainsString('Tally error for another candidate.', $json);
        $this->assertStringNotContainsString(self::SCORE_COMMENT, $json);

        // The corrected score is the one listed under Recent Assessments.
        $this->assertSame('62', $props['assessmentResults'][0]['assessments'][0]['score']);
    }

    public function test_instructors_see_results_and_activity_only_for_the_subjects_they_teach(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-05 10:00:00'));
        $this->correct($this->quiz1, $this->secondInA, '62', 'Marking error on item 4.', $this->alpha);
        $this->travelTo(CarbonImmutable::parse('2026-09-06 10:00:00'));
        $this->correct($this->exam2, $this->secondInA, '55', 'Late paper accepted by the board.', $this->bravo);

        // Bravo: Subject 2 only.
        $props = $this->profileProps($this->bravo, $this->secondInA);
        $this->assertSame([$this->offeringA2->id], array_column($props['assessmentResults'], 'classSubjectId'));
        $this->assertNotEmpty($props['recentActivity']);
        foreach ($props['recentActivity'] as $entry) {
            $this->assertSame('Subject 2', $entry['subject']);
        }
        $this->assertSame(['Late paper accepted by the board.'], $this->correctionReasons($props['recentActivity']));
        $this->assertStringNotContainsString('Marking error on item 4.', (string) json_encode($props));
        $this->assertSame([[$this->offeringA2->id, 'failing']], array_map(
            fn (array $warning): array => [$warning['classSubjectId'], $warning['standing']['value']],
            $props['warnings'],
        ));

        // Alpha: Subject 1 only.
        $props = $this->profileProps($this->alpha, $this->secondInA);
        $this->assertSame([$this->offeringA1->id], array_column($props['assessmentResults'], 'classSubjectId'));
        foreach ($props['recentActivity'] as $entry) {
            $this->assertSame('Subject 1', $entry['subject']);
        }
        $this->assertSame(['Marking error on item 4.'], $this->correctionReasons($props['recentActivity']));
        $this->assertStringNotContainsString('Late paper accepted by the board.', (string) json_encode($props));

        // Administrators: both corrections, newest first.
        $props = $this->profileProps($this->academicAdmin, $this->secondInA);
        $this->assertSame([$this->offeringA1->id, $this->offeringA2->id], array_column($props['assessmentResults'], 'classSubjectId'));
        $this->assertSame(['Late paper accepted by the board.', 'Marking error on item 4.'], $this->correctionReasons($props['recentActivity']));
    }

    public function test_profile_additions_use_a_fixed_number_of_queries(): void
    {
        $count = function (): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->profileProps($this->academicAdmin, $this->secondInA);
            $queries = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $queries;
        };

        // One correction already exists, so the baseline includes the eager
        // loads that Eloquent skips when a query returns no rows.
        $this->travelTo(CarbonImmutable::parse('2026-09-04 09:00:00'));
        $this->correct($this->quiz1, $this->secondInA, '61', 'Marking error on item 4.', $this->alpha);

        $count();
        $before = $count();

        // Three more finalized quizzes, each corrected once for A2.
        foreach (['Quiz 4', 'Quiz 5', 'Quiz 6'] as $index => $title) {
            $this->travelTo(CarbonImmutable::parse('2026-09-1'.$index.' 08:00:00'));
            $quiz = $this->createAssessment($this->quizzes, $title, '100', '2026-09-1'.$index);
            $this->recordScores($quiz, [$this->secondInA->id => '50']);
            $this->finalize($quiz);
            $this->correct($quiz, $this->secondInA, '55', "Correction of {$title}.", $this->alpha);
        }

        $props = $this->profileProps($this->academicAdmin, $this->secondInA);
        $this->assertCount(4, $props['assessmentResults'][0]['assessments']);
        $this->assertSame($before, $count());
    }

    public function test_a_candidate_without_a_class_has_no_warnings_results_or_activity(): void
    {
        $unassigned = $this->candidate(null, '999');

        $props = $this->profileProps($this->academicAdmin, $unassigned);

        $this->assertFalse($props['standing']['monitored']);
        $this->assertSame([], $props['warnings']);
        $this->assertSame([], $props['assessmentResults']);
        $this->assertSame([], $props['recentActivity']);
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function candidate(?ClassBatch $classBatch, string $number): Candidate
    {
        return Candidate::factory()->create([
            'class_batch_id' => $classBatch?->id,
            'candidate_number' => "2026-M{$number}",
            'first_name' => 'Candidate',
            'last_name' => $number,
        ]);
    }

    /**
     * A staff account whose custom role grants staff access plus the given permissions.
     *
     * @param  list<PermissionCode>  $permissions
     */
    private function staffWithPermissions(string $roleCode, string $name, array $permissions): User
    {
        $role = Role::query()->create(['code' => $roleCode, 'name' => ucwords(str_replace('_', ' ', $roleCode))]);
        $codes = array_map(fn (PermissionCode $permission): string => $permission->value, [PermissionCode::AccessStaffArea, ...$permissions]);
        $role->permissions()->sync(Permission::query()->whereIn('code', $codes)->pluck('id'));

        $user = $this->userWithRole(SystemRole::Instructor, ['name' => $name]);
        $user->role()->associate($role)->save();

        return $user->fresh();
    }

    private function recordScoreWithComment(Assessment $assessment, Candidate $candidate, string $score, string $comment, User $actor): void
    {
        $this->app->make(ScoreRecordingService::class)->recordDraftScores($assessment, [
            $candidate->id => ['score' => $score, 'comment' => $comment, 'expected_score' => null, 'expected_comment' => null],
        ], $actor);
    }

    /**
     * Corrects a finalized score, keeping its comment.
     */
    private function correct(Assessment $assessment, Candidate $candidate, ?string $score, string $reason, User $actor): void
    {
        $current = AssessmentScore::query()
            ->where('assessment_id', $assessment->id)
            ->where('candidate_id', $candidate->id)
            ->first();

        $this->app->make(ScoreRecordingService::class)->correctFinalizedScore(
            $assessment,
            $candidate->id,
            $score,
            $current?->comment,
            $reason,
            DecimalValue::normalize($current?->score),
            $current?->comment,
            $actor,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function dashboardProps(User $user): array
    {
        return $this->actingAs($user)
            ->get('/dashboard')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('staff/dashboard'))
            ->inertiaProps();
    }

    /**
     * @return array<string, mixed>
     */
    private function profileProps(User $viewer, Candidate $candidate): array
    {
        return $this->actingAs($viewer)
            ->get("/candidates/{$candidate->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('staff/candidates/show'))
            ->inertiaProps();
    }

    /**
     * @param  array<string, mixed>  $summary
     * @return list<int>
     */
    private function attentionIds(array $summary): array
    {
        return array_map(fn (array $entry): int => $entry['candidate']['id'], $summary['requiringAttention']);
    }

    /**
     * @param  array{0: int, 1: int, 2: int, 3: int, 4: int, 5: int}  $expected  monitored, failing, needs improvement, incomplete, passing, no standing
     * @param  array<string, int>  $counts
     */
    private function assertCounts(array $expected, array $counts): void
    {
        $this->assertEquals(array_combine(['monitored', 'failing', 'atRisk', 'incomplete', 'passing', 'noStanding'], $expected), $counts);
        $this->assertSame(
            $counts['monitored'],
            $counts['failing'] + $counts['atRisk'] + $counts['incomplete'] + $counts['passing'] + $counts['noStanding'],
            'The counts must add up to the monitored total.',
        );
    }

    /**
     * Warnings with the standing reduced to its value, in a fixed key order.
     *
     * @param  list<array<string, mixed>>  $warnings
     * @return list<array<string, mixed>>
     */
    private function warningsSummary(array $warnings): array
    {
        return array_map(fn (array $warning): array => [
            'classSubjectId' => $warning['classSubjectId'],
            'subject' => $warning['subject'],
            'standing' => $warning['standing']['value'] ?? null,
            'grade' => $warning['grade'] === null ? null : (float) $warning['grade'],
            'missingScores' => $warning['missingScores'],
            'isProvisional' => $warning['isProvisional'],
        ], $warnings);
    }

    /**
     * @param  list<array<string, mixed>>  $activity
     * @return list<string>
     */
    private function correctionReasons(array $activity): array
    {
        return array_values(array_map(
            fn (array $entry): string => $entry['reason'],
            array_filter($activity, fn (array $entry): bool => $entry['type'] === 'corrected'),
        ));
    }

    /**
     * Every string key at any depth.
     *
     * @return list<string>
     */
    private function keysIn(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $keys = [];
        foreach ($value as $key => $child) {
            if (is_string($key)) {
                $keys[] = $key;
            }
            array_push($keys, ...$this->keysIn($child));
        }

        return array_values(array_unique($keys));
    }
}
