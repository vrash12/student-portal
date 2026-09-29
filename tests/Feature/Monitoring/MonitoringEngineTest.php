<?php

namespace Tests\Feature\Monitoring;

use App\Enums\AcademicStanding;
use App\Enums\CandidateStatus;
use App\Enums\Permission as PermissionCode;
use App\Enums\SystemRole;
use App\Models\AcademicPeriod;
use App\Models\Assessment;
use App\Models\AssessmentCategory;
use App\Models\AssessmentScore;
use App\Models\Candidate;
use App\Models\ClassBatch;
use App\Models\ClassSubject;
use App\Models\InstructorAssignment;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Subject;
use App\Models\User;
use App\Services\Grading\GradeCalculationService;
use App\Services\Grading\GradingThresholds;
use App\Services\Grading\GradingThresholdService;
use App\Services\Grading\ScoreRecordingService;
use App\Services\Grading\SubjectGrade;
use App\Services\Monitoring\AcademicMonitoring;
use App\Services\Monitoring\CandidateAcademicRecord;
use App\Services\Monitoring\MonitoredCandidate;
use App\Services\Monitoring\MonitoringScope;
use App\Services\Monitoring\SubjectConcerns;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Grading\BuildsGradingFixtures;
use Tests\TestCase;

/**
 * Milestone 6, independent tests of the monitoring engine (services only):
 * MonitoringScope, AcademicMonitoring (population, standings, counts,
 * filters, sorts, attention lists, dashboard summary), MonitoredCandidate,
 * SubjectConcerns, GradeCalculationService::forClasses() agreeing with
 * forOffering() / forCandidate(), and query budgets.
 *
 * Most tests use the monitoring scenario (see buildMonitoringScenario()) on
 * top of the grading fixtures (see BuildsGradingFixtures).
 */
class MonitoringEngineTest extends TestCase
{
    use BuildsGradingFixtures;

    private const THRESHOLD_REASON = 'Synthetic thresholds for automated monitoring tests.';

    /** Batch A. Scenario: At Risk in Subject 1, Passing in Subject 2. */
    private Candidate $candidateA4;

    /** Batch A. Scenario: no scores at all (Incomplete everywhere). */
    private Candidate $candidateA5;

