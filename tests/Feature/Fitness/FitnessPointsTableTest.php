<?php

namespace Tests\Feature\Fitness;

use App\Enums\AuditAction;
use App\Enums\FitnessScoringMethod;
use App\Enums\SystemRole;
use App\Models\AcademicPeriod;
use App\Models\AuditLog;
use App\Models\Candidate;
use App\Models\ClassBatch;
use App\Models\FitnessEvent;
use App\Models\FitnessTest;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Configurable points for push-ups, sit-ups, runs and other events (owner
 * request 2026-10-02): a points table per event, its own passing points,
 * validation of the table, scoring by the server, and tests keeping the
 * table they were created with.
 */
class FitnessPointsTableTest extends TestCase
{
    private User $admin;

    private ClassBatch $classA;

    private Candidate $first;

    private Candidate $second;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->userWithRole(SystemRole::AcademicAdministrator);
        $this->classA = ClassBatch::factory()->for(AcademicPeriod::factory()->active())->create(['name' => 'Class A']);
        $this->first = Candidate::factory()->create(['class_batch_id' => $this->classA->id, 'candidate_number' => 'C-001']);
        $this->second = Candidate::factory()->create(['class_batch_id' => $this->classA->id, 'candidate_number' => 'C-002']);
    }

    public function test_a_points_table_is_stored_in_order_and_audited(): void
    {
        $this->postEvent('Push-ups', 'repetitions', true, [['30', '70'], ['20', '50'], ['25', '60'], ['40', '100']])
            ->assertRedirect('/fitness/standards');

        $event = FitnessEvent::query()->where('name', 'Push-ups')->sole();
        $this->assertSame(FitnessScoringMethod::Table, $event->scoring_method);
        $this->assertSame('60.00', $event->passing_points);
        $this->assertNull($event->passing_value);
        $this->assertEquals([['value' => 20, 'points' => 50], ['value' => 25, 'points' => 60], ['value' => 30, 'points' => 70], ['value' => 40, 'points' => 100]], $event->points_table);

        $audit = AuditLog::query()->where('action', AuditAction::FitnessEventCreated->value)->sole();
        $this->assertSame('20 = 50, 25 = 60, 30 = 70, 40 = 100', $audit->new_values['points_table']);
        $this->assertSame('table', $audit->new_values['scoring_method']);

        $this->actingAs($this->admin)->get('/fitness/standards')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('staff/fitness/standards/index')
                ->where('events.0.method.value', 'table')
                ->where('events.0.passingDisplay', '25')
                ->where('events.0.maximumDisplay', '40')
                ->where('events.0.maximumPoints', 100)
                ->has('events.0.table', 4));
        $this->actingAs($this->admin)->get("/fitness/standards/{$event->id}/edit")->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('staff/fitness/standards/edit')
                ->where('event.table.1.display', '25')
                ->where('event.table.1.points', 60)
                ->where('methodOptions.0.value', 'table'));
    }

    public function test_results_earn_the_points_of_the_best_row_they_reach(): void
    {
        $this->postEvent('Push-ups', 'repetitions', true, [['20', '50'], ['25', '60'], ['30', '70'], ['40', '100']])->assertRedirect();
        // A run: 16:00 or faster passes with 60 points, 13:00 or faster earns 100.
        $this->postEvent('3.2 km Run', 'time', false, [['18:00', '40'], ['16:00', '60'], ['14:30', '80'], ['13:00', '100']], sortOrder: 2)->assertRedirect();
        $test = $this->createTest();
        [$pushUps, $run] = $test->events()->orderBy('position')->pluck('id')->all();

        $this->actingAs($this->admin)->put("/fitness/tests/{$test->id}/results", ['entries' => [
            $this->first->id => [$pushUps => '33', $run => '14:45'],
            $this->second->id => [$pushUps => '24', $run => '12:10'],
        ]])->assertRedirect();

        $this->actingAs($this->admin)->get("/fitness/tests/{$test->id}")->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('events.0.method', 'table')
                ->where('events.0.passingPoints', 60)
                ->where('events.1.passingDisplay', '16:00')
                // 33 push-ups reach the 30 row (70); 14:45 reaches the 16:00 row (60).
                ->where("rows.0.results.{$pushUps}.points", 70)
                ->where("rows.0.results.{$run}.points", 60)
                ->where('rows.0.outcome.status.value', 'passed')
                ->where('rows.0.outcome.points', 65)
                // 24 push-ups reach only the 20 row (50 points): below the passing points.
                ->where("rows.1.results.{$pushUps}.points", 50)
                ->where("rows.1.results.{$pushUps}.passed", false)
                ->where("rows.1.results.{$run}.points", 100)
                ->where('rows.1.outcome.status.value', 'failed'));
    }

    public function test_changing_the_table_keeps_the_points_of_existing_tests(): void
    {
        $this->postEvent('Push-ups', 'repetitions', true, [['20', '50'], ['25', '60'], ['30', '70']])->assertRedirect();
        $test = $this->createTest();
        $eventId = (int) $test->events()->value('id');
        $this->actingAs($this->admin)->put("/fitness/tests/{$test->id}/results", ['entries' => [$this->first->id => [$eventId => '30']]]);

        $event = FitnessEvent::query()->sole();
        $this->actingAs($this->admin)->put("/fitness/standards/{$event->id}", [
            ...$this->eventPayload('Push-ups', 'repetitions', true, [['30', '50'], ['40', '80']], passingPoints: '70'),
            'is_active' => true,
        ])->assertRedirect('/fitness/standards');

        $this->assertSame('70.00', $event->refresh()->passing_points);
        $this->actingAs($this->admin)->get("/fitness/tests/{$test->id}")->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('events.0.passingPoints', 60)
                ->where("rows.0.results.{$eventId}.points", 70)
                ->where("rows.0.results.{$eventId}.passed", true));
        $this->assertSame(1, AuditLog::query()->where('action', AuditAction::FitnessEventUpdated->value)->count());
    }

    public function test_scaled_standards_can_have_their_own_passing_points(): void
    {
        $this->actingAs($this->admin)->post('/fitness/standards', [
            'name' => 'Pull-ups', 'unit' => 'repetitions', 'higher_is_better' => true, 'sort_order' => 1,
            'scoring_method' => 'scaled', 'passing_points' => '70', 'passing_value' => '10', 'maximum_value' => '20',
        ])->assertRedirect('/fitness/standards');
        $test = $this->createTest();
        $eventId = (int) $test->events()->value('id');
        $this->actingAs($this->admin)->put("/fitness/tests/{$test->id}/results", ['entries' => [$this->first->id => [$eventId => '15'], $this->second->id => [$eventId => '5']]]);

        $this->actingAs($this->admin)->get("/fitness/tests/{$test->id}")->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                // Half way from 10 (70 points) to 20 (100 points); half the passing value earns half of 70.
                ->where("rows.0.results.{$eventId}.points", 85)
                ->where("rows.1.results.{$eventId}.points", 35)
                ->where("rows.1.results.{$eventId}.passed", false));

        // The maximum standard earns 100, so a scaled event cannot need 100 to pass.
        $this->actingAs($this->admin)->post('/fitness/standards', [
            'name' => 'Dips', 'unit' => 'repetitions', 'higher_is_better' => true, 'sort_order' => 2,
            'scoring_method' => 'scaled', 'passing_points' => '100', 'passing_value' => '10', 'maximum_value' => '20',
        ])->assertSessionHasErrors('passing_points');
    }

    public function test_invalid_points_tables_are_rejected_with_the_row_in_question(): void
    {
        $this->postEvent('A', 'repetitions', true, [['20', '50'], ['20', '60']])
            ->assertSessionHasErrors(['points_table.1.value' => 'This result is already in row 1. List each result once.']);
        $this->postEvent('B', 'repetitions', true, [['20', '60'], ['30', '55']])
            ->assertSessionHasErrors(['points_table.1.points' => 'A better result cannot earn fewer points: 30 would earn less than 20.']);
        // Faster times are better: 13:00 cannot earn less than 15:00.
        $this->postEvent('C', 'time', false, [['15:00', '80'], ['13:00', '70']])
            ->assertSessionHasErrors(['points_table.1.points' => 'A better result cannot earn fewer points: 13:00 would earn less than 15:00.']);
        $this->postEvent('D', 'repetitions', true, [['20', '40'], ['30', '50']])
            ->assertSessionHasErrors(['passing_points' => 'No row of the points table reaches the passing points. Lower the passing points or add a row that reaches them.']);
        $this->postEvent('E', 'repetitions', true, [['20', '120']])->assertSessionHasErrors(['points_table.0.points' => 'Points cannot be more than 100.']);
        $this->postEvent('F', 'repetitions', true, [['20', 'sixty']])->assertSessionHasErrors('points_table.0.points');
        $this->postEvent('G', 'time', false, [['13 minutes', '60']])->assertSessionHasErrors(['points_table.0.value' => 'Enter a time such as 12:30 (minutes:seconds).']);
        $this->postEvent('H', 'repetitions', true, [])->assertSessionHasErrors(['points_table' => 'Add at least one row to the points table.']);
        $this->postEvent('I', 'repetitions', true, [['20', '60']], passingPoints: '0')->assertSessionHasErrors(['passing_points' => 'Enter passing points from 1 to 100.']);
        $this->actingAs($this->admin)->post('/fitness/standards', [
            ...$this->eventPayload('J', 'repetitions', true, []),
            'points_table' => [['value' => ['nested'], 'points' => '60']],
        ])->assertSessionHasErrors('points_table.0.value');

        $this->assertSame(0, FitnessEvent::query()->count());
    }

    public function test_the_database_requires_a_complete_standard(): void
    {
        $this->expectException(QueryException::class);

        DB::table('fitness_events')->insert([
            'name' => 'Broken', 'unit' => 'repetitions', 'higher_is_better' => true, 'scoring_method' => 'table',
            'passing_points' => 60, 'passing_value' => null, 'maximum_value' => null, 'points_table' => null,
            'sort_order' => 1, 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /**
     * @param  list<array{0: string, 1: string}>  $rows
     */
    private function postEvent(string $name, string $unit, bool $higherIsBetter, array $rows, string $passingPoints = '60', int $sortOrder = 1): TestResponse
    {
        return $this->actingAs($this->admin)->post('/fitness/standards', $this->eventPayload($name, $unit, $higherIsBetter, $rows, $passingPoints, $sortOrder));
    }

    /**
     * @param  list<array{0: string, 1: string}>  $rows
     * @return array<string, mixed>
     */
    private function eventPayload(string $name, string $unit, bool $higherIsBetter, array $rows, string $passingPoints = '60', int $sortOrder = 1): array
    {
        return [
            'name' => $name, 'unit' => $unit, 'higher_is_better' => $higherIsBetter, 'sort_order' => $sortOrder,
            'scoring_method' => 'table', 'passing_points' => $passingPoints,
            'points_table' => array_map(fn (array $row): array => ['value' => $row[0], 'points' => $row[1]], $rows),
        ];
    }

    private function createTest(): FitnessTest
    {
        $this->actingAs($this->admin)->post('/fitness/tests', [
            'class_batch_id' => $this->classA->id,
            'title' => 'Fitness Test 1',
            'tested_on' => '2026-09-15',
            'event_ids' => FitnessEvent::query()->pluck('id')->all(),
        ])->assertRedirect();

        return FitnessTest::query()->where('title', 'Fitness Test 1')->sole();
    }
}
