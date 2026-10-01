<?php

namespace Tests\Feature\Performance;

use App\Enums\AttendanceStatus;
use App\Enums\Permission as PermissionCode;
use App\Enums\SystemRole;
use App\Models\AcademicPeriod;
use App\Models\Candidate;
use App\Models\ConductType;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Subject;
use App\Models\User;
use App\Services\Attendance\AttendanceService;
use App\Services\Conduct\ConductService;
use App\Services\Performance\PerformanceAreaService;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Grading\BuildsGradingFixtures;
use Tests\TestCase;

/**
 * Module E (owner request, 2026-10-01): performance, conduct and attendance
 * on the staff candidate profile, the candidate's "My Performance" page and
 * home card, and the dashboard's qualification panel.
 *
 * On top of the grading fixtures (Batch A: A1, A2, A3 withdrawn; Batch B: B1):
 *
 *   Areas      Academic (Subject 1, weight 50, pass 75), Conduct (85/1/1,
 *              weight 25, pass 75), Attendance (weight 25, pass 90); all must pass
 *   Subject 1  Batch A quiz: A1 90, A2 60
 *   Conduct    A1: merit 3, and a demerit of 5 that was voided
 *   Attendance Batch A session: A1 present (with a staff remark), A2 absent
 *
 *   A1 Qualified, A2 Not Qualified (Academic, Attendance), B1 Pending (no
 *   grades and no attendance yet).
 */
class CandidatePerformanceIntegrationTest extends TestCase
{
    use BuildsGradingFixtures;

    private const STAFF_REMARK = 'Reported early to the duty officer.';

    private const VOID_REASON = 'Recorded for the wrong candidate.';