    /** Batch A, on leave. Scenario: Passing in both subjects. */
    private Candidate $candidateA6;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildGradingFixtures();
    }

    // ------------------------------------------------------------------
    // MonitoringScope
    // ------------------------------------------------------------------

    public function test_view_all_scope_covers_every_class_subject_of_the_selected_period_and_nothing_else(): void
    {
        // A class subject nobody teaches is still monitored by administrators.
        $batchC = ClassBatch::factory()->for($this->activePeriod)->create(['name' => 'Sample Batch C']);
        $unassigned = $this->offering($batchC, Subject::factory()->create(['code' => 'SUBJ-3', 'name' => 'Subject 3']));
        $superAdmin = $this->userWithRole(SystemRole::SuperAdministrator, ['name' => 'Super Admin']);
        $unknownPeriodId = (int) AcademicPeriod::query()->max('id') + 1000;

        foreach (['academic administrator' => $this->academicAdmin, 'super administrator' => $superAdmin] as $label => $administrator) {
            $scope = MonitoringScope::for($administrator->fresh());

            $this->assertSame('all', $scope->kind(), $label);
            $this->assertFalse($scope->isEmpty(), $label);
            $this->assertSame(
                $this->sortedIds([$this->offeringA1->id, $this->offeringA2->id, $this->offeringB1->id, $unassigned->id]),
                $this->scopedOfferingIds($scope, $this->activePeriod),
                $label,
            );
            $this->assertSame([$this->oldOffering()->id], $this->scopedOfferingIds($scope, $this->pastPeriod), $label);
            $this->assertSame([], $scope->offerings($unknownPeriodId)->get()->modelKeys(), $label);
        }
    }

    public function test_teaching_scope_covers_only_the_class_subjects_the_instructor_is_assigned_to(): void
    {
        $charlie = $this->userWithRole(SystemRole::Instructor, ['name' => 'Instructor Charlie']);

        $alpha = MonitoringScope::for($this->alpha->fresh());
        $this->assertSame('taught', $alpha->kind());
        $this->assertFalse($alpha->isEmpty());
        // Subject 1 of Batch B is the same subject, but Bravo teaches it there.
        $this->assertSame([$this->offeringA1->id], $this->scopedOfferingIds($alpha, $this->activePeriod));
        $this->assertSame([$this->oldOffering()->id], $this->scopedOfferingIds($alpha, $this->pastPeriod));

        // Bravo teaches Subject 2 of Batch A and Subject 1 of Batch B, but not
        // Alpha's Subject 1 of Batch A, although it is in the same class.
        $bravo = MonitoringScope::for($this->bravo->fresh());
        $this->assertSame($this->sortedIds([$this->offeringA2->id, $this->offeringB1->id]), $this->scopedOfferingIds($bravo, $this->activePeriod));
        $this->assertSame([], $this->scopedOfferingIds($bravo, $this->pastPeriod));

        // An instructor without assignments monitors nothing, but is still teaching staff.
        $unassigned = MonitoringScope::for($charlie->fresh());
        $this->assertSame('taught', $unassigned->kind());
        $this->assertSame([], $this->scopedOfferingIds($unassigned, $this->activePeriod));

        // For teaching staff without view_all, for() and teaching() are the same scope.
        $this->assertSame(
            $this->scopedOfferingIds($bravo, $this->activePeriod),
            $this->scopedOfferingIds(MonitoringScope::teaching($this->bravo->fresh()), $this->activePeriod),
        );
    }

    public function test_deactivated_and_former_instructors_candidates_and_deactivated_administrators_get_an_empty_scope(): void
    {
        $this->alpha->forceFill(['is_active' => false])->save();
        // Bravo keeps the monitoring permission but is no longer teaching staff.
        $observer = $this->roleWith('observer', 'Observer', [PermissionCode::AccessStaffArea, PermissionCode::ViewAcademicMonitoring]);
        $this->bravo->role()->associate($observer)->save();
        $this->academicAdmin->forceFill(['is_active' => false])->save();
        $this->setThresholds($this->activePeriod, '75', '80');
        $this->finalizedAssessment($this->quizzes, 'Quiz 1', '20', [$this->candidateInA->id => '10']);

        $users = [
            'deactivated instructor' => $this->alpha->fresh(),
            'former instructor' => $this->bravo->fresh(),
            'candidate' => $this->candidateInA->user->fresh(),
            'deactivated administrator' => $this->academicAdmin->fresh(),
        ];

        foreach ($users as $label => $user) {
            foreach (['for' => MonitoringScope::for($user), 'teaching' => MonitoringScope::teaching($user)] as $method => $scope) {
                $message = "{$label}, {$method}()";
                $this->assertTrue($scope->isEmpty(), $message);
                $this->assertSame('none', $scope->kind(), $message);
                $this->assertSame([], $this->scopedOfferingIds($scope, $this->activePeriod), $message);
                $this->assertSame([], $this->scopedOfferingIds($scope, $this->pastPeriod), $message);
                $this->assertSame([], $scope->periods(), $message);
                $this->assertSame([], $this->monitoring()->evaluate($scope->offerings($this->activePeriod->id)->get()), $message);
                $this->assertNull($this->monitoring()->activePeriodSummary($scope, 5), $message);
            }
        }

        // The leftover assignments still exist; they simply grant nothing.
        $this->assertTrue(InstructorAssignment::query()->where('instructor_id', $this->alpha->id)->exists());
        $this->assertTrue(InstructorAssignment::query()->where('instructor_id', $this->bravo->id)->exists());
    }

    public function test_view_all_takes_precedence_over_teaching_but_the_teaching_scope_ignores_it(): void
    {
        $role = $this->roleWith('teaching_administrator', 'Teaching Administrator', [
            PermissionCode::AccessStaffArea,
            PermissionCode::ViewAllCandidates,
            PermissionCode::TeachClasses,
            PermissionCode::ViewAcademicMonitoring,
        ]);
        $delta = $this->userWithRole(SystemRole::Instructor, ['name' => 'Instructor Delta']);
        $delta->role()->associate($role)->save();
        $delta = $delta->fresh();
        $this->teach($delta, $this->offeringA1);

        $all = MonitoringScope::for($delta->fresh());
        $this->assertSame('all', $all->kind());
        $this->assertSame(
            $this->sortedIds([$this->offeringA1->id, $this->offeringA2->id, $this->offeringB1->id]),
            $this->scopedOfferingIds($all, $this->activePeriod),
        );

        // The instructor's own dashboard alerts cover only what they teach.
        $taught = MonitoringScope::teaching($delta->fresh());
        $this->assertSame('taught', $taught->kind());
        $this->assertSame([$this->offeringA1->id], $this->scopedOfferingIds($taught, $this->activePeriod));
        $this->assertSame([$this->activePeriod->id], array_column($taught->periods(), 'id'));

        // Administrators who do not teach have no teaching scope.
        $this->assertTrue(MonitoringScope::teaching($this->academicAdmin->fresh())->isEmpty());
    }

    public function test_selectable_periods_are_every_period_for_administrators_and_only_taught_periods_for_instructors(): void
    {
        $future = AcademicPeriod::factory()->create(['name' => 'Period Future', 'starts_on' => '2027-01-04', 'ends_on' => '2027-05-28']);
        $older = AcademicPeriod::factory()->create(['name' => 'Period Older', 'starts_on' => '2025-08-04', 'ends_on' => '2025-12-19']);
        $futureBatch = ClassBatch::factory()->for($future)->create(['name' => 'Sample Batch Future']);
        $this->teach($this->alpha, $this->offering($futureBatch, Subject::query()->where('code', 'SUBJ-1')->sole()));
        // Charlie teaches only in the past period.
        $charlie = $this->userWithRole(SystemRole::Instructor, ['name' => 'Instructor Charlie']);
        $pastBatch = ClassBatch::factory()->for($this->pastPeriod)->create(['name' => 'Sample Batch Past Two']);
        $this->teach($charlie, $this->offering($pastBatch, Subject::query()->where('code', 'SUBJ-2')->sole()));
        $echo = $this->userWithRole(SystemRole::Instructor, ['name' => 'Instructor Echo']);

        $current = ['id' => $this->activePeriod->id, 'name' => 'Period Current', 'isActive' => true];
        $futureRow = ['id' => $future->id, 'name' => 'Period Future', 'isActive' => false];
        $past = ['id' => $this->pastPeriod->id, 'name' => 'Period Past', 'isActive' => false];
        $olderRow = ['id' => $older->id, 'name' => 'Period Older', 'isActive' => false];

        // Active first, then newest; periods without classes included.
        $this->assertSame([$current, $futureRow, $past, $olderRow], MonitoringScope::for($this->academicAdmin->fresh())->periods());
        $this->assertSame([$current, $futureRow, $past], MonitoringScope::for($this->alpha->fresh())->periods());
        $this->assertSame([$current], MonitoringScope::for($this->bravo->fresh())->periods());
        $this->assertSame([$past], MonitoringScope::for($charlie->fresh())->periods());
        $this->assertSame([], MonitoringScope::for($echo->fresh())->periods());
    }

    // ------------------------------------------------------------------
    // Population, standings, and counts
    // ------------------------------------------------------------------

    public function test_population_is_every_gradable_candidate_of_the_scoped_classes_in_name_order(): void
    {
        $this->buildMonitoringScenario();
        $completed = $this->candidateIn($this->batchA, 'A7', CandidateStatus::Completed);
        $alternateB1 = Candidate::factory()->create(['class_batch_id' => $this->batchB->id, 'first_name' => 'Alternate', 'last_name' => 'B1']);
        // Not monitored: no class, or a class without any subject.
        Candidate::factory()->create(['last_name' => 'Unassigned']);
        $batchD = ClassBatch::factory()->for($this->activePeriod)->create(['name' => 'Sample Batch D']);
        $this->candidateIn($batchD, 'D1');

        $batchA = [
            $this->candidateInA->id, $this->secondInA->id, $this->candidateA4->id,
            $this->candidateA5->id, $this->candidateA6->id, $completed->id,
        ];

        // Withdrawn A3 and the past period's candidate are excluded; on leave
        // and completed candidates are included. Last name, then first name.
        $this->assertSame([...$batchA, $alternateB1->id, $this->candidateInB->id], $this->idsOf($this->evaluateFor($this->academicAdmin)));
        // Alpha teaches only in Batch A.
        $this->assertSame($batchA, $this->idsOf($this->evaluateFor($this->alpha)));
        $this->assertSame([...$batchA, $alternateB1->id, $this->candidateInB->id], $this->idsOf($this->evaluateFor($this->bravo)));
    }

    public function test_evaluate_with_no_class_subjects_returns_nothing_without_queries(): void
    {
        $this->buildMonitoringScenario();

        $result = null;
        $queries = $this->recordQueries(function () use (&$result): void {
            $result = $this->monitoring()->evaluate(new Collection);
        });

        $this->assertSame([], $result);
        $this->assertSame([], $queries);
    }

    public function test_administrator_standings_and_counts_cover_every_subject_and_reconcile(): void
    {
        $this->buildMonitoringScenario();

        $monitored = $this->evaluateFor($this->academicAdmin);

        $this->assertSame([
            $this->candidateInA->id => 'failing',   // Subject 2: 70.00
            $this->secondInA->id => 'failing',      // Subject 1: 60.00
            $this->candidateA4->id => 'at_risk',    // Subject 1: 75.00
            $this->candidateA5->id => 'incomplete', // nothing scored
            $this->candidateA6->id => 'passing',
            $this->candidateInB->id => 'none',      // grading not set up
        ], $this->standingsOf($monitored));

        $counts = $this->monitoring()->counts($monitored);
        $this->assertSame(['monitored' => 6, 'failing' => 2, 'atRisk' => 1, 'incomplete' => 1, 'passing' => 1, 'noStanding' => 1], $counts);
        $this->assertCountsReconcile($counts);

        // Each candidate carries every subject of their own class, by subject name.
        $a1 = $this->entryFor($monitored, $this->candidateInA);
        $this->assertSame([$this->offeringA1->id, $this->offeringA2->id], $this->subjectIdsOf($a1));
        $this->assertSame([80.0, 70.0], array_map(fn (array $subject): ?float => $subject['grade']->grade, $a1->subjects));
        $this->assertSame(['standing' => ['value' => 'failing', 'label' => 'Failing', 'tone' => 'danger'], 'basedOnSubjects' => 2, 'totalSubjects' => 2, 'isProvisional' => false], $a1->overall->toArray());

        $b1 = $this->entryFor($monitored, $this->candidateInB);
        $this->assertSame([$this->offeringB1->id], $this->subjectIdsOf($b1));
        $this->assertSame(['standing' => null, 'basedOnSubjects' => 0, 'totalSubjects' => 1, 'isProvisional' => false], $b1->overall->toArray());
    }

    public function test_instructor_standings_counts_and_lowest_grade_use_only_their_own_subjects(): void
    {
        $this->buildMonitoringScenario();

        // Alpha teaches only Subject 1 of Batch A.
        $alpha = $this->evaluateFor($this->alpha);
        $this->assertSame([
            $this->candidateInA->id => 'passing',   // Failing in Bravo's Subject 2 is not visible
            $this->secondInA->id => 'failing',
            $this->candidateA4->id => 'at_risk',
            $this->candidateA5->id => 'incomplete',
            $this->candidateA6->id => 'passing',
        ], $this->standingsOf($alpha));
        $this->assertSame(['monitored' => 5, 'failing' => 1, 'atRisk' => 1, 'incomplete' => 1, 'passing' => 2, 'noStanding' => 0], $this->monitoring()->counts($alpha));
        foreach ($alpha as $entry) {
            $this->assertSame([$this->offeringA1->id], $this->subjectIdsOf($entry));
            $this->assertSame(1, $entry->overall->totalSubjects);
        }
        $a1 = $this->entryFor($alpha, $this->candidateInA);
        $this->assertSame(80.0, $a1->lowestGrade());
        $this->assertSame($this->offeringA1->id, $a1->lowest()['offering']->id);
        $this->assertSame([], $a1->concerns());
        $this->assertSame(2, $this->entryFor($alpha, $this->candidateA5)->missingScores());

        // Bravo teaches Subject 2 of Batch A and Subject 1 of Batch B.
        $bravo = $this->evaluateFor($this->bravo);
        $this->assertSame([
            $this->candidateInA->id => 'failing',
            $this->secondInA->id => 'passing',      // Failing in Alpha's Subject 1 is not visible
            $this->candidateA4->id => 'passing',
            $this->candidateA5->id => 'incomplete',
            $this->candidateA6->id => 'passing',
            $this->candidateInB->id => 'none',
        ], $this->standingsOf($bravo));
        $this->assertSame(['monitored' => 6, 'failing' => 1, 'atRisk' => 0, 'incomplete' => 1, 'passing' => 3, 'noStanding' => 1], $this->monitoring()->counts($bravo));
        $a2 = $this->entryFor($bravo, $this->secondInA);
        $this->assertSame(90.0, $a2->lowestGrade());
        // The missing Subject 1 examination belongs to Alpha's subject.
        $this->assertSame(0, $a2->missingScores());
        $this->assertSame([], $a2->concerns());
    }

    public function test_narrowing_to_a_class_or_subject_recomputes_every_value_from_the_narrowed_set(): void
    {
        $this->buildMonitoringScenario();
        $scoped = MonitoringScope::for($this->academicAdmin->fresh())->offerings($this->activePeriod->id)->get();
        $subject1 = $this->offeringA1->subject_id;
        $subject2 = $this->offeringA2->subject_id;

        $cases = [
            'Batch A' => [
                $scoped->where('class_batch_id', $this->batchA->id)->values(),
                ['monitored' => 5, 'failing' => 2, 'atRisk' => 1, 'incomplete' => 1, 'passing' => 1, 'noStanding' => 0],
            ],
            'Subject 1 (Batch A and Batch B)' => [
                $scoped->where('subject_id', $subject1)->values(),
                ['monitored' => 6, 'failing' => 1, 'atRisk' => 1, 'incomplete' => 1, 'passing' => 2, 'noStanding' => 1],
            ],
            'Batch A, Subject 2' => [
                $scoped->where('class_batch_id', $this->batchA->id)->where('subject_id', $subject2)->values(),
                ['monitored' => 5, 'failing' => 1, 'atRisk' => 0, 'incomplete' => 1, 'passing' => 3, 'noStanding' => 0],
            ],
            'Batch B' => [
                $scoped->where('class_batch_id', $this->batchB->id)->values(),
                ['monitored' => 1, 'failing' => 0, 'atRisk' => 0, 'incomplete' => 0, 'passing' => 0, 'noStanding' => 1],
            ],
        ];

        foreach ($cases as $label => [$offerings, $expectedCounts]) {
            $monitored = $this->monitoring()->evaluate($offerings);
            $counts = $this->monitoring()->counts($monitored);

            $this->assertSame($expectedCounts, $counts, $label);
            $this->assertCountsReconcile($counts, $label);
            foreach ($monitored as $entry) {
                foreach ($entry->subjects as $subject) {
                    $this->assertContains($subject['offering']->id, $offerings->modelKeys(), $label);
                }
            }
        }

        // With Subject 1 only, A1's lowest grade is Subject 1's, not Subject 2's 70.00.
        $subjectOne = $this->monitoring()->evaluate($scoped->where('subject_id', $subject1)->values());
        $this->assertSame(80.0, $this->entryFor($subjectOne, $this->candidateInA)->lowestGrade());
        $this->assertSame('passing', $this->entryFor($subjectOne, $this->candidateInA)->standingKey());
    }

    public function test_period_without_thresholds_monitors_everyone_with_grades_but_no_standing(): void
    {
        $this->buildMonitoringScenario(withThresholds: false);

        $monitored = $this->evaluateFor($this->academicAdmin);
        $counts = $this->monitoring()->counts($monitored);

        $this->assertSame(['monitored' => 6, 'failing' => 0, 'atRisk' => 0, 'incomplete' => 0, 'passing' => 0, 'noStanding' => 6], $counts);
        $this->assertCountsReconcile($counts);
        // Grades are still calculated; only the standing needs thresholds.
        $this->assertSame([80.0, 70.0], array_map(fn (array $subject): ?float => $subject['grade']->grade, $this->entryFor($monitored, $this->candidateInA)->subjects));
        $this->assertSame([], $this->monitoring()->requiringAttention($monitored, 5));
        $this->assertSame([], $this->monitoring()->subjectsRequiringAttention($monitored));
        // Missing scores are still a concern.
        $concerns = $this->entryFor($monitored, $this->secondInA)->concerns();
        $this->assertSame([$this->offeringA1->id], array_map(fn (array $concern): int => $concern['offering']->id, $concerns));
        $this->assertNull($concerns[0]['grade']->standing);

        $summary = $this->monitoring()->activePeriodSummary(MonitoringScope::for($this->academicAdmin->fresh()), 5);
        $this->assertNotNull($summary);
        $this->assertFalse($summary['hasThresholds']);
        $this->assertSame($counts, $summary['counts']);
        $this->assertSame([], $summary['requiringAttention']);
    }

    public function test_past_period_uses_its_own_thresholds_and_monitors_completed_candidates(): void
    {
        $this->buildMonitoringScenario();
        $this->setThresholds($this->pastPeriod, '60', '65');
        $oldOffering = $this->oldOffering();
        $old1 = $this->oldCandidate();
        $this->setScheme($oldOffering, ['Quizzes' => '100']);
        $this->finalizedAssessment($this->category($oldOffering, 'Quizzes'), 'Old Quiz 1', '20', [$old1->id => '14']);

        // 70.00 is Passing at the past period's 60 / 65 (it would be Failing at 75 / 80).
        $this->assertSame([$old1->id => 'passing'], $this->standingsOf($this->evaluateFor($this->academicAdmin, $this->pastPeriod)));
        $this->assertSame([$old1->id => 'passing'], $this->standingsOf($this->evaluateFor($this->alpha, $this->pastPeriod)));
        $this->assertSame([], $this->evaluateFor($this->bravo, $this->pastPeriod));

        // The active period is unaffected.
        $this->assertSame(6, $this->monitoring()->counts($this->evaluateFor($this->academicAdmin))['monitored']);
    }

    // ------------------------------------------------------------------
    // Filters, sorts, and attention lists
    // ------------------------------------------------------------------

    public function test_standing_filter_and_search_ids_narrow_the_list_in_name_order(): void
    {
        $this->buildMonitoringScenario();
        $monitored = $this->evaluateFor($this->academicAdmin);
        $filter = fn (string $standing, ?array $matching): array => $this->idsOf($this->monitoring()->filter($monitored, $standing, $matching));

        $this->assertSame([$this->candidateInA->id, $this->secondInA->id], $filter('failing', null));
        $this->assertSame([$this->candidateA4->id], $filter('at_risk', null));
        $this->assertSame([$this->candidateA5->id], $filter('incomplete', null));
        $this->assertSame([$this->candidateA6->id], $filter('passing', null));
        $this->assertSame([$this->candidateInB->id], $filter('none', null));
        $this->assertSame($this->idsOf($monitored), $filter('', null));

        // Search results keep the list's order; ids outside the list are ignored.
        $outside = (int) Candidate::query()->max('id') + 1000;
        $this->assertSame([$this->candidateA4->id, $this->candidateInB->id], $filter('', [$this->candidateInB->id, $outside, $this->candidateA4->id]));
        $this->assertSame([$this->candidateInA->id], $filter('failing', [$this->candidateA4->id, $this->candidateInA->id]));
        // A search that matched nothing shows nothing.
        $this->assertSame([], $filter('', []));
    }

    public function test_default_sort_is_most_serious_first_then_lowest_grade_with_no_standing_last(): void
    {
        $this->buildMonitoringScenario();

        $admin = $this->evaluateFor($this->academicAdmin);
        $nameOrder = $this->idsOf($admin);
        $this->assertSame([
            $this->secondInA->id,     // Failing, 60.00
            $this->candidateInA->id,  // Failing, 70.00
            $this->candidateA4->id,   // At Risk
            $this->candidateA5->id,   // Incomplete
            $this->candidateA6->id,   // Passing
            $this->candidateInB->id,  // no standing yet
        ], $this->idsOf($this->monitoring()->sort($admin, '')));
        // The given list is not reordered.
        $this->assertSame($nameOrder, $this->idsOf($admin));

        // Within Alpha's subject, A1 and A6 are both Passing at 80.00: name order.
        $this->assertSame([
            $this->secondInA->id, $this->candidateA4->id, $this->candidateA5->id, $this->candidateInA->id, $this->candidateA6->id,
        ], $this->idsOf($this->monitoring()->sort($this->evaluateFor($this->alpha), '')));
    }

    public function test_grade_sorts_put_candidates_without_a_grade_last_and_keep_name_order_on_ties(): void
    {
        $this->buildMonitoringScenario();
        $admin = $this->evaluateFor($this->academicAdmin);
        $sorted = fn (array $monitored, string $sort): array => $this->idsOf($this->monitoring()->sort($monitored, $sort));

        // Lowest subject grades: A2 60, A1 70, A4 75, A6 80; A5 and B1 have none.
        $this->assertSame([
            $this->secondInA->id, $this->candidateInA->id, $this->candidateA4->id, $this->candidateA6->id, $this->candidateA5->id, $this->candidateInB->id,
        ], $sorted($admin, 'lowest'));
        $this->assertSame([
            $this->candidateA6->id, $this->candidateA4->id, $this->candidateInA->id, $this->secondInA->id, $this->candidateA5->id, $this->candidateInB->id,
        ], $sorted($admin, 'highest'));
        $this->assertSame($this->idsOf($admin), $sorted($admin, 'name'));

        // Alpha's subject: A1 and A6 tie at 80.00 and keep name order both ways.
        $alpha = $this->evaluateFor($this->alpha);
        $this->assertSame([
            $this->secondInA->id, $this->candidateA4->id, $this->candidateInA->id, $this->candidateA6->id, $this->candidateA5->id,
        ], $sorted($alpha, 'lowest'));
        $this->assertSame([
            $this->candidateInA->id, $this->candidateA6->id, $this->candidateA4->id, $this->secondInA->id, $this->candidateA5->id,
        ], $sorted($alpha, 'highest'));
    }

    public function test_requiring_attention_lists_only_failing_and_at_risk_candidates_most_serious_first_up_to_the_limit(): void
    {
        $this->buildMonitoringScenario();
        $admin = $this->evaluateFor($this->academicAdmin);

        $this->assertSame([$this->secondInA->id, $this->candidateInA->id, $this->candidateA4->id], $this->idsOf($this->monitoring()->requiringAttention($admin, 5)));
        $this->assertSame([$this->secondInA->id, $this->candidateInA->id], $this->idsOf($this->monitoring()->requiringAttention($admin, 2)));
        $this->assertSame([], $this->monitoring()->requiringAttention($admin, 0));

        // Within each instructor's own subjects.
        $this->assertSame([$this->secondInA->id, $this->candidateA4->id], $this->idsOf($this->monitoring()->requiringAttention($this->evaluateFor($this->alpha), 5)));
        $this->assertSame([$this->candidateInA->id], $this->idsOf($this->monitoring()->requiringAttention($this->evaluateFor($this->bravo), 5)));
    }

    public function test_subjects_requiring_attention_count_standings_per_class_subject_within_the_scope(): void
    {
        $this->buildMonitoringScenario();
        $batchA = ['id' => $this->batchA->id, 'name' => 'Sample Batch A'];
        $subjectOneRow = [
            'classSubjectId' => $this->offeringA1->id,
            'classBatch' => $batchA,
            'subject' => ['id' => $this->offeringA1->subject_id, 'name' => 'Subject 1'],
            'failing' => 1,    // A2
            'atRisk' => 1,     // A4
            'incomplete' => 1, // A5
        ];
        $subjectTwoRow = [
            'classSubjectId' => $this->offeringA2->id,
            'classBatch' => $batchA,
            'subject' => ['id' => $this->offeringA2->subject_id, 'name' => 'Subject 2'],
            'failing' => 1,    // A1
            'atRisk' => 0,
            'incomplete' => 1, // A5
        ];

        // Batch B Subject 1 has no Failing or At Risk candidate, so it is not listed.
        $this->assertSame([$subjectOneRow, $subjectTwoRow], $this->monitoring()->subjectsRequiringAttention($this->evaluateFor($this->academicAdmin)));
        $this->assertSame([$subjectOneRow], $this->monitoring()->subjectsRequiringAttention($this->evaluateFor($this->alpha)));
        $this->assertSame([$subjectTwoRow], $this->monitoring()->subjectsRequiringAttention($this->evaluateFor($this->bravo)));
    }

    public function test_subjects_requiring_attention_are_ordered_by_failing_then_at_risk_then_subject_and_class_name(): void
    {
        $this->buildMonitoringScenario();
        // Batch B Subject 1: two Failing, one At Risk.
        $b2 = $this->candidateIn($this->batchB, 'B2');
        $b3 = $this->candidateIn($this->batchB, 'B3');
        $this->setScheme($this->offeringB1, ['Quizzes' => '100']);
        $this->finalizedAssessment($this->category($this->offeringB1, 'Quizzes'), 'Batch B Quiz', '10', [
            $this->candidateInB->id => '5', $b2->id => '6', $b3->id => '7.6',
        ], $this->bravo);
        // Batch C: one Failing candidate in Subject 3 and Subject 2 (added in that order).
        $batchC = ClassBatch::factory()->for($this->activePeriod)->create(['name' => 'Sample Batch C']);
        $c1 = $this->candidateIn($batchC, 'C1');
        $offeringsC = [];
        foreach ([Subject::factory()->create(['code' => 'SUBJ-3', 'name' => 'Subject 3']), Subject::query()->where('code', 'SUBJ-2')->sole()] as $subject) {
            $offering = $this->offering($batchC, $subject);
            $this->setScheme($offering, ['Assessments' => '100']);
            $this->finalizedAssessment($this->category($offering, 'Assessments'), "{$subject->name} Test", '100', [$c1->id => '50']);
            $offeringsC[$subject->name] = $offering;
        }

        $rows = $this->monitoring()->subjectsRequiringAttention($this->evaluateFor($this->academicAdmin));

        $this->assertSame([
            $this->offeringB1->id,            // 2 Failing, 1 At Risk
            $this->offeringA1->id,            // 1 Failing, 1 At Risk
            $this->offeringA2->id,            // 1 Failing: Subject 2, Sample Batch A
            $offeringsC['Subject 2']->id,     // 1 Failing: Subject 2, Sample Batch C
            $offeringsC['Subject 3']->id,     // 1 Failing: Subject 3
        ], array_column($rows, 'classSubjectId'));
        $this->assertSame([2, 1, 0], [$rows[0]['failing'], $rows[0]['atRisk'], $rows[0]['incomplete']]);
    }

    // ------------------------------------------------------------------
    // Dashboard summary
    // ------------------------------------------------------------------

    public function test_active_period_summary_for_administrators_and_instructors(): void
    {
        $this->buildMonitoringScenario();

        $admin = $this->monitoring()->activePeriodSummary(MonitoringScope::for($this->academicAdmin->fresh()), 5);
        $this->assertNotNull($admin);
        $this->assertSame(['id' => $this->activePeriod->id, 'name' => 'Period Current'], $admin['period']);
        $this->assertSame('all', $admin['scope']);
        $this->assertTrue($admin['hasThresholds']);
        $this->assertSame(['monitored' => 6, 'failing' => 2, 'atRisk' => 1, 'incomplete' => 1, 'passing' => 1, 'noStanding' => 1], $admin['counts']);
        $this->assertContainsOnlyInstancesOf(MonitoredCandidate::class, $admin['requiringAttention']);
        $this->assertSame([$this->secondInA->id, $this->candidateInA->id, $this->candidateA4->id], $this->idsOf($admin['requiringAttention']));

        $alpha = $this->monitoring()->activePeriodSummary(MonitoringScope::teaching($this->alpha->fresh()), 5);
        $this->assertNotNull($alpha);
        $this->assertSame('taught', $alpha['scope']);
        $this->assertSame(['monitored' => 5, 'failing' => 1, 'atRisk' => 1, 'incomplete' => 1, 'passing' => 2, 'noStanding' => 0], $alpha['counts']);
        $this->assertSame([$this->secondInA->id, $this->candidateA4->id], $this->idsOf($alpha['requiringAttention']));
        // A1's Failing Subject 2 belongs to Bravo, so A1 is not listed for Alpha.
        $this->assertNotContains($this->candidateInA->id, $this->idsOf($alpha['requiringAttention']));

        $limited = $this->monitoring()->activePeriodSummary(MonitoringScope::for($this->academicAdmin->fresh()), 1);
        $this->assertSame([$this->secondInA->id], $this->idsOf($limited['requiringAttention']));
        // The limit applies to the list only, never to the counts.
        $this->assertSame(6, $limited['counts']['monitored']);
    }

    public function test_active_period_summary_is_null_without_an_active_period_or_with_an_empty_scope(): void
    {
        $this->buildMonitoringScenario();

        $this->assertNull($this->monitoring()->activePeriodSummary(MonitoringScope::for($this->candidateInA->user->fresh()), 5));
        $this->assertNull($this->monitoring()->activePeriodSummary(MonitoringScope::teaching($this->academicAdmin->fresh()), 5));

        $this->activePeriod->forceFill(['is_active' => false])->save();

        $this->assertNull($this->monitoring()->activePeriodSummary(MonitoringScope::for($this->academicAdmin->fresh()), 5));
        $this->assertNull($this->monitoring()->activePeriodSummary(MonitoringScope::teaching($this->alpha->fresh()), 5));
    }

    // ------------------------------------------------------------------
    // MonitoredCandidate and SubjectConcerns
    // ------------------------------------------------------------------

    public function test_monitored_candidate_helpers_describe_the_scoped_subjects(): void
    {
        $this->buildMonitoringScenario();
        $admin = $this->evaluateFor($this->academicAdmin);

        $a1 = $this->entryFor($admin, $this->candidateInA);
        $this->assertSame('failing', $a1->standingKey());
        $this->assertSame(AcademicStanding::Failing->severity(), $a1->severity());
        $this->assertSame(70.0, $a1->lowestGrade());
        $this->assertSame($this->offeringA2->id, $a1->lowest()['offering']->id);
        $this->assertSame(0, $a1->missingScores());
        $this->assertSame([$this->offeringA2->id], $this->concernIdsOf($a1));

        $a2 = $this->entryFor($admin, $this->secondInA);
        $this->assertSame(1, $a2->missingScores());
        $this->assertSame([$this->offeringA1->id], $this->concernIdsOf($a2));

        $a5 = $this->entryFor($admin, $this->candidateA5);
        $this->assertSame('incomplete', $a5->standingKey());
        $this->assertNull($a5->lowest());
        $this->assertNull($a5->lowestGrade());
        $this->assertSame(3, $a5->missingScores());
        // Both Incomplete without a grade: subject name order.
        $this->assertSame([$this->offeringA1->id, $this->offeringA2->id], $this->concernIdsOf($a5));

        $this->assertSame([], $this->entryFor($admin, $this->candidateA6)->concerns());

        $b1 = $this->entryFor($admin, $this->candidateInB);
        $this->assertSame('none', $b1->standingKey());
        // No standing ranks below Passing.
        $this->assertLessThan(AcademicStanding::Passing->severity(), $b1->severity());
        $this->assertNull($b1->lowestGrade());
        $this->assertSame(0, $b1->missingScores());
        $this->assertSame([], $b1->concerns());
    }

    public function test_subject_concerns_include_warnings_and_missing_scores_but_not_clean_results(): void
    {
        $thresholds = new GradingThresholds(7500, 8000);
        $offerings = $this->batchAOfferings(6);

        $subjects = [
            $this->subjectResult($offerings['Subject 3'], 90.0, 0, $thresholds),  // Passing
            $this->subjectResult($offerings['Subject 1'], 70.0, 0, $thresholds),  // Failing
            $this->subjectResult($offerings['Subject 5'], null, 0, $thresholds),  // nothing to judge yet
            $this->subjectResult($offerings['Subject 2'], 77.0, 1, $thresholds),  // At Risk, one missing score
            $this->subjectResult($offerings['Subject 4'], 90.0, 2, $thresholds),  // Incomplete (Passing withheld)
            $this->subjectResult($offerings['Subject 6'], 88.0, 1, null),         // no thresholds, one missing score
        ];

        $concerns = SubjectConcerns::of($subjects);

        $this->assertSame(
            [$offerings['Subject 1']->id, $offerings['Subject 2']->id, $offerings['Subject 4']->id, $offerings['Subject 6']->id],
            array_map(fn (array $concern): int => $concern['offering']->id, $concerns),
        );
        $this->assertSame(['failing', 'at_risk', 'incomplete', null], array_map(fn (array $concern): ?string => $concern['grade']->standing?->value, $concerns));
        // The calculated results are passed through, never recalculated.
        $this->assertSame($subjects[1]['grade'], $concerns[0]['grade']);

        $this->assertSame([], SubjectConcerns::of([$subjects[0], $subjects[2]]));
        $this->assertSame([], SubjectConcerns::of([]));
    }

    public function test_subject_concerns_are_ordered_by_severity_then_lowest_grade_then_subject_name(): void
    {
        $thresholds = new GradingThresholds(7500, 8000);
        $offerings = $this->batchAOfferings(7);

        $concerns = SubjectConcerns::of([
            $this->subjectResult($offerings['Subject 7'], 50.0, 1, null),         // missing scores only
            $this->subjectResult($offerings['Subject 5'], null, 1, $thresholds),  // Incomplete, no grade
            $this->subjectResult($offerings['Subject 2'], 70.0, 0, $thresholds),  // Failing 70.00
            $this->subjectResult($offerings['Subject 4'], 76.0, 0, $thresholds),  // At Risk
            $this->subjectResult($offerings['Subject 6'], 85.0, 1, $thresholds),  // Incomplete 85.00
            $this->subjectResult($offerings['Subject 1'], 70.0, 0, $thresholds),  // Failing 70.00
            $this->subjectResult($offerings['Subject 3'], 60.0, 1, $thresholds),  // Failing 60.00
        ]);

        $this->assertSame(
            ['Subject 3', 'Subject 1', 'Subject 2', 'Subject 4', 'Subject 6', 'Subject 5', 'Subject 7'],
            array_map(fn (array $concern): string => $concern['offering']->subject->name, $concerns),
        );
    }

    public function test_subject_concern_payload_contains_only_summary_fields(): void
    {
        $thresholds = new GradingThresholds(7500, 8000);
        $offerings = $this->batchAOfferings(2);

        $this->assertSame([
            'classSubjectId' => $offerings['Subject 2']->id,
            'subject' => 'Subject 2',
            'grade' => 77.0,
            'standing' => ['value' => 'at_risk', 'label' => 'At Risk', 'tone' => 'warning'],
            'missingScores' => 1,
            'isProvisional' => true,
        ], SubjectConcerns::present($this->subjectResult($offerings['Subject 2'], 77.0, 1, $thresholds, pendingCategories: 1)));

        $this->assertSame([
            'classSubjectId' => $offerings['Subject 1']->id,
            'subject' => 'Subject 1',
            'grade' => null,
            'standing' => null,
            'missingScores' => 2,
            'isProvisional' => false,
        ], SubjectConcerns::present($this->subjectResult($offerings['Subject 1'], null, 2, null, pendingCategories: 1)));
    }

    public function test_monitoring_concerns_and_overall_standing_agree_with_the_profile_calculation(): void
    {
        $this->buildMonitoringScenario();
        $record = $this->app->make(CandidateAcademicRecord::class);

        foreach (['administrator' => $this->academicAdmin, 'Alpha' => $this->alpha, 'Bravo' => $this->bravo] as $label => $viewer) {
            foreach ($this->evaluateFor($viewer) as $entry) {
                $message = "{$label}, candidate {$entry->candidate->last_name}";
                // The candidate profile calculates with forCandidate() over the same subjects.
                $byCandidate = $this->calculator()->forCandidate($entry->candidate->fresh(), $this->subjectIdsOf($entry));
                $profileSubjects = array_map(
                    fn (array $subject): array => ['offering' => $subject['offering'], 'grade' => $byCandidate[$subject['offering']->id]],
                    $entry->subjects,
                );

                $this->assertSame(
                    array_map(SubjectConcerns::present(...), $entry->concerns()),
                    $record->warnings($profileSubjects),
                    $message,
                );
                $this->assertSame($this->calculator()->overallStanding($byCandidate)->toArray(), $entry->overall->toArray(), $message);
            }
        }
    }

    // ------------------------------------------------------------------
    // GradeCalculationService::forClasses()
    // ------------------------------------------------------------------

    public function test_for_classes_agrees_with_for_offering_and_for_candidate_at_every_boundary(): void
    {
        $this->setThresholds($this->activePeriod, '75', '80');
        $this->setThresholds($this->pastPeriod, '62.5', '65');
        $subject1 = Subject::query()->where('code', 'SUBJ-1')->sole();

        $atPassing = $this->candidateInA;
        $belowPassing = $this->secondInA;
        $atWarning = $this->candidateIn($this->batchA, 'A4');
        $belowWarning = $this->candidateIn($this->batchA, 'A5');
        $missingExam = $this->candidateIn($this->batchA, 'A6');
        $explicitZero = $this->candidateIn($this->batchA, 'A7');
        $blankScore = $this->candidateIn($this->batchA, 'A8');
        $leaver = $this->candidateIn($this->batchA, 'A9');
        $mover = $this->candidateIn($this->batchA, 'A10');
        $failingInB = $this->candidateIn($this->batchB, 'B2');
        Candidate::factory()->create(['last_name' => 'Unassigned']);

        // Batch A, Subject 1: Quizzes 40%, Examinations 60%.
        $quiz = $this->createAssessment($this->quizzes, 'Quiz 1', '20');
        $this->recordScores($quiz, [
            $atPassing->id => '15', $belowPassing->id => '15', $atWarning->id => '16', $belowWarning->id => '16',
            $missingExam->id => '18', $explicitZero->id => '0', $leaver->id => '20', $mover->id => '10',
        ]);
        $this->recordBlankScore($quiz, $blankScore, 'Absent with permission.');
        $this->finalize($quiz);
        $this->finalizedAssessment($this->examinations, 'Midterm Examination', '100', [
            $atPassing->id => '75', $belowPassing->id => '74.98', $atWarning->id => '80', $belowWarning->id => '79.98',
            $explicitZero->id => '50', $blankScore->id => '90', $leaver->id => '100', $mover->id => '60',
        ]);
        // A draft never counts.
        $draft = $this->createAssessment($this->examinations, 'Final Examination', '100');
        $this->recordScores($draft, [$atPassing->id => '0', $belowPassing->id => '100']);

        // Batch A, Subject 2: only quizzes assessed so far (provisional grades).
        $this->setScheme($this->offeringA2, ['Quizzes' => '50', 'Examinations' => '50']);
        $this->finalizedAssessment($this->category($this->offeringA2, 'Quizzes'), 'Subject 2 Quiz', '20', [
            $atPassing->id => '18', $belowPassing->id => '14',
        ], $this->bravo);

        // Batch A, Subject 3: grading not set up.
        $offeringA3 = $this->offering($this->batchA, Subject::factory()->create(['code' => 'SUBJ-3', 'name' => 'Subject 3']));

        // Batch B, Subject 1.
        $this->setScheme($this->offeringB1, ['Quizzes' => '100']);
        $this->finalizedAssessment($this->category($this->offeringB1, 'Quizzes'), 'Batch B Quiz', '10', [
            $this->candidateInB->id => '7.7', $failingInB->id => '7',
        ], $this->bravo);

        // Batch C: its only candidate withdrew after a finalized assessment.
        // Batch D: no candidates at all.
        $batchC = ClassBatch::factory()->for($this->activePeriod)->create(['name' => 'Sample Batch C']);
        $offeringC1 = $this->offering($batchC, $subject1);
        $leftC = $this->candidateIn($batchC, 'C1');
        $this->setScheme($offeringC1, ['Quizzes' => '100']);
        $this->finalizedAssessment($this->category($offeringC1, 'Quizzes'), 'Batch C Quiz', '10', [$leftC->id => '4']);
        $batchD = ClassBatch::factory()->for($this->activePeriod)->create(['name' => 'Sample Batch D']);
        $offeringD1 = $this->offering($batchD, $subject1);
        $this->setScheme($offeringD1, ['Quizzes' => '100']);

        // Past period (62.50 / 65.00).
        $oldOffering = $this->oldOffering();
        $old1 = $this->oldCandidate();
        $old2 = $this->candidateIn($this->batchOld, 'Old2', CandidateStatus::Completed);
        $old3 = $this->candidateIn($this->batchOld, 'Old3', CandidateStatus::Completed);
        $this->setScheme($oldOffering, ['Quizzes' => '100']);
        $this->finalizedAssessment($this->category($oldOffering, 'Quizzes'), 'Old Quiz 1', '20', [
            $old1->id => '14', $old2->id => '12.5', $old3->id => '12.49',
        ]);

        $leaver->forceFill(['status' => CandidateStatus::Withdrawn->value])->save();
        $leftC->forceFill(['status' => CandidateStatus::Withdrawn->value])->save();
        $mover->forceFill(['class_batch_id' => $this->batchB->id])->save();
        $this->assertTrue(AssessmentScore::query()->where('assessment_id', $quiz->id)->where('candidate_id', $blankScore->id)->whereNull('score')->exists());

        $offerings = ClassSubject::query()->orderBy('id')->get();
        $candidates = Candidate::query()->orderBy('id')->get();
        $grades = $this->calculator()->forClasses($offerings, $candidates);

        foreach ($candidates as $candidate) {
            $label = "Candidate {$candidate->last_name}";
            $own = $candidate->class_batch_id === null
                ? new Collection
                : $offerings->where('class_batch_id', $candidate->class_batch_id)->values();

            $this->assertEqualsCanonicalizing($own->modelKeys(), array_keys($grades[$candidate->id] ?? []), "{$label}: only the subjects of their own class");
            if ($own->isEmpty()) {
                continue;
            }

            $byCandidate = $this->calculator()->forCandidate($candidate->fresh(), $own->modelKeys());
            foreach ($own as $offering) {
                $classmates = Candidate::query()->where('class_batch_id', $offering->class_batch_id)->pluck('id')->all();
                $byOffering = $this->calculator()->forOffering(ClassSubject::query()->findOrFail($offering->id), $classmates);
                $actual = $grades[$candidate->id][$offering->id]->toArray();

                $this->assertSame($byOffering[$candidate->id]->toArray(), $actual, "{$label}, class subject {$offering->id}: forOffering()");
                $this->assertSame($byCandidate[$offering->id]->toArray(), $actual, "{$label}, class subject {$offering->id}: forCandidate()");
            }
        }

        // The agreement is not vacuous: the expected results.
        $expected = [
            // candidate, class subject, grade, standing, missing scores
            [$atPassing, $this->offeringA1, 75.0, 'at_risk', 0],
            [$belowPassing, $this->offeringA1, 74.99, 'failing', 0],          // 74.988
            [$atWarning, $this->offeringA1, 80.0, 'passing', 0],
            [$belowWarning, $this->offeringA1, 79.99, 'at_risk', 0],          // 79.988
            [$missingExam, $this->offeringA1, 90.0, 'incomplete', 1],
            [$explicitZero, $this->offeringA1, 30.0, 'failing', 0],           // a recorded 0 counts
            [$blankScore, $this->offeringA1, 90.0, 'incomplete', 1],          // a row without a score is missing
            [$leaver, $this->offeringA1, 100.0, null, 0],                     // withdrawn: grade kept, no standing
            [$this->withdrawnInA, $this->offeringA1, null, null, 2],
            [$atPassing, $this->offeringA2, 90.0, 'passing', 0],              // provisional
            [$belowPassing, $this->offeringA2, 70.0, 'failing', 0],
            [$atWarning, $this->offeringA2, null, 'incomplete', 1],
            [$atPassing, $offeringA3, null, null, 0],
            [$this->candidateInB, $this->offeringB1, 77.0, 'at_risk', 0],
            [$failingInB, $this->offeringB1, 70.0, 'failing', 0],
            [$mover, $this->offeringB1, null, 'incomplete', 1],
            [$leftC, $offeringC1, 40.0, null, 0],
            [$old1, $oldOffering, 70.0, 'passing', 0],                        // same 70.00 as B2, other thresholds
            [$old2, $oldOffering, 62.5, 'at_risk', 0],
            [$old3, $oldOffering, 62.45, 'failing', 0],
        ];
        foreach ($expected as [$candidate, $offering, $grade, $standing, $missing]) {
            $result = $grades[$candidate->id][$offering->id];
            $label = "Candidate {$candidate->last_name}, class subject {$offering->id}";
            $this->assertSame($grade, $result->grade, $label);
            $this->assertSame($standing, $result->standing?->value, $label);
            $this->assertSame($missing, $result->missingScores, $label);
        }
        $this->assertTrue($grades[$atPassing->id][$this->offeringA2->id]->isProvisional());
        $this->assertSame('not_configured', $grades[$atPassing->id][$offeringA3->id]->status()->value);

        // Monitoring uses exactly these results for the gradable population.
        $monitored = $this->evaluateFor($this->academicAdmin);
        $this->assertEqualsCanonicalizing(
            array_map(fn (Candidate $candidate): int => $candidate->id, [
                $atPassing, $belowPassing, $atWarning, $belowWarning, $missingExam, $explicitZero, $blankScore,
                $this->candidateInB, $failingInB, $mover,
            ]),
            $this->idsOf($monitored),
        );
        foreach ($monitored as $entry) {
            foreach ($entry->subjects as $subject) {
                $this->assertSame($grades[$entry->candidate->id][$subject['offering']->id]->toArray(), $subject['grade']->toArray());
            }
        }
        $this->assertCountsReconcile($this->monitoring()->counts($monitored));
        $this->assertSame(
            ['standing' => ['value' => 'at_risk', 'label' => 'At Risk', 'tone' => 'warning'], 'basedOnSubjects' => 2, 'totalSubjects' => 3, 'isProvisional' => true],
            $this->entryFor($monitored, $atPassing)->overall->toArray(),
        );
    }

    public function test_for_classes_calculates_each_candidate_only_in_the_subjects_of_their_own_class(): void
    {
        $this->setThresholds($this->activePeriod, '75', '80');
        $this->finalizedAssessment($this->quizzes, 'Quiz 1', '20', [$this->candidateInA->id => '10', $this->secondInA->id => '18']);
        $this->setScheme($this->offeringB1, ['Quizzes' => '100']);
        $this->finalizedAssessment($this->category($this->offeringB1, 'Quizzes'), 'Batch B Quiz', '10', [$this->candidateInB->id => '9'], $this->bravo);
        // Candidate A1 moves to Batch B after scoring 50.00 in Batch A.
        $this->candidateInA->forceFill(['class_batch_id' => $this->batchB->id])->save();

        $offerings = ClassSubject::query()->whereKey([$this->offeringA1->id, $this->offeringA2->id, $this->offeringB1->id])->get();
        $candidates = Candidate::query()->whereKey([$this->candidateInA->id, $this->secondInA->id, $this->candidateInB->id])->get();
        $grades = $this->calculator()->forClasses($offerings, $candidates);

        $this->assertSame([$this->offeringB1->id], array_keys($grades[$this->candidateInA->id]));
        $this->assertNull($grades[$this->candidateInA->id][$this->offeringB1->id]->grade);
        $this->assertSame(AcademicStanding::Incomplete, $grades[$this->candidateInA->id][$this->offeringB1->id]->standing);
        $this->assertEqualsCanonicalizing([$this->offeringA1->id, $this->offeringA2->id], array_keys($grades[$this->secondInA->id]));
        $this->assertSame(AcademicStanding::Passing, $grades[$this->secondInA->id][$this->offeringA1->id]->standing);
        $this->assertSame([$this->offeringB1->id], array_keys($grades[$this->candidateInB->id]));
        $this->assertSame(AcademicStanding::Passing, $grades[$this->candidateInB->id][$this->offeringB1->id]->standing);

        // Only Batch A's subjects: Batch B candidates are not calculated at all.
        $onlyBatchA = $this->calculator()->forClasses(ClassSubject::query()->whereKey([$this->offeringA1->id])->get(), $candidates);
        $this->assertSame([], $onlyBatchA[$this->candidateInA->id] ?? []);
        $this->assertSame([], $onlyBatchA[$this->candidateInB->id] ?? []);
        $this->assertSame([$this->offeringA1->id], array_keys($onlyBatchA[$this->secondInA->id]));
    }

    public function test_for_classes_with_no_class_subjects_or_no_candidates_returns_nothing(): void
    {
        $this->buildMonitoringScenario();
        $offerings = ClassSubject::query()->get();
        $candidates = Candidate::query()->get();

        $results = [];
        $queries = $this->recordQueries(function () use ($offerings, $candidates, &$results): void {
            $results[] = $this->calculator()->forClasses(new Collection, $candidates);
            $results[] = $this->calculator()->forClasses($offerings, new Collection);
        });

        $this->assertSame([[], []], $results);
        $this->assertSame([], $queries);
    }

    // ------------------------------------------------------------------
    // Query budgets
    // ------------------------------------------------------------------

    public function test_for_classes_uses_the_same_queries_however_many_candidates_and_classes(): void
    {
        $this->buildMonitoringScenario();

        $measure = function (): int {
            $offerings = ClassSubject::query()->get();
            $candidates = Candidate::query()->get();
            $grades = [];
            $queries = $this->recordQueries(function () use ($offerings, $candidates, &$grades): void {
                $grades = $this->calculator()->forClasses($offerings, $candidates);
            });
            $this->assertNotSame([], $grades);

            return count($queries);
        };

        $before = $measure();
        $this->gradedClass('Sample Batch Q1', 20);
        $this->gradedClass('Sample Batch Q2', 20);
        Candidate::factory()->count(10)->create(['class_batch_id' => $this->batchA->id]);
        $after = $measure();

        $this->assertSame($before, $after);
        // Scheme, assessments, and scores, plus the classes and periods of the class subjects.
        $this->assertLessThanOrEqual(8, $after);
    }

    public function test_evaluate_uses_the_same_queries_however_many_candidates_and_classes(): void
    {
        $this->buildMonitoringScenario();
        $adminScope = MonitoringScope::for($this->academicAdmin->fresh());
        $alphaScope = MonitoringScope::for($this->alpha->fresh());

        $measure = function (MonitoringScope $scope): array {
            $monitored = [];
            $queries = $this->recordQueries(function () use ($scope, &$monitored): void {
                $monitored = $this->monitoring()->evaluate($scope->offerings($this->activePeriod->id)->get());
            });

            return [count($queries), count($monitored)];
        };

        [$adminBefore, $adminPopulationBefore] = $measure($adminScope);
        [$alphaBefore, $alphaPopulationBefore] = $measure($alphaScope);
        $this->gradedClass('Sample Batch Q1', 20);
        $this->gradedClass('Sample Batch Q2', 20);
        Candidate::factory()->count(15)->create(['class_batch_id' => $this->batchA->id]);
        [$adminAfter, $adminPopulationAfter] = $measure($adminScope);
        [$alphaAfter, $alphaPopulationAfter] = $measure($alphaScope);

        $this->assertSame([6, 61], [$adminPopulationBefore, $adminPopulationAfter]);
        $this->assertSame([5, 60], [$alphaPopulationBefore, $alphaPopulationAfter]);
        $this->assertSame($adminBefore, $adminAfter);
        $this->assertSame($alphaBefore, $alphaAfter);
    }

    public function test_active_period_summary_uses_the_same_queries_however_many_candidates(): void
    {
        $this->buildMonitoringScenario();
        $adminScope = MonitoringScope::for($this->academicAdmin->fresh());
        $alphaScope = MonitoringScope::teaching($this->alpha->fresh());

        $measure = fn (MonitoringScope $scope): int => count($this->recordQueries(function () use ($scope): void {
            $this->assertNotNull($this->monitoring()->activePeriodSummary($scope, 5));
        }));

        $adminBefore = $measure($adminScope);
        $alphaBefore = $measure($alphaScope);
        $this->gradedClass('Sample Batch Q1', 25);
        Candidate::factory()->count(15)->create(['class_batch_id' => $this->batchA->id]);

        $this->assertSame($adminBefore, $measure($adminScope));
        $this->assertSame($alphaBefore, $measure($alphaScope));
    }

    public function test_counts_filters_sorts_and_attention_lists_run_without_queries(): void
    {
        $this->buildMonitoringScenario();
        $monitored = $this->evaluateFor($this->academicAdmin);

        $queries = $this->recordQueries(function () use ($monitored): void {
            $monitoring = $this->monitoring();
            $monitoring->counts($monitored);
            $monitoring->filter($monitored, 'failing', null);
            foreach (['', 'lowest', 'highest', 'name'] as $sort) {
                $monitoring->sort($monitored, $sort);
            }
            $monitoring->requiringAttention($monitored, 5);
            $monitoring->subjectsRequiringAttention($monitored);
            foreach ($monitored as $entry) {
                $entry->lowest();
                $entry->missingScores();
                array_map(SubjectConcerns::present(...), $entry->concerns());
            }
        });

        $this->assertSame([], $queries);
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /**
     * Passing 75 / warning 80 in the active period (unless disabled):
     *
     *                  Subject 1 (Alpha)           Subject 2 (Bravo)      Overall (all subjects)
     *   A1             80.00 Passing               70.00 Failing          Failing, lowest 70.00
     *   A2             60.00 Failing, 1 missing    90.00 Passing          Failing, lowest 60.00
     *   A3 (withdrawn) -                           -                      not monitored
     *   A4             75.00 At Risk               85.00 Passing          At Risk, lowest 75.00
     *   A5             no grade, 2 missing         no grade, 1 missing    Incomplete, no grade
     *   A6 (on leave)  80.00 Passing               95.00 Passing          Passing, lowest 80.00
     *   B1             Batch B Subject 1 (Bravo): grading not set up      no standing yet
     */
    private function buildMonitoringScenario(bool $withThresholds = true): void
    {
        if ($withThresholds) {
            $this->setThresholds($this->activePeriod, '75', '80');
        }

        $this->candidateA4 = $this->candidateIn($this->batchA, 'A4');
        $this->candidateA5 = $this->candidateIn($this->batchA, 'A5');
        $this->candidateA6 = $this->candidateIn($this->batchA, 'A6');

        $this->finalizedAssessment($this->quizzes, 'Quiz 1', '20', [
            $this->candidateInA->id => '16', $this->secondInA->id => '12', $this->candidateA4->id => '15', $this->candidateA6->id => '16',
        ]);
        $this->finalizedAssessment($this->examinations, 'Midterm Examination', '100', [
            $this->candidateInA->id => '80', $this->candidateA4->id => '75', $this->candidateA6->id => '80',
        ]);

        $this->setScheme($this->offeringA2, ['Assessments' => '100']);
        $this->finalizedAssessment($this->category($this->offeringA2, 'Assessments'), 'Subject 2 Test', '100', [
            $this->candidateInA->id => '70', $this->secondInA->id => '90', $this->candidateA4->id => '85', $this->candidateA6->id => '95',
        ], $this->bravo);

        $this->candidateA6->forceFill(['status' => CandidateStatus::OnLeave->value])->save();
    }

    /**
     * A class of the active period with the given number of candidates, one
     * subject taught by Alpha (Quizzes 40%, Examinations 60%), a finalized
     * quiz with scores spread from 50% to 100%, and a finalized examination
     * missing the first candidate's score.
     */
    private function gradedClass(string $name, int $candidateCount): ClassSubject
    {
        $classBatch = ClassBatch::factory()->for($this->activePeriod)->create(['name' => $name]);
        $offering = $this->offering($classBatch, Subject::query()->where('code', 'SUBJ-1')->sole());
        $this->teach($this->alpha, $offering);
        $ids = Candidate::factory()->count($candidateCount)->create(['class_batch_id' => $classBatch->id])->modelKeys();

        $quizScores = [];
        foreach ($ids as $index => $id) {
            $quizScores[$id] = (string) (10 + $index % 11);
        }

        $this->setScheme($offering, ['Quizzes' => '40', 'Examinations' => '60']);
        $this->finalizedAssessment($this->category($offering, 'Quizzes'), 'Quiz 1', '20', $quizScores);
        $this->finalizedAssessment($this->category($offering, 'Examinations'), 'Midterm Examination', '100', array_fill_keys(array_slice($ids, 1), '78'));

        return $offering;
    }

    private function monitoring(): AcademicMonitoring
    {
        return $this->app->make(AcademicMonitoring::class);
    }

    private function calculator(): GradeCalculationService
    {
        return $this->app->make(GradeCalculationService::class);
    }

    /**
     * @return list<MonitoredCandidate>
     */
    private function evaluateFor(User $viewer, ?AcademicPeriod $period = null): array
    {
        $scope = MonitoringScope::for($viewer->fresh());

        return $this->monitoring()->evaluate($scope->offerings(($period ?? $this->activePeriod)->id)->get());
    }

    /**
     * @param  list<MonitoredCandidate>  $monitored
     * @return list<int>
     */
    private function idsOf(array $monitored): array
    {
        return array_map(fn (MonitoredCandidate $entry): int => $entry->candidate->id, $monitored);
    }

    /**
     * @param  list<MonitoredCandidate>  $monitored
     * @return array<int, string> candidate id => standing key, in list order
     */
    private function standingsOf(array $monitored): array
    {
        $standings = [];
        foreach ($monitored as $entry) {
            $standings[$entry->candidate->id] = $entry->standingKey();
        }

        return $standings;
    }

    /**
     * @param  list<MonitoredCandidate>  $monitored
     */
    private function entryFor(array $monitored, Candidate $candidate): MonitoredCandidate
    {
        foreach ($monitored as $entry) {
            if ($entry->candidate->id === $candidate->id) {
                return $entry;
            }
        }

        $this->fail("Candidate {$candidate->last_name} is not monitored.");
    }

    /**
     * @return list<int>
     */
    private function subjectIdsOf(MonitoredCandidate $entry): array
    {
        return array_map(fn (array $subject): int => $subject['offering']->id, $entry->subjects);
    }

    /**
     * @return list<int>
     */
    private function concernIdsOf(MonitoredCandidate $entry): array
    {
        return array_map(fn (array $concern): int => $concern['offering']->id, $entry->concerns());
    }

    /**
     * @return list<int>
     */
    private function scopedOfferingIds(MonitoringScope $scope, AcademicPeriod $period): array
    {
        return $this->sortedIds($scope->offerings($period->id)->get()->modelKeys());
    }

    /**
     * @param  list<int>  $ids
     * @return list<int>
     */
    private function sortedIds(array $ids): array
    {
        $ids = array_map(intval(...), $ids);
        sort($ids);

        return $ids;
    }

    /**
     * @param  array<string, int>  $counts
     */
    private function assertCountsReconcile(array $counts, string $message = ''): void
    {
        $this->assertSame(
            $counts['monitored'],
            $counts['failing'] + $counts['atRisk'] + $counts['incomplete'] + $counts['passing'] + $counts['noStanding'],
            $message,
        );
    }

    /**
     * Batch A class subjects of Subject 1 to Subject {$count}, keyed by
     * subject name, with the subject loaded.
     *
     * @return array<string, ClassSubject>
     */
    private function batchAOfferings(int $count): array
    {
        $offerings = [];
        for ($number = 1; $number <= $count; $number++) {
            $subject = Subject::query()->where('code', "SUBJ-{$number}")->first()
                ?? Subject::factory()->create(['code' => "SUBJ-{$number}", 'name' => "Subject {$number}"]);
            $offerings["Subject {$number}"] = $this->offering($this->batchA, $subject)->load('subject');
        }

        return $offerings;
    }

    /**
     * One subject result with the standing decided by GradeCalculationService.
     *
     * @return array{offering: ClassSubject, grade: SubjectGrade}
     */
    private function subjectResult(ClassSubject $offering, ?float $grade, int $missingScores, ?GradingThresholds $thresholds, int $pendingCategories = 0): array
    {
        $standing = $thresholds === null ? null : $this->calculator()->standing($grade, $missingScores, $thresholds);

        return [
            'offering' => $offering,
            'grade' => new SubjectGrade([], $grade, $grade === null ? 0.0 : 100.0, $missingScores, $pendingCategories, $standing),
        ];
    }

    private function candidateIn(ClassBatch $classBatch, string $lastName, CandidateStatus $status = CandidateStatus::Enrolled): Candidate
    {
        return Candidate::factory()->create([
            'class_batch_id' => $classBatch->id,
            'last_name' => $lastName,
            'status' => $status->value,
        ]);
    }

    /**
     * @param  list<PermissionCode>  $permissions
     */
    private function roleWith(string $code, string $name, array $permissions): Role
    {
        $role = Role::query()->create(['code' => $code, 'name' => $name]);
        $role->permissions()->sync(Permission::query()
            ->whereIn('code', array_map(fn (PermissionCode $permission): string => $permission->value, $permissions))
            ->pluck('id'));

        return $role;
    }

    private function setThresholds(AcademicPeriod $period, string $passing, string $warning): void
    {
        $this->app->make(GradingThresholdService::class)->save($period, $passing, $warning, self::THRESHOLD_REASON);
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

    /**
     * Records a draft entry with a comment but no score (a stored row whose
     * score is null).
     */
    private function recordBlankScore(Assessment $assessment, Candidate $candidate, string $comment): void
    {
        $this->app->make(ScoreRecordingService::class)->recordDraftScores($assessment, [
            $candidate->id => ['score' => null, 'comment' => $comment, 'expected_score' => null, 'expected_comment' => null],
        ], $this->alpha);
    }

    private function category(ClassSubject $offering, string $name): AssessmentCategory
    {
        return $offering->assessmentCategories()->where('name', $name)->sole();
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

    /**
     * @return list<string> the SQL of every query the callback ran
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
}
