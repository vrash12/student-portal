<?php

namespace Tests\Feature\Fitness;

use App\Enums\AuditAction;
use App\Enums\CandidateStatus;
use App\Enums\SystemRole;
use App\Models\AcademicPeriod;
use App\Models\AuditLog;
use App\Models\Candidate;
use App\Models\ClassBatch;
use App\Models\FitnessEvent;
use App\Models\FitnessResult;
use App\Models\FitnessTest as FitnessTestModel;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Standard military fitness (owner request, 2026-10-01): configurable events
 * and standards, tests per class with a copy of the standards, raw results
 * scored by the server, audited changes, staff-only access. Points tables
 * and instructor access: FitnessPointsTableTest, InstructorFitnessAccessTest.
 */
class FitnessTest extends TestCase
{
    private User $admin;

    private ClassBatch $classA;

    private Candidate $first;

    private Candidate $second;

    private FitnessEvent $pushUps;

    private FitnessEvent $run;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->userWithRole(SystemRole::AcademicAdministrator);
        $this->classA = ClassBatch::factory()->for(AcademicPeriod::factory()->active())->create(['name' => 'Class A']);
        $this->first = Candidate::factory()->create(['class_batch_id' => $this->classA->id, 'candidate_number' => 'C-001']);
        $this->second = Candidate::factory()->create(['class_batch_id' => $this->classA->id, 'candidate_number' => 'C-002']);

        $this->actingAs($this->admin)->post('/fitness/standards', [
            'name' => 'Push-ups', 'unit' => 'repetitions', 'higher_is_better' => true, 'scoring_method' => 'scaled', 'passing_points' => '60',
            'passing_value' => '40', 'maximum_value' => '60', 'sort_order' => 1,
        ])->assertRedirect('/fitness/standards');
        $this->actingAs($this->admin)->post('/fitness/standards', [
            'name' => '3.2 km Run', 'unit' => 'time', 'higher_is_better' => false, 'scoring_method' => 'scaled', 'passing_points' => '60',
            'passing_value' => '15:00', 'maximum_value' => '11:00', 'sort_order' => 2,
        ])->assertRedirect('/fitness/standards');

