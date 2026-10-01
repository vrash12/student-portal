<?php

namespace Tests\Feature\Portal;

use App\Enums\SystemRole;
use App\Models\AcademicPeriod;
use App\Models\Candidate;
use App\Models\ClassBatch;
use App\Models\FitnessTest;
use App\Services\Fitness\FitnessStandardService;
use App\Services\Fitness\FitnessTestService;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Candidate portal "Physical Fitness" (owner request 2026-10-01): the
 * candidate's own fitness tests, scored by the fitness engine, and the Home
 * tile. Never another candidate's results or staff names.
 */
class CandidateFitnessPageTest extends TestCase
{
    private Candidate $own;

    private Candidate $classmate;

    private FitnessTest $test;

    protected function setUp(): void
    {
        parent::setUp();

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

    public function test_staff_cannot_open_the_candidate_fitness_page(): void
    {
        $this->actingAs($this->userWithRole(SystemRole::AcademicAdministrator))->get('/portal/fitness')->assertForbidden();
        $this->actingAs($this->userWithRole(SystemRole::Instructor))->get('/portal/fitness')->assertForbidden();
    }
}
