<?php

namespace Tests\Feature\Monitoring;

use App\Enums\CandidateStatus;
use App\Enums\Permission as PermissionCode;
use App\Enums\SystemRole;
use App\Models\AcademicPeriod;
use App\Models\Candidate;
use App\Models\ClassBatch;
use App\Models\ClassSubject;
use App\Models\InstructorAssignment;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Subject;
use App\Models\User;
use App\Services\Grading\GradingThresholdService;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Grading\BuildsGradingFixtures;
use Tests\TestCase;

/**
 * Independent tests of the academic monitoring page (GET /monitoring,
 * Milestone 6): authorization, scope and tampering, counts, filters, sorts,
 * overall and subject views, pagination, periods without thresholds, and
 * withdrawn candidates.
 *
 * Expectations come from the design (SESSION_HANDOFF.md "Next Recommended
 * Task" 1-7) and AGENTS.md, not from the implementation.
 *
 * Fixture, on top of BuildsGradingFixtures, with passing 75 / warning 80 in
 * the active period "Period Current":
 *
 *   Sample Batch A
 *     Subject 1 (Instructor Alpha)  Quiz 1 /10 finalized
 *     Subject 2 (Instructor Bravo)  Subject 2 Exam /100 finalized
 *       A1  Candidate A1              S1  9 -> 90 Passing   S2 70 Failing   overall Failing, lowest 70 (Subject 2)
 *       A2  Candidate A2              S1  6 -> 60 Failing   S2 missing      overall Failing, lowest 60 (Subject 1)
 *       A3  Candidate A3 (withdrawn)  S1  1 -> 10           S2 20           never monitored
 *       A4  Candidate A4 (On Leave)   S1  8 -> 80 Passing   S2 85 Passing   overall Passing, lowest 80
 *       A5  Candidate A5              S1 7.8 -> 78 At Risk  S2 90 Passing   overall At Risk, lowest 78
 *   Sample Batch B
 *     Subject 1 (Instructor Bravo)  Batch B Quiz /10 finalized
 *       B1  Candidate B1              7.7 -> 77 At Risk
 *       B2  Candidate B2              missing -> Incomplete, no grade
 *   Sample Batch C
 *     Subject 3 (nobody)            no grading set up
 *       C01 Candidate C01             no standing yet
 *
 *   Administrator counts: 7 monitored = 2 Failing + 2 At Risk + 1 Incomplete + 1 Passing + 1 No Standing Yet.
 *
 * "Period Past" (no thresholds): Sample Batch Old, Subject 1 (Alpha).
 * "Period Other" (no thresholds): Sample Batch Other, Subject 1 (Instructor Charlie only).
 */
class MonitoringPageTest extends TestCase
{
    use BuildsGradingFixtures;

    private const EXPECTED_ADMIN_COUNTS = ['monitored' => 7, 'failing' => 2, 'atRisk' => 2, 'incomplete' => 1, 'passing' => 1, 'noStanding' => 1];

    /** Alpha teaches only Subject 1 of Batch A: A1 90, A2 60, A4 80, A5 78. */
    private const EXPECTED_ALPHA_COUNTS = ['monitored' => 4, 'failing' => 1, 'atRisk' => 1, 'incomplete' => 0, 'passing' => 2, 'noStanding' => 0];

    /** Bravo teaches Subject 2 of Batch A and Subject 1 of Batch B. */
    private const EXPECTED_BRAVO_COUNTS = ['monitored' => 6, 'failing' => 1, 'atRisk' => 1, 'incomplete' => 2, 'passing' => 2, 'noStanding' => 0];

    private const EMPTY_COUNTS = ['monitored' => 0, 'failing' => 0, 'atRisk' => 0, 'incomplete' => 0, 'passing' => 0, 'noStanding' => 0];

    /** Standing filter value => counts key. */
    private const STANDING_COUNT_KEYS = ['failing' => 'failing', 'at_risk' => 'atRisk', 'incomplete' => 'incomplete', 'passing' => 'passing', 'none' => 'noStanding'];

    private Subject $subject1;

    private Subject $subject2;

    private Subject $subject3;

    private ClassBatch $batchC;

    private ClassSubject $offeringC3;

    private AcademicPeriod $otherPeriod;

    private ClassBatch $batchOther;

    private User $charlie;

    /** A4: On Leave, Passing in both subjects. */
    private Candidate $passingInA;

    /** A5: At Risk in Subject 1, Passing in Subject 2. */
    private Candidate $atRiskInA;

    /** B2: missing the Batch B quiz (Incomplete, no grade). */
    private Candidate $missingInB;

    /** C01: in a class whose subject has no grading yet (no standing). */
    private Candidate $ungradedInC;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildGradingFixtures();
        $this->app->make(GradingThresholdService::class)->save($this->activePeriod, '75', '80', null);

        $this->subject1 = Subject::query()->where('code', 'SUBJ-1')->sole();
        $this->subject2 = Subject::query()->where('code', 'SUBJ-2')->sole();
        $this->subject3 = Subject::factory()->create(['code' => 'SUBJ-3', 'name' => 'Subject 3']);

        $this->passingInA = $this->makeCandidate($this->batchA, 'OCS-0004', 'A4', CandidateStatus::OnLeave);
        $this->atRiskInA = $this->makeCandidate($this->batchA, 'OCS-0005', 'A5');
        $this->missingInB = $this->makeCandidate($this->batchB, 'OCS-0012', 'B2');

        $this->batchC = ClassBatch::factory()->for($this->activePeriod)->create(['name' => 'Sample Batch C']);
        $this->offeringC3 = $this->offering($this->batchC, $this->subject3);
        $this->ungradedInC = $this->makeCandidate($this->batchC, 'OCS-0021', 'C01');

        // A3 had failing scores recorded before withdrawing: they must never
        // count once the candidate is withdrawn.
        $this->withdrawnInA->forceFill(['status' => CandidateStatus::Enrolled->value])->save();

        $quiz = $this->createAssessment($this->quizzes, 'Quiz 1', '10');
        $this->recordScores($quiz, [
            $this->candidateInA->id => '9',
            $this->secondInA->id => '6',
            $this->withdrawnInA->id => '1',
            $this->passingInA->id => '8',
            $this->atRiskInA->id => '7.8',
        ]);
        $this->finalize($quiz);

        $this->setScheme($this->offeringA2, ['Examinations' => '100']);
        $exam = $this->createAssessment($this->offeringA2->assessmentCategories()->sole(), 'Subject 2 Exam', '100', null, $this->bravo);
        $this->recordScores($exam, [
            $this->candidateInA->id => '70',
            $this->withdrawnInA->id => '20',
            $this->passingInA->id => '85',
            $this->atRiskInA->id => '90',
        ], $this->bravo);
        $this->finalize($exam, $this->bravo);

        $this->setScheme($this->offeringB1, ['Quizzes' => '100']);
        $quizB = $this->createAssessment($this->offeringB1->assessmentCategories()->sole(), 'Batch B Quiz', '10', null, $this->bravo);
        $this->recordScores($quizB, [$this->candidateInB->id => '7.7'], $this->bravo);
        $this->finalize($quizB, $this->bravo);