        $this->pushUps = FitnessEvent::query()->where('name', 'Push-ups')->sole();
        $this->run = FitnessEvent::query()->where('name', '3.2 km Run')->sole();
    }

    public function test_standards_are_stored_in_the_events_unit_and_audited(): void
    {
        $this->assertSame('40.00', $this->pushUps->passing_value);
        $this->assertSame('900.00', $this->run->passing_value);
        $this->assertSame('660.00', $this->run->maximum_value);
        $this->assertFalse($this->run->higher_is_better);
        $this->assertSame(2, AuditLog::query()->where('action', AuditAction::FitnessEventCreated->value)->count());

        $this->actingAs($this->admin)->get('/fitness/standards')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('staff/fitness/standards/index')
                ->where('events.1.passingDisplay', '15:00')
                ->where('events.1.maximumDisplay', '11:00'));
    }

    public function test_the_maximum_standard_must_be_better_than_the_passing_standard(): void
    {
        $this->actingAs($this->admin)->post('/fitness/standards', [
            'name' => 'Sit-ups', 'unit' => 'repetitions', 'higher_is_better' => true, 'scoring_method' => 'scaled', 'passing_points' => '60',
            'passing_value' => '50', 'maximum_value' => '40', 'sort_order' => 3,
        ])->assertSessionHasErrors(['maximum_value' => 'When higher results are better, the maximum standard must be higher than the passing standard.']);

        $this->actingAs($this->admin)->post('/fitness/standards', [
            'name' => 'Swim', 'unit' => 'time', 'higher_is_better' => false, 'scoring_method' => 'scaled', 'passing_points' => '60',
            'passing_value' => '5:75', 'maximum_value' => '4:00', 'sort_order' => 3,
        ])->assertSessionHasErrors('passing_value');

        $this->assertSame(2, FitnessEvent::query()->count());
    }

    public function test_results_are_scored_by_the_server_and_tests_keep_their_standards(): void
    {
        $test = $this->createTest();

        $this->actingAs($this->admin)->put("/fitness/tests/{$test->id}/results", ['entries' => [
            $this->first->id => [$this->eventIdIn($test, $this->pushUps) => '50', $this->eventIdIn($test, $this->run) => '13:00'],
            $this->second->id => [$this->eventIdIn($test, $this->pushUps) => '30'],
        ]])->assertRedirect("/fitness/tests/{$test->id}")->assertInertiaFlash('toast.message', '3 results saved.');

        // A later change to the standard does not alter the test.
        $this->actingAs($this->admin)->put("/fitness/standards/{$this->pushUps->id}", [
            'name' => 'Push-ups', 'unit' => 'repetitions', 'higher_is_better' => true, 'scoring_method' => 'scaled', 'passing_points' => '60',
            'passing_value' => '55', 'maximum_value' => '70', 'sort_order' => 1, 'is_active' => true,
        ])->assertRedirect();

        $this->actingAs($this->admin)->get("/fitness/tests/{$test->id}")->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('staff/fitness/tests/show')
                ->has('rows', 2)
                ->where('rows.0.candidate.candidateNumber', 'C-001')
                // Push-ups 50 of 40/60 = 80 points; run 13:00 of 15:00/11:00 = 80 points.
                ->where('rows.0.results.'.$this->eventIdIn($test, $this->pushUps).'.points', 80)
                ->where('rows.0.results.'.$this->eventIdIn($test, $this->run).'.display', '13:00')
                ->where('rows.0.outcome.status.value', 'passed')
                ->where('rows.0.outcome.points', 80)
                // 30 push-ups fail the test even before the run is recorded.
                ->where('rows.1.outcome.status.value', 'failed')
                ->where('rows.1.outcome.points', null)
                ->where('summary.counts.passed', 1)
                ->where('summary.counts.failed', 1)
                ->where('events.0.passingDisplay', '40'));
    }

    public function test_result_changes_are_audited_with_previous_and_new_values(): void
    {
        $test = $this->createTest();
        $eventId = $this->eventIdIn($test, $this->run);

        $this->actingAs($this->admin)->put("/fitness/tests/{$test->id}/results", ['entries' => [$this->first->id => [$eventId => '14:10']]]);
        $this->actingAs($this->admin)->put("/fitness/tests/{$test->id}/results", ['entries' => [$this->first->id => [$eventId => '13:45']]]);
        // Clearing a value removes the result.
        $this->actingAs($this->admin)->put("/fitness/tests/{$test->id}/results", ['entries' => [$this->first->id => [$eventId => '']]]);

        $entries = AuditLog::query()->where('action', AuditAction::FitnessResultsRecorded->value)->orderBy('id')->get();
        $this->assertCount(3, $entries);
        $this->assertSame(['C-001 · 3.2 km Run' => '14:10'], $entries[1]->old_values);
        $this->assertSame(['C-001 · 3.2 km Run' => '13:45'], $entries[1]->new_values);
        $this->assertSame(['C-001 · 3.2 km Run' => null], $entries[2]->new_values);
        $this->assertSame(0, FitnessResult::query()->count());
    }

    public function test_invalid_values_and_candidates_outside_the_class_are_rejected(): void
    {
        $test = $this->createTest();
        $runId = $this->eventIdIn($test, $this->run);

        $this->actingAs($this->admin)->put("/fitness/tests/{$test->id}/results", ['entries' => [$this->first->id => [$runId => '13 minutes']]])
            ->assertSessionHasErrors(["entries.{$this->first->id}.{$runId}" => 'Enter 3.2 km Run as minutes:seconds, such as 12:30.']);

        $outsider = Candidate::factory()->create(['class_batch_id' => ClassBatch::factory()->create()->id]);
        $this->actingAs($this->admin)->put("/fitness/tests/{$test->id}/results", ['entries' => [$outsider->id => [$runId => '13:00']]])
            ->assertSessionHasErrors('entries');

        $this->second->status = CandidateStatus::Withdrawn;
        $this->second->save();
        $this->actingAs($this->admin)->put("/fitness/tests/{$test->id}/results", ['entries' => [$this->second->id => [$runId => '13:00']]])
            ->assertSessionHasErrors('entries');

        $this->assertSame(0, FitnessResult::query()->count());
    }

    public function test_only_tests_without_results_can_be_deleted(): void
    {
        $test = $this->createTest();
        $this->actingAs($this->admin)->put("/fitness/tests/{$test->id}/results", ['entries' => [$this->first->id => [$this->eventIdIn($test, $this->pushUps) => '45']]]);

        $this->actingAs($this->admin)->delete("/fitness/tests/{$test->id}")->assertSessionHasErrors('test');
        $this->assertModelExists($test);

        $empty = $this->createTest('Fitness Test 2');
        $this->actingAs($this->admin)->delete("/fitness/tests/{$empty->id}")->assertRedirect('/fitness');
        $this->assertModelMissing($empty);
        $this->assertDatabaseHas('audit_logs', ['action' => AuditAction::FitnessTestDeleted->value, 'auditable_id' => $empty->id]);
    }

    public function test_the_list_summarizes_each_test(): void
    {
        $test = $this->createTest();
        $this->actingAs($this->admin)->put("/fitness/tests/{$test->id}/results", ['entries' => [
            $this->first->id => [$this->eventIdIn($test, $this->pushUps) => '45', $this->eventIdIn($test, $this->run) => '14:00'],
        ]]);

        $this->actingAs($this->admin)->get('/fitness')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('staff/fitness/index')
                ->has('tests.data', 1)
                ->where('tests.data.0.rosterCount', 2)
                ->where('tests.data.0.summary', ['passed' => 1, 'failed' => 0, 'incomplete' => 0, 'recorded' => 1]));
    }

    public function test_candidate_profiles_show_fitness_to_staff_who_may_view_it(): void
    {
        $test = $this->createTest();
        $this->actingAs($this->admin)->put("/fitness/tests/{$test->id}/results", ['entries' => [$this->first->id => [$this->eventIdIn($test, $this->pushUps) => '45']]]);

        $this->actingAs($this->admin)->get("/candidates/{$this->first->id}")->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('fitness.0.title', 'Fitness Test 1')
                ->where('fitness.0.events.0.result.display', '45')
                ->where('fitness.0.outcome.status.value', 'incomplete'));
    }

    public function test_candidates_and_instructors_of_other_classes_cannot_see_or_record_fitness(): void
    {
        $test = $this->createTest();

        $this->actingAs($this->first->user)->get('/fitness')->assertForbidden();
        // An instructor who does not teach Class A sees an empty list and nothing of its tests.
        $this->actingAs($this->userWithRole(SystemRole::Instructor))->get('/fitness')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('tests.data', 0)->where('can.manage', false));

        foreach ([$this->userWithRole(SystemRole::Instructor), $this->first->user] as $user) {
            $this->actingAs($user)->get("/fitness/tests/{$test->id}")->assertForbidden();
            $this->actingAs($user)->put("/fitness/tests/{$test->id}/results", ['entries' => [$this->first->id => [$this->eventIdIn($test, $this->pushUps) => '45']]])->assertForbidden();
        }
        $this->actingAs($this->first->user)->post('/fitness/standards', ['name' => 'X'])->assertForbidden();

        $this->assertSame(0, FitnessResult::query()->count());
    }

    private function createTest(string $title = 'Fitness Test 1'): FitnessTestModel
    {
        $this->actingAs($this->admin)->post('/fitness/tests', [
            'class_batch_id' => $this->classA->id,
            'title' => $title,
            'tested_on' => '2026-09-15',
            'event_ids' => [$this->pushUps->id, $this->run->id],
        ])->assertRedirect();

        return FitnessTestModel::query()->where('title', $title)->sole();
    }

    private function eventIdIn(FitnessTestModel $test, FitnessEvent $event): int
    {
        return (int) $test->events()->where('fitness_event_id', $event->id)->value('id');
    }
}
