<?php

namespace Tests\Feature\Portal;

use App\Enums\SystemRole;
use App\Models\AcademicPeriod;
use App\Models\Candidate;
use App\Models\ClassBatch;
use App\Models\FitnessTest;
use App\Services\Fitness\FitnessStandardService;
use App\Services\Fitness\FitnessTestService;
use App\Services\Performance\PerformanceAreaService;
use App\Services\Performance\PortalQualification;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Candidate portal "Physical Fitness" (owner request 2026-10-01): the
 * candidate's own fitness tests, scored by the fitness engine, and the Home
 * tile. Never another candidate's results or staff names. Since 2026-10-02
 * military fitness is staff only by default; the page exists only when
 * institution.portal.show_fitness is on.
 */
class CandidateFitnessPageTest extends TestCase
{
    private Candidate $own;

    private Candidate $classmate;

    private FitnessTest $test;

    protected function setUp(): void
    {
        parent::setUp();
        config(['institution.portal.show_fitness' => true]);

        $admin = $this->userWithRole(SystemRole::AcademicAdministrator, ['name' => 'Officer Recorder']);
        $class = ClassBatch::factory()->for(AcademicPeriod::factory()->active())->create();
        $this->own = Candidate::factory()->create(['class_batch_id' => $class->id, 'candidate_number' => 'C-001']);
        $this->classmate = Candidate::factory()->create(['class_batch_id' => $class->id, 'candidate_number' => 'C-002']);

        $pushUps = app(FitnessStandardService::class)->create([
            'name' => 'Push-ups', 'description' => null, 'unit' => 'repetitions', 'higher_is_better' => true,
            'passing_value' => 40.0, 'maximum_value' => 60.0, 'sort_order' => 1,
        ]);
        $this->test = app(FitnessTestService::class)->create($class, ['title' => 'Diagnostic Test', 'tested_on' => '2026-09-01', 'notes' => null], [$pushUps->id], $admin);
        $eventId = (int) $this->test->events()->value('id');
        app(FitnessTestService::class)->recordResults($this->test, [
            $this->own->id => [$eventId => 50.0],
            $this->classmate->id => [$eventId => 31.0],
        ], $admin);
    }

    public function test_the_candidate_sees_their_own_results_scored_by_the_server(): void
    {
        $response = $this->actingAs($this->own->user)->get('/portal/fitness?candidate_id='.$this->classmate->id)->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('portal/fitness')
                ->where('candidate.number', 'C-001')
                ->has('tests', 1)
                ->where('tests.0.title', 'Diagnostic Test')
                ->where('tests.0.events.0.result.display', '50')
                // 50 of 40/60 = 80 points; every event passed.
                ->where('tests.0.events.0.result.points', 80)
                ->where('tests.0.outcome.status.value', 'passed'));

        $json = $response->getContent();
        $this->assertStringNotContainsString('C-002', $json);
        $this->assertStringNotContainsString('Officer Recorder', $json);
        $this->assertStringNotContainsString('"31"', $json);
    }

    public function test_the_home_tile_shows_the_latest_test(): void
    {
        $this->actingAs($this->classmate->user)->get('/portal')->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('sections.fitness.title', 'Diagnostic Test')
                ->where('sections.fitness.status.value', 'failed'));
    }

    public function test_without_tests_the_page_is_empty(): void
    {
        $unassigned = Candidate::factory()->create();

        $this->actingAs($unassigned->user)->get('/portal/fitness')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('tests', []));
        $this->actingAs($unassigned->user)->get('/portal')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('sections.fitness', null));
    }

    public function test_by_default_candidates_do_not_see_fitness(): void
    {
        config(['institution.portal.show_fitness' => false]);

        $this->actingAs($this->own->user)->get('/portal/fitness')->assertNotFound();
        $response = $this->actingAs($this->own->user)->get('/portal')->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('app.portal.showFitness', false)
                ->where('sections.fitness', null));
        $this->assertStringNotContainsString('Diagnostic Test', $response->getContent());
    }

    public function test_by_default_my_performance_names_the_fitness_area_only_as_a_staff_assessed_requirement(): void
    {
        config(['institution.portal.show_fitness' => false]);
        $areas = app(PerformanceAreaService::class);
        $area = fn (string $name, string $source, int $order, array $extra = []): array => [
            'name' => $name, 'description' => null, 'source' => $source, 'weight' => '50', 'passing_grade' => '60',
            'must_pass' => true, 'base_rating' => null, 'merit_value' => null, 'demerit_value' => null, 'sort_order' => $order,
            'is_active' => true, ...$extra,
        ];
        $areas->create($area('Conduct', 'conduct', 1, ['base_rating' => '85', 'merit_value' => '1', 'demerit_value' => '1']));
        $fitness = $areas->create($area('Physical Fitness', 'fitness', 2));

        // The classmate failed push-ups (31 of 40), so the fitness area fails: Not Qualified.
        $props = $this->actingAs($this->classmate->user)->get('/portal/performance')->assertOk()->inertiaProps();
        $json = (string) json_encode($props);
        $this->assertSame(['Conduct'], array_column($props['areas'], 'name'));
        $this->assertNotContains($fitness->id, array_column($props['result']['areas'], 'areaId'));
        $this->assertSame(1, $props['staffAssessedAreas']);
        $this->assertSame('not_qualified', $props['result']['qualification']['status']['value']);
        $this->assertSame([PortalQualification::STAFF_ASSESSED_REASON], $props['result']['qualification']['reasons']);
        $this->assertStringNotContainsString('Physical Fitness', $json);
        $this->assertStringNotContainsString('Diagnostic Test', $json);

        $home = $this->actingAs($this->classmate->user)->get('/portal')->assertOk()->inertiaProps();
        $this->assertSame([PortalQualification::STAFF_ASSESSED_REASON], $home['performance']['reasons']);
        $this->assertStringNotContainsString('Physical Fitness', (string) json_encode($home));

        // Staff still see the area by name.
        $this->actingAs($this->userWithRole(SystemRole::AcademicAdministrator))->get("/candidates/{$this->classmate->id}")->assertOk()
            ->assertSee('Physical Fitness requirement not met', false);

        // When the institution shows fitness to candidates, the area is listed again.
        config(['institution.portal.show_fitness' => true]);
        $props = $this->actingAs($this->classmate->user)->get('/portal/performance')->assertOk()->inertiaProps();
        $this->assertSame(['Conduct', 'Physical Fitness'], array_column($props['areas'], 'name'));
        $this->assertSame(['Physical Fitness requirement not met'], $props['result']['qualification']['reasons']);
        $this->assertSame(0, $props['staffAssessedAreas']);
    }

    public function test_staff_cannot_open_the_candidate_fitness_page(): void
    {
        $this->actingAs($this->userWithRole(SystemRole::AcademicAdministrator))->get('/portal/fitness')->assertForbidden();
        $this->actingAs($this->userWithRole(SystemRole::Instructor))->get('/portal/fitness')->assertForbidden();
    }
}
