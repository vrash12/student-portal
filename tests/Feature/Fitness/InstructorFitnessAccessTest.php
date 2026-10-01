<?php

namespace Tests\Feature\Fitness;

use App\Enums\Permission;
use App\Enums\SystemRole;
use App\Models\AcademicPeriod;
use App\Models\Candidate;
use App\Models\ClassBatch;
use App\Models\FitnessEvent;
use App\Models\FitnessResult;
use App\Models\FitnessTest;
use App\Models\Subject;
use App\Models\User;
use App\Services\ClassBatchService;
use App\Services\Fitness\FitnessScope;
use App\Services\Fitness\FitnessStandardService;
use App\Services\Fitness\FitnessTestService;
use App\Services\InstructorAssignmentService;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Military fitness for instructors (owner request 2026-10-02): instructors
 * see, create and record the fitness tests of the classes they teach and
 * adjust the events and their points; the tests of other classes stay
 * forbidden even when their URL is known.
 */
class InstructorFitnessAccessTest extends TestCase
{
    private User $admin;

    private User $alpha;

    private ClassBatch $classA;

    private ClassBatch $classB;

    private Candidate $first;

    private Candidate $other;

    private FitnessEvent $pushUps;

    private FitnessTest $testA;

    private FitnessTest $testB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->userWithRole(SystemRole::AcademicAdministrator);
        $period = AcademicPeriod::factory()->active()->create();
        $this->classA = ClassBatch::factory()->for($period)->create(['name' => 'Class A']);
        $this->classB = ClassBatch::factory()->for($period)->create(['name' => 'Class B']);
        $this->first = Candidate::factory()->create(['class_batch_id' => $this->classA->id, 'candidate_number' => 'C-001']);
        $this->other = Candidate::factory()->create(['class_batch_id' => $this->classB->id, 'candidate_number' => 'C-101']);

        // Instructor Alpha teaches Class A only.
        $this->alpha = $this->userWithRole(SystemRole::Instructor, ['name' => 'Instructor Alpha']);
        $offering = $this->app->make(ClassBatchService::class)->addSubject($this->classA, Subject::factory()->create());
        $this->app->make(InstructorAssignmentService::class)->assign($offering, $this->alpha);

