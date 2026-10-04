<?php

namespace Tests\Feature\Campuses;

use App\Enums\AttendanceStatus;
use App\Enums\CampusCode;
use App\Enums\CandidateStatus;
use App\Enums\SystemRole;
use App\Models\AcademicPeriod;
use App\Models\Campus;
use App\Models\Candidate;
use App\Models\ClassBatch;
use App\Models\User;
use App\Services\Attendance\AttendanceService;
use App\Services\Fitness\FitnessStandardService;
use App\Services\Fitness\FitnessTestService;
use Database\Factories\CampusFactory;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Campus analytics (owner request 2026-10-05): the Campuses page compares
 * the campuses in the active academic year and each campus has an Analytics
 * page. Figures come from the existing engines (attendance, fitness,
 * qualification, grades) and only for that campus's classes. Institution-
 * wide administrators only, like the rest of the Campuses section.
 */
class CampusAnalyticsTest extends TestCase
{
    private User $admin;

    private Campus $north;

    private ClassBatch $northClass;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->userWithRole(SystemRole::SuperAdministrator);
        $period = AcademicPeriod::factory()->active()->create();
        $this->north = CampusFactory::fixed(CampusCode::North);
        $south = CampusFactory::fixed(CampusCode::South);
        CampusFactory::fixed(CampusCode::East);
        CampusFactory::fixed(CampusCode::West);

        $this->northClass = ClassBatch::factory()->for($period)->onCampus($this->north)->create(['name' => 'North Class']);
        $southClass = ClassBatch::factory()->for($period)->onCampus($south)->create(['name' => 'South Class']);
        $present = Candidate::factory()->create(['class_batch_id' => $this->northClass->id]);
        $absent = Candidate::factory()->create(['class_batch_id' => $this->northClass->id]);
        Candidate::factory()->create(['class_batch_id' => $this->northClass->id, 'status' => CandidateStatus::Withdrawn]);
        Candidate::factory()->create(['class_batch_id' => $southClass->id]);

        // North: one present, one absent → 50 %; one fitness test with a pass and a fail.
        $attendance = $this->app->make(AttendanceService::class);
        $session = $attendance->create($this->northClass, ['held_on' => $period->starts_on->toDateString(), 'title' => 'Formation', 'hours' => '1', 'notes' => null], $this->admin);
        $attendance->record($session, [
            $present->id => ['status' => AttendanceStatus::Present, 'remarks' => null],
            $absent->id => ['status' => AttendanceStatus::Absent, 'remarks' => null],
        ], $this->admin);

        $pushUps = $this->app->make(FitnessStandardService::class)->create([
            'name' => 'Push-ups', 'description' => null, 'unit' => 'repetitions', 'higher_is_better' => true,
            'passing_value' => 40.0, 'maximum_value' => 60.0, 'sort_order' => 1,
        ]);
        $tests = $this->app->make(FitnessTestService::class);
        $test = $tests->create($this->northClass, ['title' => 'Fitness 1', 'tested_on' => $period->starts_on->toDateString(), 'notes' => null], [$pushUps->id], $this->admin);
        $eventId = (int) $test->events()->value('id');
        $tests->recordResults($test, [$present->id => [$eventId => 50.0], $absent->id => [$eventId => 20.0]], $this->admin);
    }

    public function test_the_campuses_page_compares_each_campus_with_its_own_figures(): void
    {
        $this->actingAs($this->admin)->get('/campuses')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('staff/campuses/index')
                ->where('period.name', AcademicPeriod::query()->active()->value('name'))
                ->where('campuses.0.code', 'SOUTH')
                ->where('campuses.0.figures.candidateCount', 1)
                ->where('campuses.0.figures.attendanceRate', null)
                ->where('campuses.0.figures.fitness.recorded', 0)
                ->where('campuses.1.code', 'NORTH')
                // The withdrawn candidate is not counted.
                ->where('campuses.1.figures.candidateCount', 2)
                ->where('campuses.1.figures.classCount', 1)
                ->where('campuses.1.figures.attendanceRate', 50)
                ->where('campuses.1.figures.attendanceRecords', 2)
                ->where('campuses.1.figures.fitness.tests', 1)
                ->where('campuses.1.figures.fitness.passed', 1)
                ->where('campuses.1.figures.fitness.failed', 1)
                ->where('campuses.1.figures.fitness.passRate', 50)
                ->where('campuses.2.figures.candidateCount', 0));
    }

    public function test_a_campus_analytics_page_shows_only_that_campus(): void
    {
        $this->actingAs($this->admin)->get("/campuses/{$this->north->id}")->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('staff/campuses/show')
                ->where('campus.code', 'NORTH')
                ->has('analytics.classes', 1)
                ->where('analytics.classes.0.name', 'North Class')
                ->where('analytics.classes.0.candidateCount', 2)
                ->where('analytics.classes.0.attendanceRate', 50)
                ->where('analytics.attendanceTotals', ['present' => 1, 'late' => 0, 'excused' => 0, 'absent' => 1])
                ->where('analytics.candidateCount', 2));
    }

    public function test_only_institution_wide_administrators_see_campus_analytics(): void
    {
        $limited = User::factory()->withRole(SystemRole::SuperAdministrator)->onCampus($this->north)->create();
        $this->actingAs($limited)->get("/campuses/{$this->north->id}")->assertForbidden();
        $this->actingAs($this->userWithRole(SystemRole::Instructor))->get("/campuses/{$this->north->id}")->assertForbidden();
        $this->actingAs($this->admin)->get('/campuses/999999')->assertNotFound();
    }

    public function test_without_an_active_year_the_pages_say_so(): void
    {
        AcademicPeriod::query()->update(['is_active' => false]);

        $this->actingAs($this->admin)->get('/campuses')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('period', null)->where('campuses.1.figures.candidateCount', 0));
        $this->actingAs($this->admin)->get("/campuses/{$this->north->id}")->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('period', null)->where('analytics.classes', []));
    }
}