    private User $superAdmin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-09-20 09:00:00');
        $this->buildGradingFixtures();
        $this->superAdmin = $this->userWithRole(SystemRole::SuperAdministrator, ['name' => 'Chief Registrar']);
    }

    private function buildPerformanceRecords(): void
    {
        $areas = $this->app->make(PerformanceAreaService::class);
        $area = fn (string $name, string $source, string $weight, string $passing, int $order, array $extra = []): array => [
            'name' => $name, 'description' => null, 'source' => $source, 'weight' => $weight, 'passing_grade' => $passing,
            'must_pass' => true, 'base_rating' => null, 'merit_value' => null, 'demerit_value' => null, 'sort_order' => $order,
            'is_active' => true, ...$extra,
        ];
        $areas->create($area('Academic', 'subjects', '50', '75', 1), [Subject::query()->where('code', 'SUBJ-1')->value('id')]);
        $areas->create($area('Conduct', 'conduct', '25', '75', 2, ['base_rating' => '85', 'merit_value' => '1', 'demerit_value' => '1']));
        $areas->create($area('Attendance', 'attendance', '25', '90', 3));

        $quiz = $this->createAssessment($this->quizzes, 'Quiz 1', '100', '2026-09-01');
        $this->recordScores($quiz, [$this->candidateInA->id => '90', $this->secondInA->id => '60']);
        $this->finalize($quiz);

        $conduct = $this->app->make(ConductService::class);
        $merit = ConductType::query()->where('name', 'Leadership commendation')->sole();
        $demerit = ConductType::query()->where('name', 'Violation of regulations')->sole();
        $conduct->record($this->candidateInA, ['conduct_type_id' => $merit->id, 'points' => 3, 'occurred_on' => '2026-09-10', 'reason' => 'Led the field exercise.'], $this->superAdmin);
        $mistake = $conduct->record($this->candidateInA, ['conduct_type_id' => $demerit->id, 'points' => 5, 'occurred_on' => '2026-09-11', 'reason' => 'Out of bounds.'], $this->superAdmin);
        $conduct->void($mistake, self::VOID_REASON, $this->superAdmin);

        $attendance = $this->app->make(AttendanceService::class);
        $session = $attendance->create($this->batchA, ['held_on' => '2026-09-12', 'title' => 'Formation', 'hours' => '2', 'notes' => null], $this->superAdmin);
        $attendance->record($session, [
            $this->candidateInA->id => ['status' => AttendanceStatus::Present, 'remarks' => self::STAFF_REMARK],
            $this->secondInA->id => ['status' => AttendanceStatus::Absent, 'remarks' => null],
        ], $this->superAdmin);
    }

    // ------------------------------------------------------------------
    // Staff candidate profile
    // ------------------------------------------------------------------

    public function test_administrators_see_qualification_with_rank_conduct_and_attendance_on_the_profile(): void
    {
        $this->buildPerformanceRecords();

        $props = $this->profileProps($this->academicAdmin, $this->candidateInA);

        $qualification = $props['qualification'];
        $this->assertTrue($qualification['showRank']);
        $this->assertTrue($qualification['canConfigure']);
        $this->assertSame(['Academic', 'Conduct', 'Attendance'], array_column($qualification['areas'], 'name'));
        $this->assertSame('qualified', $qualification['result']['qualification']['status']['value']);
        $this->assertSame(1, $qualification['result']['rank']);
        $this->assertSame(['passed', 'passed', 'passed'], array_map(fn (array $area): string => $area['status']['value'], $qualification['result']['areas']));
        $this->assertEquals(88, $qualification['result']['areas'][1]['grade']);

        // Totals leave the voided demerit out; the latest entries list it, marked.
        $this->assertSame(['merits' => 3, 'demerits' => 0, 'net' => 3], $props['conduct']['totals']);
        $this->assertCount(2, $props['conduct']['entries']);
        $this->assertSame(self::VOID_REASON, $props['conduct']['entries'][0]['voided']['reason']);
        $this->assertTrue($props['conduct']['canManage']);

        $this->assertSame(1, $props['attendance']['summary']['sessions']);
        $this->assertEquals(100, $props['attendance']['summary']['rate']);
        $this->assertSame(self::STAFF_REMARK, $props['attendance']['sessions'][0]['remarks']);
        $this->assertTrue($props['attendance']['canManage']);

        // A2 ranks second and is not qualified, with the reasons in area order.
        $second = $this->profileProps($this->superAdmin, $this->secondInA)['qualification']['result'];
        $this->assertSame(2, $second['rank']);
        $this->assertSame(['Academic requirement not met', 'Attendance requirement not met'], $second['qualification']['reasons']);
    }

    public function test_instructors_see_conduct_and_attendance_of_their_class_but_no_qualification(): void
    {
        $this->buildPerformanceRecords();

        // Alpha teaches only Subject 1 of Batch A and has no fitness access:
        // area grades (every subject, fitness) and the rank are not shown.
        $props = $this->profileProps($this->alpha, $this->candidateInA);
        $this->assertNull($props['qualification']);
        $this->assertSame(['merits' => 3, 'demerits' => 0, 'net' => 3], $props['conduct']['totals']);
        $this->assertTrue($props['conduct']['canManage']);
        $this->assertTrue($props['attendance']['canManage']);
        $this->assertStringNotContainsString('"rank"', (string) json_encode($props));

        // Bravo teaches B1's class.
        $props = $this->profileProps($this->bravo, $this->candidateInB);
        $this->assertNull($props['qualification']);
        $this->assertTrue($props['conduct']['canManage']);
        $this->assertSame(0, $props['attendance']['summary']['sessions']);
    }

    public function test_a_role_that_views_all_candidates_without_performance_view_sees_areas_without_rank(): void
    {
        $this->buildPerformanceRecords();
        $viewer = $this->withCustomRole($this->userWithRole(SystemRole::Instructor), 'records_viewer', [
            PermissionCode::AccessStaffArea, PermissionCode::ViewAllCandidates,
        ]);

        $props = $this->profileProps($viewer, $this->candidateInA);

        $this->assertFalse($props['qualification']['showRank']);
        $this->assertFalse($props['qualification']['canConfigure']);
        $this->assertSame('qualified', $props['qualification']['result']['qualification']['status']['value']);
        $this->assertArrayNotHasKey('rank', $props['qualification']['result']);
        // Visible, but no conduct or attendance management without those permissions.
        $this->assertFalse($props['conduct']['canManage']);
        $this->assertFalse($props['attendance']['canManage']);
    }

    public function test_a_candidate_without_a_class_or_without_areas_gets_empty_panels(): void
    {
        $unassigned = Candidate::factory()->create(['class_batch_id' => null, 'last_name' => 'Unassigned']);

        $props = $this->profileProps($this->academicAdmin, $unassigned);
        $this->assertSame(['areas' => [], 'result' => null, 'showRank' => true, 'canConfigure' => true], $props['qualification']);
        $this->assertSame(['merits' => 0, 'demerits' => 0, 'net' => 0], $props['conduct']['totals']);
        $this->assertSame([], $props['conduct']['entries']);
        $this->assertSame(0, $props['attendance']['summary']['sessions']);
        $this->assertNull($props['attendance']['summary']['rate']);
        $this->assertSame([], $props['attendance']['sessions']);

        // In a class but no area configured yet: Pending, no area results.
        $props = $this->profileProps($this->academicAdmin, $this->candidateInA);
        $this->assertSame([], $props['qualification']['areas']);
        $this->assertSame('pending', $props['qualification']['result']['qualification']['status']['value']);
    }

    // ------------------------------------------------------------------
    // Candidate portal
    // ------------------------------------------------------------------

    public function test_my_performance_shows_only_the_candidates_own_results_without_rank(): void
    {
        $this->buildPerformanceRecords();

        $response = $this->actingAs($this->candidateInA->user)->get('/portal/performance')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('portal/performance'));
        $props = $response->inertiaProps();
        $json = (string) json_encode($props);

        $this->assertSame($this->candidateInA->candidate_number, $props['candidate']['number']);
        $this->assertSame('Sample Batch A', $props['candidate']['className']);
        $this->assertSame(['Academic', 'Conduct', 'Attendance'], array_column($props['areas'], 'name'));
        $this->assertSame($this->candidateInA->id, $props['result']['candidate']['id']);
        $this->assertSame('qualified', $props['result']['qualification']['status']['value']);

        // Never a rank, anywhere in the payload.
        $this->assertArrayNotHasKey('rank', $props['result']);
        $this->assertStringNotContainsString('"rank"', $json);

        // Own merits that count only: no voided entry, no staff names.
        $this->assertSame(['merits' => 3, 'demerits' => 0, 'net' => 3], $props['conduct']['totals']);
        $this->assertCount(1, $props['conduct']['entries']);
        $this->assertSame(['id', 'occurredOn', 'kind', 'type', 'points', 'reason'], array_keys($props['conduct']['entries'][0]));
        $this->assertSame('Led the field exercise.', $props['conduct']['entries'][0]['reason']);

        // Attendance without staff remarks.
        $this->assertSame(1, $props['attendance']['summary']['present']);
        $this->assertNull($props['attendance']['sessions'][0]['remarks']);

        foreach ([self::VOID_REASON, 'Out of bounds.', self::STAFF_REMARK, 'Chief Registrar', $this->secondInA->candidate_number, $this->secondInA->full_name, $this->candidateInB->candidate_number] as $hidden) {
            $this->assertStringNotContainsString($hidden, $json);
        }
    }

    public function test_my_performance_without_a_class_or_areas_and_is_candidate_only(): void
    {
        $unassigned = Candidate::factory()->create(['class_batch_id' => null, 'last_name' => 'Unassigned']);
        $props = $this->actingAs($unassigned->user)->get('/portal/performance')->assertOk()->inertiaProps();
        $this->assertNull($props['result']);
        $this->assertSame([], $props['areas']);
        $this->assertSame(0, $props['attendance']['summary']['sessions']);

        // No areas yet: Pending with no area results.
        $props = $this->actingAs($this->candidateInA->user)->get('/portal/performance')->assertOk()->inertiaProps();
        $this->assertSame([], $props['areas']);
        $this->assertSame('pending', $props['result']['qualification']['status']['value']);

        $this->actingAs($this->academicAdmin)->get('/portal/performance')->assertForbidden();
        $this->actingAs($this->alpha)->get('/portal/performance')->assertForbidden();
    }

    public function test_the_portal_home_card_shows_the_qualification_status_without_rank(): void
    {
        $unassigned = Candidate::factory()->create(['class_batch_id' => null, 'last_name' => 'Unassigned']);
        $this->assertNull($this->homeProps($unassigned->user)['performance']);
        $this->assertSame(['configured' => false, 'status' => ['value' => 'pending', 'label' => 'Pending', 'tone' => 'warning'], 'reasons' => [], 'pending' => []],
            $this->homeProps($this->candidateInA->user)['performance']);

        $this->buildPerformanceRecords();

        $card = $this->homeProps($this->secondInA->user)['performance'];
        $this->assertTrue($card['configured']);
        $this->assertSame('not_qualified', $card['status']['value']);
        $this->assertSame(['Academic requirement not met', 'Attendance requirement not met'], $card['reasons']);
        $this->assertStringNotContainsString('"rank"', (string) json_encode($this->homeProps($this->candidateInA->user)));
    }

    // ------------------------------------------------------------------
    // Administrator dashboard
    // ------------------------------------------------------------------

    public function test_the_dashboard_summarizes_qualification_of_the_active_period_for_performance_viewers(): void
    {
        $this->buildPerformanceRecords();

        $props = $this->dashboardProps($this->academicAdmin);
        $this->assertTrue($props['showQualification']);
        $this->assertTrue($props['canConfigurePerformance']);
        $overview = $props['qualificationOverview'];
        $this->assertSame(['id' => $this->activePeriod->id, 'name' => 'Period Current'], $overview['period']);
        $this->assertTrue($overview['configured']);
        $this->assertSame(2, $overview['classCount']);
        // A1 qualified, A2 not qualified, B1 pending; A3 is withdrawn and left out.
        $this->assertSame(['total' => 3, 'qualified' => 1, 'notQualified' => 1, 'pending' => 1], $overview['counts']);
        // Academic and Attendance are each failed once: the first in area order.
        $this->assertSame('Academic', $overview['mostCommonUnmet']['name']);
        $this->assertSame(1, $overview['mostCommonUnmet']['count']);

        $instructor = $this->dashboardProps($this->alpha);
        $this->assertFalse($instructor['showQualification']);
        $this->assertNull($instructor['qualificationOverview']);
    }

    public function test_the_dashboard_panel_without_areas_or_without_an_active_period(): void
    {
        $overview = $this->dashboardProps($this->superAdmin)['qualificationOverview'];
        $this->assertFalse($overview['configured']);
        $this->assertSame(['total' => 0, 'qualified' => 0, 'notQualified' => 0, 'pending' => 0], $overview['counts']);
        $this->assertNull($overview['mostCommonUnmet']);

        AcademicPeriod::query()->update(['is_active' => false]);
        $props = $this->dashboardProps($this->superAdmin);
        $this->assertTrue($props['showQualification']);
        $this->assertNull($props['qualificationOverview']);
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    private function profileProps(User $viewer, Candidate $candidate): array
    {
        return $this->inertia($this->actingAs($viewer)->get("/candidates/{$candidate->id}"), 'staff/candidates/show');
    }

    /**
     * @return array<string, mixed>
     */
    private function homeProps(User $user): array
    {
        return $this->inertia($this->actingAs($user)->get('/portal'), 'portal/home');
    }

    /**
     * @return array<string, mixed>
     */
    private function dashboardProps(User $user): array
    {
        return $this->inertia($this->actingAs($user)->get('/dashboard'), 'staff/dashboard');
    }

    /**
     * @return array<string, mixed>
     */
    private function inertia(TestResponse $response, string $component): array
    {
        return $response->assertOk()->assertInertia(fn (Assert $page) => $page->component($component))->inertiaProps();
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