        $this->pushUps = $this->app->make(FitnessStandardService::class)->create([
            'name' => 'Push-ups', 'description' => null, 'unit' => 'repetitions', 'higher_is_better' => true,
            'passing_value' => 40.0, 'maximum_value' => 60.0, 'sort_order' => 1,
        ]);
        $tests = $this->app->make(FitnessTestService::class);
        $this->testA = $tests->create($this->classA, ['title' => 'Class A Test', 'tested_on' => '2026-09-15', 'notes' => null], [$this->pushUps->id], $this->admin);
        $this->testB = $tests->create($this->classB, ['title' => 'Class B Test', 'tested_on' => '2026-09-15', 'notes' => null], [$this->pushUps->id], $this->admin);
    }

    public function test_instructors_receive_military_fitness_by_default(): void
    {
        foreach ([Permission::ViewFitness, Permission::ManageFitness, Permission::ConfigureFitness] as $permission) {
            $this->assertContains($permission, SystemRole::Instructor->defaultPermissions());
            $this->assertTrue($this->alpha->hasPermission($permission));
        }
        $this->assertNotContains(Permission::ViewFitness, SystemRole::Candidate->defaultPermissions());
    }

    public function test_the_list_shows_only_the_classes_the_instructor_teaches(): void
    {
        $this->actingAs($this->alpha)->get('/fitness')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('staff/fitness/index')
                ->has('tests.data', 1)
                ->where('tests.data.0.title', 'Class A Test')
                ->has('classes', 1)
                ->where('classes.0.name', 'Class A')
                ->where('scope', 'taught')
                ->where('can.manage', true)
                ->where('can.configure', true));

        $this->actingAs($this->admin)->get('/fitness')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('tests.data', 2)->where('scope', 'all'));
    }

    public function test_an_instructor_records_results_for_their_class_only(): void
    {
        $eventA = (int) $this->testA->events()->value('id');
        $eventB = (int) $this->testB->events()->value('id');

        $this->actingAs($this->alpha)->get("/fitness/tests/{$this->testA->id}")->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('can.manage', true)->where('can.viewCandidates', true));
        $this->actingAs($this->alpha)->put("/fitness/tests/{$this->testA->id}/results", ['entries' => [$this->first->id => [$eventA => '45']]])
            ->assertRedirect("/fitness/tests/{$this->testA->id}");

        $this->actingAs($this->alpha)->get("/fitness/tests/{$this->testB->id}")->assertForbidden();
        $this->actingAs($this->alpha)->get("/fitness/tests/{$this->testB->id}/edit")->assertForbidden();
        $this->actingAs($this->alpha)->put("/fitness/tests/{$this->testB->id}", ['title' => 'Renamed', 'tested_on' => '2026-09-16'])->assertForbidden();
        $this->actingAs($this->alpha)->delete("/fitness/tests/{$this->testB->id}")->assertForbidden();
        // Forbidden before validation, so nothing about the other class is revealed.
        $this->actingAs($this->alpha)->put("/fitness/tests/{$this->testB->id}/results", ['entries' => [$this->other->id => [$eventB => 'not a number']]])->assertForbidden();

        $this->assertSame([$this->first->id], FitnessResult::query()->pluck('candidate_id')->all());
        $this->assertModelExists($this->testB);
    }

    public function test_an_instructor_creates_tests_for_their_class_only(): void
    {
        $this->actingAs($this->alpha)->get('/fitness/tests/create?class='.$this->classB->id)->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('staff/fitness/tests/create')
                ->has('classOptions', 1)
                ->has('classOptions.0.classes', 1)
                ->where('classOptions.0.classes.0.id', $this->classA->id)
                // Class B is not offered, so it is not pre-selected either.
                ->where('selectedClassId', null)
                ->where('scope', 'taught'));

        $payload = ['title' => 'Fitness Test 2', 'tested_on' => '2026-10-01', 'event_ids' => [$this->pushUps->id]];
        $this->actingAs($this->alpha)->post('/fitness/tests', [...$payload, 'class_batch_id' => $this->classB->id])->assertForbidden();
        $this->actingAs($this->alpha)->post('/fitness/tests', [...$payload, 'class_batch_id' => $this->classA->id])->assertRedirect();

        $this->assertSame(1, FitnessTest::query()->where('title', 'Fitness Test 2')->where('class_batch_id', $this->classA->id)->count());
        $this->assertSame(0, FitnessTest::query()->where('title', 'Fitness Test 2')->where('class_batch_id', $this->classB->id)->count());
    }

    public function test_an_instructor_without_classes_sees_nothing_and_cannot_create_tests(): void
    {
        $unassigned = $this->userWithRole(SystemRole::Instructor);

        $this->actingAs($unassigned)->get('/fitness')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('tests.data', 0)->has('periods', 0)->where('can.manage', false));
        $this->actingAs($unassigned)->post('/fitness/tests', [
            'class_batch_id' => $this->classA->id, 'title' => 'X', 'tested_on' => '2026-10-01', 'event_ids' => [$this->pushUps->id],
        ])->assertForbidden();
        $this->assertFalse(FitnessScope::for($unassigned)->classes()->exists());
    }

    public function test_an_instructor_adjusts_the_events_and_their_points(): void
    {
        $this->actingAs($this->alpha)->get('/fitness/standards')->assertOk();
        $this->actingAs($this->alpha)->post('/fitness/standards', [
            'name' => 'Sit-ups', 'unit' => 'repetitions', 'higher_is_better' => true, 'sort_order' => 2,
            'scoring_method' => 'table', 'passing_points' => '60',
            'points_table' => [['value' => '30', 'points' => '60'], ['value' => '40', 'points' => '80'], ['value' => '50', 'points' => '100']],
        ])->assertRedirect('/fitness/standards');

        $this->assertDatabaseHas('fitness_events', ['name' => 'Sit-ups', 'scoring_method' => 'table']);
    }

    public function test_the_candidate_profile_shows_fitness_to_the_class_instructor(): void
    {
        $this->actingAs($this->alpha)->get("/candidates/{$this->first->id}")->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('fitness.0.title', 'Class A Test'));
        $this->actingAs($this->alpha)->get("/candidates/{$this->other->id}")->assertForbidden();
    }
}