        $this->withdrawnInA->forceFill(['status' => CandidateStatus::Withdrawn->value])->save();

        $this->otherPeriod = AcademicPeriod::factory()->create(['name' => 'Period Other', 'starts_on' => '2025-08-04', 'ends_on' => '2025-12-19']);
        $this->batchOther = ClassBatch::factory()->for($this->otherPeriod)->create(['name' => 'Sample Batch Other']);
        $this->charlie = $this->userWithRole(SystemRole::Instructor, ['name' => 'Instructor Charlie']);
        $this->teach($this->charlie, $this->offering($this->batchOther, $this->subject1));
        $this->makeCandidate($this->batchOther, 'OCS-0031', 'Other1');
    }

    // ------------------------------------------------------------------
    // Authorization
    // ------------------------------------------------------------------

    public function test_guests_are_sent_to_sign_in(): void
    {
        $this->get('/monitoring')->assertRedirect('/login');
        $this->get('/monitoring?class='.$this->batchA->id)->assertRedirect('/login');
    }

    public function test_candidates_cannot_open_monitoring(): void
    {
        $this->actingAs($this->candidateInA->user)->get('/monitoring')->assertForbidden();
        $this->actingAs($this->candidateInA->user)->get('/monitoring?class='.$this->batchA->id)->assertForbidden();
    }

    public function test_staff_without_the_monitoring_permission_are_forbidden(): void
    {
        // Teaching staff with assignments but without academic_monitoring.view.
        $teacher = $this->withCustomRole($this->alpha, 'teaching_only', [
            PermissionCode::AccessStaffArea,
            PermissionCode::TeachClasses,
            PermissionCode::RecordGrades,
        ]);
        $this->actingAs($teacher)->get('/monitoring')->assertForbidden();

        // Staff who can view every candidate but not academic monitoring.
        $clerk = $this->withCustomRole($this->userWithRole(SystemRole::Instructor, ['name' => 'Records Clerk']), 'records_clerk', [
            PermissionCode::AccessStaffArea,
            PermissionCode::ViewAllCandidates,
        ]);
        $this->actingAs($clerk)->get('/monitoring')->assertForbidden();
    }

    public function test_deactivated_instructor_is_signed_out_instead_of_seeing_monitoring(): void
    {
        $this->alpha->forceFill(['is_active' => false])->save();

        $this->actingAs($this->alpha)->get('/monitoring')->assertRedirect('/login');
        $this->assertGuest();
    }

    public function test_deactivated_administrator_is_signed_out_instead_of_seeing_monitoring(): void
    {
        $this->academicAdmin->forceFill(['is_active' => false])->save();

        $this->actingAs($this->academicAdmin)->get('/monitoring')->assertRedirect('/login');
        $this->assertGuest();
    }

    public function test_former_instructor_with_leftover_assignments_sees_nothing_even_when_asking_for_their_classes(): void
    {
        // Alpha keeps the Batch A Subject 1 assignment but no longer holds classes.teach.
        $former = $this->withCustomRole($this->alpha, 'former_instructor', [
            PermissionCode::AccessStaffArea,
            PermissionCode::ViewAcademicMonitoring,
            PermissionCode::RecordGrades,
        ]);
        $this->assertTrue(InstructorAssignment::query()->where('instructor_id', $former->id)->exists());

        foreach ([[], ['period' => $this->activePeriod->id, 'class' => $this->batchA->id, 'subject' => $this->subject1->id]] as $query) {
            $props = $this->monitoringProps($former, $query);

            $this->assertSame('none', $props['scope']);
            $this->assertNull($props['period']);
            $this->assertSame([], $props['periods']);
            $this->assertSame([], $props['classOptions']);
            $this->assertSame([], $props['subjectOptions']);
            $this->assertSame(self::EMPTY_COUNTS, $props['counts']);
            $this->assertSame([], $props['candidates']['data']);
            $this->assertSame(0, $props['candidates']['total']);
            $this->assertSame('', $props['filters']['period']);
            $this->assertSame('', $props['filters']['class']);
            $this->assertSame('', $props['filters']['subject']);
            $this->assertPropsDoNotMention($props, ['Sample Batch A', 'Candidate A1', 'Candidate A2', 'Period Current']);
        }
    }

    public function test_super_and_academic_administrators_monitor_every_class_subject(): void
    {
        $superAdmin = $this->userWithRole(SystemRole::SuperAdministrator, ['name' => 'Super Admin']);

        foreach ([$superAdmin, $this->academicAdmin] as $administrator) {
            $props = $this->monitoringProps($administrator);

            $this->assertSame('all', $props['scope']);
            $this->assertSame($this->activePeriod->id, $props['period']['id']);
            $this->assertSame(self::EXPECTED_ADMIN_COUNTS, $props['counts']);
            $this->assertSame(
                ['Sample Batch A', 'Sample Batch B', 'Sample Batch C'],
                array_column($props['classOptions'], 'name'),
            );
            $this->assertSame(['Subject 1', 'Subject 2', 'Subject 3'], array_column($props['subjectOptions'], 'name'));
            $this->assertSame(
                ['Period Current', 'Period Past', 'Period Other'],
                array_column($props['periods'], 'name'),
            );
        }
    }

    // ------------------------------------------------------------------
    // Scope and tampering
    // ------------------------------------------------------------------

    public function test_instructor_scope_is_limited_to_the_class_subjects_they_teach(): void
    {
        $props = $this->monitoringProps($this->alpha);

        $this->assertSame('taught', $props['scope']);
        $this->assertSame(self::EXPECTED_ALPHA_COUNTS, $props['counts']);
        $this->assertSame([['id' => $this->batchA->id, 'name' => 'Sample Batch A']], $props['classOptions']);
        $this->assertSame([['id' => $this->subject1->id, 'code' => 'SUBJ-1', 'name' => 'Subject 1']], $props['subjectOptions']);
        $this->assertTrue($props['singleClass']);
        $this->assertSame(
            [$this->secondInA->id, $this->atRiskInA->id, $this->passingInA->id, $this->candidateInA->id],
            $this->candidateIds($props),
        );
        // Only Alpha's own class subject can require attention.
        $this->assertSame([$this->offeringA1->id], array_column($props['subjectsRequiringAttention'], 'classSubjectId'));
        $this->assertSame(1, $props['subjectsRequiringAttention'][0]['failing']);
        $this->assertSame(1, $props['subjectsRequiringAttention'][0]['atRisk']);
        $this->assertPropsDoNotMention($props, $this->outsideAlphaScope());

        $bravo = $this->monitoringProps($this->bravo);
        $this->assertSame('taught', $bravo['scope']);
        $this->assertSame(self::EXPECTED_BRAVO_COUNTS, $bravo['counts']);
        $this->assertSame(
            [$this->offeringA2->id, $this->offeringB1->id],
            array_column($bravo['subjectsRequiringAttention'], 'classSubjectId'),
        );
    }

    public function test_instructor_class_filter_outside_their_scope_is_ignored(): void
    {
        foreach ([$this->batchB, $this->batchC, $this->batchOld, $this->batchOther] as $foreignClass) {
            $props = $this->monitoringProps($this->alpha, ['class' => $foreignClass->id]);

            $this->assertSame('', $props['filters']['class'], "Class {$foreignClass->name} must be ignored.");
            $this->assertSame($this->activePeriod->id, $props['period']['id']);
            $this->assertSame(self::EXPECTED_ALPHA_COUNTS, $props['counts']);
            $this->assertSame(4, $props['candidates']['total']);
            $this->assertEveryRowInClass($props, $this->batchA);
            $this->assertPropsDoNotMention($props, $this->outsideAlphaScope());
        }
    }

    public function test_instructor_subject_filter_outside_their_scope_is_ignored(): void
    {
        foreach ([$this->subject2, $this->subject3] as $foreignSubject) {
            $props = $this->monitoringProps($this->alpha, ['subject' => $foreignSubject->id]);

            $this->assertSame('', $props['filters']['subject'], "{$foreignSubject->name} must be ignored.");
            $this->assertSame('overall', $props['view']);
            $this->assertSame(self::EXPECTED_ALPHA_COUNTS, $props['counts']);
            $this->assertPropsDoNotMention($props, $this->outsideAlphaScope());
        }

        // A class Alpha teaches with a subject of that class Alpha does not teach.
        $props = $this->monitoringProps($this->alpha, ['class' => $this->batchA->id, 'subject' => $this->subject2->id]);
        $this->assertSame((string) $this->batchA->id, $props['filters']['class']);
        $this->assertSame('', $props['filters']['subject']);
        $this->assertSame(self::EXPECTED_ALPHA_COUNTS, $props['counts']);
        $this->assertPropsDoNotMention($props, ['Subject 2']);
    }

    public function test_instructor_subject_filter_never_reaches_the_same_subject_in_another_instructors_class(): void
    {
        // Subject 1 is also taught in Batch B, by Bravo.
        $props = $this->monitoringProps($this->alpha, ['subject' => $this->subject1->id]);

        $this->assertSame((string) $this->subject1->id, $props['filters']['subject']);
        $this->assertSame('subject', $props['view']);
        $this->assertSame(self::EXPECTED_ALPHA_COUNTS, $props['counts']);
        $this->assertEveryRowInClass($props, $this->batchA);
        foreach ($props['candidates']['data'] as $row) {
            $this->assertSame($this->offeringA1->id, $row['subjectResult']['classSubjectId']);
        }
        $this->assertPropsDoNotMention($props, $this->outsideAlphaScope());
    }

    public function test_instructor_cannot_select_a_period_they_do_not_teach_in(): void
    {
        foreach ([$this->otherPeriod->id, 999999, 'abc', '-3', '0'] as $period) {
            $props = $this->monitoringProps($this->alpha, ['period' => $period]);

            $this->assertSame($this->activePeriod->id, $props['period']['id'], "Period [{$period}] must fall back to the active period.");
            $this->assertSame('', $props['filters']['period']);
            $this->assertSame(['Period Current', 'Period Past'], array_column($props['periods'], 'name'));
            $this->assertSame(self::EXPECTED_ALPHA_COUNTS, $props['counts']);
            $this->assertPropsDoNotMention($props, ['Period Other', 'Sample Batch Other', 'Candidate Other1']);
        }

        // A period Alpha taught in can be selected.
        $props = $this->monitoringProps($this->alpha, ['period' => $this->pastPeriod->id]);
        $this->assertSame($this->pastPeriod->id, $props['period']['id']);
        $this->assertSame((string) $this->pastPeriod->id, $props['filters']['period']);
    }

    public function test_instructor_who_teaches_only_outside_the_active_period_starts_in_their_own_period(): void
    {
        // Charlie teaches only in Period Other, which is not active.
        $props = $this->monitoringProps($this->charlie);
        $this->assertSame($this->otherPeriod->id, $props['period']['id']);
        $this->assertSame([['id' => $this->otherPeriod->id, 'name' => 'Period Other', 'isActive' => false]], $props['periods']);
        $this->assertSame(1, $props['counts']['monitored']);

        // Asking for the active period (which Charlie does not teach in) does not widen the scope.
        $props = $this->monitoringProps($this->charlie, ['period' => $this->activePeriod->id, 'class' => $this->batchA->id]);
        $this->assertSame($this->otherPeriod->id, $props['period']['id']);
        $this->assertSame('', $props['filters']['period']);
        $this->assertSame('', $props['filters']['class']);
        $this->assertSame(1, $props['counts']['monitored']);
        $this->assertPropsDoNotMention($props, ['Period Current', 'Sample Batch A', 'Candidate A1']);
    }

    public function test_removing_an_assignment_removes_that_class_subject_from_the_instructors_scope(): void
    {
        InstructorAssignment::query()
            ->where('instructor_id', $this->alpha->id)
            ->where('class_subject_id', $this->offeringA1->id)
            ->delete();

        // Alpha now teaches only in Period Past.
        $props = $this->monitoringProps($this->alpha, ['period' => $this->activePeriod->id]);

        $this->assertSame($this->pastPeriod->id, $props['period']['id']);
        $this->assertSame(['Period Past'], array_column($props['periods'], 'name'));
        $this->assertPropsDoNotMention($props, ['Sample Batch A', 'Candidate A1', 'Candidate A2']);
    }

    public function test_administrator_filters_from_another_period_or_unknown_ids_are_ignored(): void
    {
        $cases = [
            ['period' => 999999],
            ['class' => $this->batchOld->id],
            ['class' => $this->batchOther->id],
            ['class' => 999999],
            ['subject' => 999999],
            ['class' => 'abc', 'subject' => '-1'],
        ];

        foreach ($cases as $query) {
            $props = $this->monitoringProps($this->academicAdmin, $query);
            $label = json_encode($query);

            $this->assertSame($this->activePeriod->id, $props['period']['id'], $label);
            $this->assertSame('', $props['filters']['period'], $label);
            $this->assertSame('', $props['filters']['class'], $label);
            $this->assertSame('', $props['filters']['subject'], $label);
            $this->assertSame(self::EXPECTED_ADMIN_COUNTS, $props['counts'], $label);
        }

        // Administrators may select any period, and the classes of that period.
        $props = $this->monitoringProps($this->academicAdmin, ['period' => $this->otherPeriod->id, 'class' => $this->batchOther->id]);
        $this->assertSame($this->otherPeriod->id, $props['period']['id']);
        $this->assertSame((string) $this->batchOther->id, $props['filters']['class']);
        $this->assertSame(1, $props['counts']['monitored']);
    }

    /**
     * Invalid filter values are ignored (design item 4), so a crafted
     * "?class[]=..." must not turn into a server error (AGENTS.md §32, §50).
     */
    public function test_array_query_parameters_are_ignored_instead_of_causing_a_server_error(): void
    {
        $problems = [];

        foreach (['period', 'class', 'subject', 'standing', 'sort', 'search'] as $key) {
            $response = $this->actingAs($this->alpha)->get('/monitoring?'.http_build_query([$key => [$this->batchB->id]]));

            if ($response->status() !== 200) {
                $problems[] = "{$key}[]: HTTP {$response->status()}";

                continue;
            }

            $props = $this->propsOf($response);
            if ($props['filters'][$key] !== '') {
                $problems[] = "{$key}[]: echoed as ".json_encode($props['filters'][$key]);
            }
            if ($props['counts'] !== self::EXPECTED_ALPHA_COUNTS) {
                $problems[] = "{$key}[]: counts changed";
            }
        }

        $this->assertSame([], $problems, 'Array query parameters must be ignored.');
    }

    public function test_combined_tampering_leaks_nothing_about_other_instructors_subjects(): void
    {
        $props = $this->monitoringProps($this->alpha, [
            'period' => $this->otherPeriod->id,
            'class' => $this->batchB->id,
            'subject' => $this->subject2->id,
            'standing' => 'incomplete',
            'search' => 'Candidate',
            'sort' => 'lowest',
        ]);

        $this->assertSame(self::EXPECTED_ALPHA_COUNTS, $props['counts']);
        // A2 is Incomplete only in Bravo's Subject 2, so Alpha has no Incomplete candidates.
        $this->assertSame([], $props['candidates']['data']);
        $this->assertPropsDoNotMention($props, $this->outsideAlphaScope());
    }

    // ------------------------------------------------------------------
    // Counts
    // ------------------------------------------------------------------

    public function test_counts_reconcile_for_every_viewer_and_filter(): void
    {
        $cases = [
            [$this->academicAdmin, []],
            [$this->academicAdmin, ['class' => $this->batchA->id]],
            [$this->academicAdmin, ['class' => $this->batchB->id]],
            [$this->academicAdmin, ['class' => $this->batchC->id]],
            [$this->academicAdmin, ['subject' => $this->subject1->id]],
            [$this->academicAdmin, ['class' => $this->batchA->id, 'subject' => $this->subject2->id]],
            [$this->academicAdmin, ['period' => $this->pastPeriod->id]],
            [$this->alpha, []],
            [$this->bravo, []],
            [$this->bravo, ['subject' => $this->subject1->id]],
            [$this->charlie, []],
        ];

        foreach ($cases as [$viewer, $query]) {
            $label = $viewer->name.' '.json_encode($query);
            $props = $this->monitoringProps($viewer, $query);
            $counts = $props['counts'];

            $this->assertSame(
                $counts['monitored'],
                $counts['failing'] + $counts['atRisk'] + $counts['incomplete'] + $counts['passing'] + $counts['noStanding'],
                "Counts must reconcile for {$label}.",
            );
            $this->assertSame($counts['monitored'], $props['candidates']['total'], "Unfiltered list size for {$label}.");

            // Every row agrees with the bucket it is counted in.
            $byStanding = array_count_values(array_map(
                fn (array $row): string => $row['standing']['value'] ?? 'none',
                $props['candidates']['data'],
            ));
            foreach (self::STANDING_COUNT_KEYS as $value => $key) {
                $this->assertSame($counts[$key], $byStanding[$value] ?? 0, "Rows with standing [{$value}] for {$label}.");
            }
        }
    }

    public function test_each_standing_filter_lists_exactly_the_candidates_counted_for_it(): void
    {
        foreach ([$this->academicAdmin, $this->alpha, $this->bravo] as $viewer) {
            $counts = $this->monitoringProps($viewer)['counts'];

            foreach (self::STANDING_COUNT_KEYS as $value => $key) {
                $props = $this->monitoringProps($viewer, ['standing' => $value]);

                $this->assertSame($value, $props['filters']['standing']);
                $this->assertSame($counts[$key], $props['candidates']['total'], "{$viewer->name}: [{$value}] list must match its count.");
                foreach ($props['candidates']['data'] as $row) {
                    $this->assertSame($value, $row['standing']['value'] ?? 'none');
                }
            }
        }
    }

    public function test_counts_ignore_search_standing_sort_and_page(): void
    {
        foreach ([
            ['search' => 'OCS-0005'],
            ['search' => 'nobody matches this'],
            ['standing' => 'failing'],
            ['standing' => 'none'],
            ['sort' => 'name'],
            ['sort' => 'highest'],
            ['page' => 2],
        ] as $query) {
            $props = $this->monitoringProps($this->academicAdmin, $query);

            $this->assertSame(self::EXPECTED_ADMIN_COUNTS, $props['counts'], json_encode($query));
        }
    }

    // ------------------------------------------------------------------
    // Filters
    // ------------------------------------------------------------------

    public function test_class_filter_narrows_counts_candidates_and_subject_options(): void
    {
        $props = $this->monitoringProps($this->academicAdmin, ['class' => $this->batchA->id]);

        $this->assertSame((string) $this->batchA->id, $props['filters']['class']);
        $this->assertSame(['monitored' => 4, 'failing' => 2, 'atRisk' => 1, 'incomplete' => 0, 'passing' => 1, 'noStanding' => 0], $props['counts']);
        $this->assertEveryRowInClass($props, $this->batchA);
        $this->assertSame(['Subject 1', 'Subject 2'], array_column($props['subjectOptions'], 'name'));
        // Every class of the period stays selectable.
        $this->assertCount(3, $props['classOptions']);
        $this->assertTrue($props['singleClass']);

        $props = $this->monitoringProps($this->academicAdmin, ['class' => $this->batchC->id]);
        $this->assertSame(['monitored' => 1, 'failing' => 0, 'atRisk' => 0, 'incomplete' => 0, 'passing' => 0, 'noStanding' => 1], $props['counts']);
        $this->assertSame([$this->ungradedInC->id], $this->candidateIds($props));
        $this->assertSame(['Subject 3'], array_column($props['subjectOptions'], 'name'));
    }

    public function test_subject_filter_must_belong_to_the_selected_class(): void
    {
        // Subject 1 is not offered in Batch C: ignored, the class filter stays.
        $props = $this->monitoringProps($this->academicAdmin, ['class' => $this->batchC->id, 'subject' => $this->subject1->id]);

        $this->assertSame((string) $this->batchC->id, $props['filters']['class']);
        $this->assertSame('', $props['filters']['subject']);
        $this->assertSame('overall', $props['view']);
        $this->assertSame(1, $props['counts']['monitored']);

        // Subject 2 of Batch A: only Batch A, only Subject 2 results.
        $props = $this->monitoringProps($this->academicAdmin, ['class' => $this->batchA->id, 'subject' => $this->subject2->id]);
        $this->assertSame((string) $this->subject2->id, $props['filters']['subject']);
        $this->assertSame(['monitored' => 4, 'failing' => 1, 'atRisk' => 0, 'incomplete' => 1, 'passing' => 2, 'noStanding' => 0], $props['counts']);
    }

    public function test_standing_filter_lists_only_that_standing_in_the_default_order(): void
    {
        $expected = [
            'failing' => [$this->secondInA->id, $this->candidateInA->id],
            'at_risk' => [$this->candidateInB->id, $this->atRiskInA->id],
            'incomplete' => [$this->missingInB->id],
            'passing' => [$this->passingInA->id],
            'none' => [$this->ungradedInC->id],
        ];

        foreach ($expected as $standing => $ids) {
            $props = $this->monitoringProps($this->academicAdmin, ['standing' => $standing]);

            $this->assertSame($ids, $this->candidateIds($props), "Standing [{$standing}].");
        }

        // Class and standing combine.
        $props = $this->monitoringProps($this->academicAdmin, ['class' => $this->batchB->id, 'standing' => 'at_risk']);
        $this->assertSame([$this->candidateInB->id], $this->candidateIds($props));
    }

    public function test_unknown_standing_is_ignored_and_echoed_empty(): void
    {
        foreach (['excellent', 'FAILING', 'At Risk', 'at risk', 'failing,passing'] as $standing) {
            $props = $this->monitoringProps($this->academicAdmin, ['standing' => $standing]);

            $this->assertSame('', $props['filters']['standing'], "Standing [{$standing}].");
            $this->assertSame(7, $props['candidates']['total']);
        }
    }

    public function test_search_matches_number_and_name_literally_within_the_scope(): void
    {
        $this->assertSame([$this->atRiskInA->id], $this->candidateIds($this->monitoringProps($this->academicAdmin, ['search' => 'OCS-0005'])));

        // Trimmed, and echoed trimmed.
        $props = $this->monitoringProps($this->academicAdmin, ['search' => '  OCS-0005  ']);
        $this->assertSame('OCS-0005', $props['filters']['search']);
        $this->assertSame([$this->atRiskInA->id], $this->candidateIds($props));

        // Full name, case-insensitive; the withdrawn A3 is never found.
        $this->assertSame(
            [$this->secondInA->id, $this->candidateInA->id, $this->atRiskInA->id, $this->passingInA->id],
            $this->candidateIds($this->monitoringProps($this->academicAdmin, ['search' => 'candidate a'])),
        );
        $this->assertSame([], $this->candidateIds($this->monitoringProps($this->academicAdmin, ['search' => 'A3'])));

        // LIKE wildcards are matched literally.
        $this->assertSame([], $this->candidateIds($this->monitoringProps($this->academicAdmin, ['search' => '%'])));
        $this->assertSame([], $this->candidateIds($this->monitoringProps($this->academicAdmin, ['search' => 'OCS_0005'])));

        // Instructors cannot find candidates outside their classes.
        $this->assertSame([], $this->candidateIds($this->monitoringProps($this->alpha, ['search' => 'Candidate B1'])));
        $this->assertSame([], $this->candidateIds($this->monitoringProps($this->alpha, ['search' => 'OCS-0012'])));
    }

    // ------------------------------------------------------------------
    // Sorting
    // ------------------------------------------------------------------

    public function test_default_sort_is_most_serious_first_then_lowest_grade(): void
    {
        $props = $this->monitoringProps($this->academicAdmin);

        $this->assertSame('', $props['filters']['sort']);
        $this->assertSame([
            $this->secondInA->id,   // Failing, lowest 60
            $this->candidateInA->id, // Failing, lowest 70
            $this->candidateInB->id, // At Risk, 77
            $this->atRiskInA->id,    // At Risk, lowest 78
            $this->missingInB->id,   // Incomplete
            $this->passingInA->id,   // Passing
            $this->ungradedInC->id,  // No standing yet
        ], $this->candidateIds($props));
    }

    public function test_lowest_grade_sort_puts_candidates_without_grades_last(): void
    {
        $props = $this->monitoringProps($this->academicAdmin, ['sort' => 'lowest']);

        $this->assertSame('lowest', $props['filters']['sort']);
        $this->assertSame([
            $this->secondInA->id,    // 60
            $this->candidateInA->id, // 70
            $this->candidateInB->id, // 77
            $this->atRiskInA->id,    // 78
            $this->passingInA->id,   // 80
            $this->missingInB->id,   // no grade (Incomplete)
            $this->ungradedInC->id,  // no grade (no standing)
        ], $this->candidateIds($props));
        $this->assertSame([60.0, 70.0, 77.0, 78.0, 80.0, null, null], $this->lowestGrades($props));
    }

    public function test_highest_grade_sort_puts_candidates_without_grades_last(): void
    {
        $props = $this->monitoringProps($this->academicAdmin, ['sort' => 'highest']);

        $this->assertSame([
            $this->passingInA->id,
            $this->atRiskInA->id,
            $this->candidateInB->id,
            $this->candidateInA->id,
            $this->secondInA->id,
            $this->missingInB->id,
            $this->ungradedInC->id,
        ], $this->candidateIds($props));
        $this->assertSame([80.0, 78.0, 77.0, 70.0, 60.0, null, null], $this->lowestGrades($props));
    }

    public function test_name_sort_orders_by_name_whatever_the_standing(): void
    {
        $props = $this->monitoringProps($this->academicAdmin, ['sort' => 'name']);

        $this->assertSame([
            $this->candidateInA->id, // Candidate A1
            $this->secondInA->id,    // Candidate A2
            $this->passingInA->id,   // Candidate A4
            $this->atRiskInA->id,    // Candidate A5
            $this->candidateInB->id, // Candidate B1
            $this->missingInB->id,   // Candidate B2
            $this->ungradedInC->id,  // Candidate C01
        ], $this->candidateIds($props));
    }

    public function test_unknown_sort_falls_back_to_most_serious_first(): void
    {
        $default = $this->candidateIds($this->monitoringProps($this->academicAdmin));

        foreach (['random', 'LOWEST', 'grade desc'] as $sort) {
            $props = $this->monitoringProps($this->academicAdmin, ['sort' => $sort]);

            $this->assertSame('', $props['filters']['sort'], "Sort [{$sort}].");
            $this->assertSame($default, $this->candidateIds($props));
        }
    }

    public function test_instructor_sorts_use_only_the_grades_of_their_own_subjects(): void
    {
        // If Bravo's Subject 2 leaked in, A1's lowest grade would be 70 instead of 90.
        $lowest = $this->monitoringProps($this->alpha, ['sort' => 'lowest']);
        $this->assertSame(
            [$this->secondInA->id, $this->atRiskInA->id, $this->passingInA->id, $this->candidateInA->id],
            $this->candidateIds($lowest),
        );
        $this->assertSame([60.0, 78.0, 80.0, 90.0], $this->lowestGrades($lowest));

        $highest = $this->monitoringProps($this->alpha, ['sort' => 'highest']);
        $this->assertSame(
            [$this->candidateInA->id, $this->passingInA->id, $this->atRiskInA->id, $this->secondInA->id],
            $this->candidateIds($highest),
        );

        // Bravo: most serious first; both Incomplete candidates (no grade) keep name order.
        $this->assertSame([
            $this->candidateInA->id, // Failing in Subject 2 (70)
            $this->candidateInB->id, // At Risk (77)
            $this->secondInA->id,    // Incomplete (Subject 2 missing)
            $this->missingInB->id,   // Incomplete (quiz missing)
            $this->passingInA->id,   // Passing 85
            $this->atRiskInA->id,    // Passing 90 in Bravo's subject
        ], $this->candidateIds($this->monitoringProps($this->bravo)));
    }

    // ------------------------------------------------------------------
    // Overall and subject views
    // ------------------------------------------------------------------

    public function test_overall_view_rows_cover_every_scoped_subject(): void
    {
        $props = $this->monitoringProps($this->academicAdmin);
        $this->assertSame('overall', $props['view']);
        $this->assertFalse($props['singleClass']);

        $a1 = $this->rowFor($props, $this->candidateInA);
        $this->assertSame('failing', $a1['standing']['value']);
        $this->assertSame(2, $a1['overall']['basedOnSubjects']);
        $this->assertSame(2, $a1['overall']['totalSubjects']);
        $this->assertSame(70.0, (float) $a1['lowest']['grade']);
        $this->assertSame('Subject 2', $a1['lowest']['subject']);
        $this->assertSame(['Subject 2'], array_column($a1['concerns'], 'subject'));
        $this->assertSame('Subject 2', $a1['mostSerious']['subject']);
        $this->assertNull($a1['subjectResult']);
        $this->assertSame(['id' => $this->batchA->id, 'name' => 'Sample Batch A'], $a1['classBatch']);

        // A2: Failing Subject 1 first, then the missing Subject 2 exam.
        $a2 = $this->rowFor($props, $this->secondInA);
        $this->assertSame(['Subject 1', 'Subject 2'], array_column($a2['concerns'], 'subject'));
        $this->assertSame('failing', $a2['concerns'][0]['standing']['value']);
        $this->assertSame('incomplete', $a2['concerns'][1]['standing']['value']);
        $this->assertSame(1, $a2['missingScores']);
        $this->assertSame(2, $a2['overall']['basedOnSubjects']);
        $this->assertSame(60.0, (float) $a2['lowest']['grade']);

        // On Leave candidates are monitored and their status explains the row.
        $a4 = $this->rowFor($props, $this->passingInA);
        $this->assertSame('On Leave', $a4['candidate']['status']);
        $this->assertSame('passing', $a4['standing']['value']);
        $this->assertSame([], $a4['concerns']);
        $this->assertNull($a4['mostSerious']);
        $this->assertNull($a1['candidate']['status']);

        // Subjects requiring attention: withdrawn A3's failing scores are not counted.
        $this->assertSame([
            ['classSubjectId' => $this->offeringA1->id, 'failing' => 1, 'atRisk' => 1, 'incomplete' => 0],
            ['classSubjectId' => $this->offeringA2->id, 'failing' => 1, 'atRisk' => 0, 'incomplete' => 1],
            ['classSubjectId' => $this->offeringB1->id, 'failing' => 0, 'atRisk' => 1, 'incomplete' => 1],
        ], array_map(
            fn (array $row): array => array_intersect_key($row, array_flip(['classSubjectId', 'failing', 'atRisk', 'incomplete'])),
            $props['subjectsRequiringAttention'],
        ));
    }

    public function test_subject_view_shows_only_that_subjects_result(): void
    {
        $props = $this->monitoringProps($this->academicAdmin, ['subject' => $this->subject1->id]);

        $this->assertSame('subject', $props['view']);
        $this->assertSame([], $props['subjectsRequiringAttention']);
        // Batches A and B offer Subject 1; Batch C does not.
        $this->assertSame(['monitored' => 6, 'failing' => 1, 'atRisk' => 2, 'incomplete' => 1, 'passing' => 2, 'noStanding' => 0], $props['counts']);
        $this->assertNotContains($this->ungradedInC->id, $this->candidateIds($props));

        $offeringByClass = [$this->batchA->id => $this->offeringA1->id, $this->batchB->id => $this->offeringB1->id];
        foreach ($props['candidates']['data'] as $row) {
            $this->assertSame(1, $row['overall']['totalSubjects']);
            $this->assertSame($offeringByClass[$row['classBatch']['id']], $row['subjectResult']['classSubjectId']);
            $this->assertArrayNotHasKey('categories', $row['subjectResult']['result']);
            $this->assertFalse($row['subjectResult']['canOpenGradebook'], 'Administrators do not open gradebooks.');
        }

        // A1 is Passing in Subject 1 even though Failing Subject 2.
        $a1 = $this->rowFor($props, $this->candidateInA);
        $this->assertSame('passing', $a1['standing']['value']);
        $this->assertSame(90.0, (float) $a1['subjectResult']['result']['grade']);
        $this->assertSame('passing', $a1['subjectResult']['result']['standing']['value']);

        $b2 = $this->rowFor($props, $this->missingInB);
        $this->assertNull($b2['subjectResult']['result']['grade']);
        $this->assertSame(1, $b2['subjectResult']['result']['missingScores']);
        $this->assertSame('incomplete', $b2['standing']['value']);

        // The subject view sorts by that subject's grade.
        $this->assertSame(
            [$this->secondInA->id, $this->candidateInB->id, $this->atRiskInA->id, $this->passingInA->id, $this->candidateInA->id, $this->missingInB->id],
            $this->candidateIds($this->monitoringProps($this->academicAdmin, ['subject' => $this->subject1->id, 'sort' => 'lowest'])),
        );
    }

    public function test_instructor_subject_view_links_only_to_their_own_gradebook(): void
    {
        $props = $this->monitoringProps($this->alpha, ['subject' => $this->subject1->id]);

        $this->assertNotEmpty($props['candidates']['data']);
        foreach ($props['candidates']['data'] as $row) {
            $this->assertTrue($row['subjectResult']['canOpenGradebook']);
        }

        // Bravo's Subject 1 is in Batch B; his Batch A Subject 2 rows are not in this view.
        $props = $this->monitoringProps($this->bravo, ['subject' => $this->subject1->id]);
        $this->assertSame([$this->candidateInB->id, $this->missingInB->id], $this->candidateIds($props));
        foreach ($props['candidates']['data'] as $row) {
            $this->assertSame($this->offeringB1->id, $row['subjectResult']['classSubjectId']);
            $this->assertTrue($row['subjectResult']['canOpenGradebook']);
        }
    }

    public function test_instructors_get_the_taught_scope_behind_standing_in_your_subjects(): void
    {
        // The page labels standings "Standing in Your Subjects" when the
        // scope is "taught" and "Overall Standing" when it is "all". The
        // label is truthful only if the numbers behind it match the scope.
        $alpha = $this->monitoringProps($this->alpha);
        $this->assertSame('taught', $alpha['scope']);
        $a1 = $this->rowFor($alpha, $this->candidateInA);
        $this->assertSame('passing', $a1['standing']['value']);
        $this->assertSame(1, $a1['overall']['totalSubjects']);
        $this->assertSame('Subject 1', $a1['lowest']['subject']);
        $this->assertSame([], $a1['concerns']);

        $bravo = $this->monitoringProps($this->bravo);
        $this->assertSame('taught', $bravo['scope']);
        $this->assertSame('failing', $this->rowFor($bravo, $this->candidateInA)['standing']['value']);
        $this->assertSame(1, $this->rowFor($bravo, $this->candidateInA)['overall']['totalSubjects']);

        $admin = $this->monitoringProps($this->academicAdmin);
        $this->assertSame('all', $admin['scope']);
        $this->assertSame(2, $this->rowFor($admin, $this->candidateInA)['overall']['totalSubjects']);
    }

    // ------------------------------------------------------------------
    // Pagination
    // ------------------------------------------------------------------

    public function test_pagination_shows_10_per_page_and_keeps_path_and_query_string(): void
    {
        $this->addUngradedCandidatesToBatchC(15); // C02 ... C16
        $query = ['class' => $this->batchC->id, 'sort' => 'name', 'search' => 'candidate c'];

        $first = $this->monitoringProps($this->academicAdmin, $query);
        $this->assertSame(16, $first['candidates']['total']);
        $this->assertSame(10, $first['candidates']['per_page']);
        $this->assertSame(1, $first['candidates']['current_page']);
        $this->assertSame(2, $first['candidates']['last_page']);
        $this->assertCount(10, $first['candidates']['data']);
        $this->assertSame('Candidate C01', $first['candidates']['data'][0]['candidate']['name']);
        $this->assertSame('Candidate C10', $first['candidates']['data'][9]['candidate']['name']);
        $this->assertNull($first['candidates']['prev_page_url']);
        $this->assertPageUrl($first['candidates']['next_page_url'], [...$query, 'page' => 2]);

        $second = $this->monitoringProps($this->academicAdmin, [...$query, 'page' => 2]);
        $this->assertSame(2, $second['candidates']['current_page']);
        $this->assertSame(
            ['Candidate C11', 'Candidate C12', 'Candidate C13', 'Candidate C14', 'Candidate C15', 'Candidate C16'],
            array_map(fn (array $row): string => $row['candidate']['name'], $second['candidates']['data']),
        );
        $this->assertNull($second['candidates']['next_page_url']);
        $this->assertPageUrl($second['candidates']['prev_page_url'], [...$query, 'page' => 1]);

        // No candidate appears twice or is skipped across pages.
        $all = [...$this->candidateIds($first), ...$this->candidateIds($second)];
        $this->assertCount(16, array_unique($all));

        // The counts are for the whole class, not the page.
        $this->assertSame(16, $second['counts']['monitored']);
        $this->assertSame(16, $second['counts']['noStanding']);
    }

    public function test_invalid_page_numbers_fall_back_to_the_first_page(): void
    {
        $this->addUngradedCandidatesToBatchC(30);
        $firstPage = $this->candidateIds($this->monitoringProps($this->academicAdmin));

        foreach (['-1', '0', 'abc', '1.5', '2abc', ''] as $page) {
            $props = $this->monitoringProps($this->academicAdmin, ['page' => $page]);

            $this->assertSame(1, $props['candidates']['current_page'], "Page [{$page}].");
            $this->assertSame($firstPage, $this->candidateIds($props), "Page [{$page}].");
            $this->assertSame(37, $props['candidates']['total']);
        }

        $response = $this->actingAs($this->academicAdmin)->get('/monitoring?'.http_build_query(['page' => ['2']]));
        $response->assertOk();
        $this->assertSame(1, $response->inertiaProps('candidates.current_page'));
    }

    public function test_page_beyond_the_last_is_empty_but_keeps_totals_and_counts(): void
    {
        foreach (['2', '999999', '99999999999999999999'] as $page) {
            $props = $this->monitoringProps($this->academicAdmin, ['page' => $page]);

            $this->assertSame(7, $props['candidates']['total'], "Page [{$page}].");
            $this->assertSame(self::EXPECTED_ADMIN_COUNTS, $props['counts']);
            if ($page !== '99999999999999999999') {
                $this->assertSame([], $props['candidates']['data'], "Page [{$page}].");
            }
        }
    }

    /**
     * Page numbers that are valid integers but whose offset no longer fits
     * in an integer (above roughly 3.7 * 10^17 with 25 per page) must give
     * an empty page like any other page past the end, not a server error.
     */
    public function test_huge_integer_page_numbers_return_an_empty_page_instead_of_a_server_error(): void
    {
        foreach (['400000000000000000', (string) PHP_INT_MAX] as $page) {
            $response = $this->actingAs($this->academicAdmin)->get('/monitoring?page='.$page);

            $this->assertSame(200, $response->status(), "Page [{$page}] must not fail.");
            $props = $this->propsOf($response);
            $this->assertSame(7, $props['candidates']['total']);
            $this->assertSame([], $props['candidates']['data']);
        }
    }

    // ------------------------------------------------------------------
    // Periods without thresholds
    // ------------------------------------------------------------------

    public function test_period_without_thresholds_lists_grades_but_no_standing(): void
    {
        $old2 = $this->makeCandidate($this->batchOld, 'OCS-0102', 'Old2');
        $old3 = $this->makeCandidate($this->batchOld, 'OCS-0103', 'Old3');
        $old1 = Candidate::query()->where('class_batch_id', $this->batchOld->id)->where('last_name', 'Old1')->sole();
        $offeringOld = $this->offering($this->batchOld, $this->subject1);
        $this->setScheme($offeringOld, ['Quizzes' => '100']);
        $quiz = $this->createAssessment($offeringOld->assessmentCategories()->sole(), 'Past Quiz', '10');
        $this->recordScores($quiz, [$old1->id => '9', $old2->id => '6']); // Old3 missing
        $this->finalize($quiz);

        foreach ([$this->academicAdmin, $this->alpha] as $viewer) {
            $props = $this->monitoringProps($viewer, ['period' => $this->pastPeriod->id]);

            $this->assertSame($this->pastPeriod->id, $props['period']['id']);
            $this->assertNull($props['thresholds']);
            $this->assertSame(['monitored' => 3, 'failing' => 0, 'atRisk' => 0, 'incomplete' => 0, 'passing' => 0, 'noStanding' => 3], $props['counts']);
            $this->assertSame([], $props['subjectsRequiringAttention']);
            // Without standings the list is ordered by grade, low to high, ungraded last.
            $this->assertSame([$old2->id, $old1->id, $old3->id], $this->candidateIds($props));
            $this->assertSame([60.0, 90.0, null], $this->lowestGrades($props));
            foreach ($props['candidates']['data'] as $row) {
                $this->assertNull($row['standing']);
                $this->assertNull($row['overall']['standing']);
            }
            // Missing scores are still reported, never counted as zero.
            $old3Row = $this->rowFor($props, $old3);
            $this->assertSame(1, $old3Row['missingScores']);
            $this->assertNull($old3Row['lowest']);
        }

        // A standing filter has nothing to filter on: every candidate stays listed.
        $props = $this->monitoringProps($this->academicAdmin, ['period' => $this->pastPeriod->id, 'standing' => 'failing']);
        $this->assertSame(3, $props['candidates']['total']);

        $props = $this->monitoringProps($this->academicAdmin, ['period' => $this->pastPeriod->id, 'sort' => 'highest']);
        $this->assertSame([$old1->id, $old2->id, $old3->id], $this->candidateIds($props));

        $props = $this->monitoringProps($this->academicAdmin, ['period' => $this->pastPeriod->id, 'sort' => 'name']);
        $this->assertSame([$old1->id, $old2->id, $old3->id], $this->candidateIds($props));
    }

    public function test_administrator_without_thresholds_is_offered_the_threshold_setup(): void
    {
        $props = $this->monitoringProps($this->academicAdmin, ['period' => $this->otherPeriod->id]);
        $this->assertNull($props['thresholds']);
        $this->assertTrue($props['canConfigureThresholds']);

        $props = $this->monitoringProps($this->charlie);
        $this->assertNull($props['thresholds']);
        $this->assertFalse($props['canConfigureThresholds']);
        $this->assertSame(['monitored' => 1, 'failing' => 0, 'atRisk' => 0, 'incomplete' => 0, 'passing' => 0, 'noStanding' => 1], $props['counts']);
    }

    // ------------------------------------------------------------------
    // Withdrawn and unassigned candidates
    // ------------------------------------------------------------------

    public function test_withdrawn_candidates_are_never_monitored_or_counted(): void
    {
        foreach ([$this->academicAdmin, $this->alpha, $this->bravo] as $viewer) {
            foreach ([[], ['standing' => 'failing'], ['search' => 'A3'], ['search' => $this->withdrawnInA->candidate_number], ['sort' => 'name']] as $query) {
                $props = $this->monitoringProps($viewer, $query);

                $this->assertNotContains($this->withdrawnInA->id, $this->candidateIds($props), $viewer->name.' '.json_encode($query));
                $this->assertPropsDoNotMention($props, ['Candidate A3']);
            }
        }

        // Withdrawing a Failing candidate removes them from every count.
        $this->secondInA->forceFill(['status' => CandidateStatus::Withdrawn->value])->save();

        $props = $this->monitoringProps($this->academicAdmin);
        $this->assertSame(['monitored' => 6, 'failing' => 1, 'atRisk' => 2, 'incomplete' => 1, 'passing' => 1, 'noStanding' => 1], $props['counts']);
        $this->assertNotContains($this->secondInA->id, $this->candidateIds($props));
        $this->assertSame(0, collect($props['subjectsRequiringAttention'])->firstWhere('classSubjectId', $this->offeringA1->id)['failing']);
    }

    public function test_candidates_without_a_class_are_not_monitored(): void
    {
        $unassigned = Candidate::factory()->create([
            'candidate_number' => 'OCS-0099',
            'first_name' => 'Candidate',
            'last_name' => 'Unassigned',
            'class_batch_id' => null,
        ]);

        $props = $this->monitoringProps($this->academicAdmin);

        $this->assertSame(self::EXPECTED_ADMIN_COUNTS, $props['counts']);
        $this->assertNotContains($unassigned->id, $this->candidateIds($props));
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    private function monitoringProps(User $viewer, array $query = []): array
    {
        $response = $this->actingAs($viewer)->get('/monitoring'.($query === [] ? '' : '?'.http_build_query($query)));

        $response->assertOk()->assertInertia(fn (Assert $page) => $page->component('staff/monitoring/index'));

        return $this->propsOf($response);
    }

    /**
     * @return array<string, mixed>
     */
    private function propsOf(TestResponse $response): array
    {
        $props = $response->inertiaProps();
        $this->assertIsArray($props);

        return $props;
    }

    /**
     * @param  array<string, mixed>  $props
     * @return list<int>
     */
    private function candidateIds(array $props): array
    {
        return array_map(fn (array $row): int => $row['candidate']['id'], $props['candidates']['data']);
    }

    /**
     * @param  array<string, mixed>  $props
     * @return list<float|null>
     */
    private function lowestGrades(array $props): array
    {
        return array_map(
            fn (array $row): ?float => $row['lowest'] === null ? null : (float) $row['lowest']['grade'],
            $props['candidates']['data'],
        );
    }

    /**
     * @param  array<string, mixed>  $props
     * @return array<string, mixed>
     */
    private function rowFor(array $props, Candidate $candidate): array
    {
        $row = collect($props['candidates']['data'])->first(fn (array $row): bool => $row['candidate']['id'] === $candidate->id);
        $this->assertNotNull($row, "{$candidate->full_name} should be listed.");

        return $row;
    }

    /**
     * @param  array<string, mixed>  $props
     */
    private function assertEveryRowInClass(array $props, ClassBatch $classBatch): void
    {
        foreach ($props['candidates']['data'] as $row) {
            $this->assertSame($classBatch->id, $row['classBatch']['id']);
        }
    }

    /**
     * @param  array<string, mixed>  $props
     * @param  list<string>  $needles
     */
    private function assertPropsDoNotMention(array $props, array $needles): void
    {
        $json = json_encode($props, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        foreach ($needles as $needle) {
            $this->assertStringNotContainsString($needle, $json, "The page must not reveal [{$needle}].");
        }
    }

    /**
     * Names and numbers Alpha must never see in the active period: other
     * instructors' subjects and classes, and candidates outside Batch A.
     *
     * @return list<string>
     */
    private function outsideAlphaScope(): array
    {
        return [
            'Subject 2', 'Subject 3',
            'Sample Batch B', 'Sample Batch C', 'Sample Batch Other',
            'Candidate B1', 'Candidate B2', 'Candidate C01', 'Candidate A3',
            'OCS-0012', 'OCS-0021', $this->candidateInB->candidate_number,
            'Period Other',
        ];
    }

    /**
     * @param  array<string, mixed>  $expectedQuery
     */
    private function assertPageUrl(?string $url, array $expectedQuery): void
    {
        $this->assertNotNull($url);
        $this->assertSame('/monitoring', parse_url($url, PHP_URL_PATH));
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $this->assertEquals(array_map('strval', $expectedQuery), $query);
    }

    private function makeCandidate(ClassBatch $classBatch, string $number, string $lastName, CandidateStatus $status = CandidateStatus::Enrolled): Candidate
    {
        return Candidate::factory()->create([
            'candidate_number' => $number,
            'first_name' => 'Candidate',
            'last_name' => $lastName,
            'class_batch_id' => $classBatch->id,
            'status' => $status->value,
        ]);
    }

    private function addUngradedCandidatesToBatchC(int $count): void
    {
        for ($index = 2; $index <= $count + 1; $index++) {
            $this->makeCandidate($this->batchC, sprintf('OCS-1%03d', $index), sprintf('C%02d', $index));
        }
    }

    /**
     * Moves a user to a custom role holding exactly the given permissions.
     *
     * @param  list<PermissionCode>  $permissions
     */
    private function withCustomRole(User $user, string $code, array $permissions): User
    {
        $role = Role::query()->create(['code' => $code, 'name' => ucwords(str_replace('_', ' ', $code))]);
        $role->permissions()->sync(Permission::query()->whereIn(
            'code',
            array_map(fn (PermissionCode $permission): string => $permission->value, $permissions),
        )->pluck('id'));
        $user->role()->associate($role)->save();

        return $user->fresh();
    }
}
