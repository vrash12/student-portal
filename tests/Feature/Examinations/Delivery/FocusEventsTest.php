<?php

namespace Tests\Feature\Examinations\Delivery;

use App\Models\Candidate;
use App\Models\Examination;
use App\Models\ExaminationAttempt;
use App\Models\ExaminationFocusEvent;
use App\Services\Examinations\ExaminationFocusService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Leaving the examination screen during an attempt (tab/app switch, another
 * window focused) is recorded with server times for the instructor, and
 * never changes answers or scores.
 */
class FocusEventsTest extends TestCase
{
    use BuildsDeliveryFixtures;

    private Examination $exam;

    private ExaminationAttempt $attempt;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildDeliveryFixtures();
        $this->exam = $this->makeExamination();
        $this->addItem($this->exam, $this->mcq());
        $this->addItem($this->exam, $this->essay());
        $this->attempt = $this->startFor($this->candidateInA, $this->exam);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function report(array $data, ?Candidate $as = null, ?ExaminationAttempt $attempt = null): TestResponse
    {
        return $this->actingAs(($as ?? $this->candidateInA)->user)
            ->postJson('/portal/attempts/'.($attempt ?? $this->attempt)->id.'/focus', $data);
    }

    private function leave(string $reason = 'hidden', int $delayMs = 0): TestResponse
    {
        return $this->report(['event' => 'left', 'reason' => $reason, 'delay_ms' => $delayMs]);
    }

    private function back(string $reason = 'hidden', int $delayMs = 0): TestResponse
    {
        return $this->report(['event' => 'returned', 'reason' => $reason, 'delay_ms' => $delayMs]);
    }

    public function test_a_departure_and_return_are_recorded_with_server_times(): void
    {
        $leftAt = now()->copy();
        $this->leave()->assertOk()->assertJson(['recorded' => true, 'status' => 'in_progress']);

        $this->travel(42)->seconds();
        $this->back()->assertOk()->assertJson(['recorded' => true]);

        $event = ExaminationFocusEvent::query()->sole();
        $this->assertSame($this->attempt->id, $event->examination_attempt_id);
        $this->assertSame('hidden', $event->reason);
        $this->assertSame($leftAt->getTimestamp(), $event->left_at->getTimestamp());
        $this->assertSame(42, (int) $event->left_at->diffInSeconds($event->returned_at));
    }

    public function test_repeated_left_reports_while_away_create_one_departure(): void
    {
        $this->leave('blur')->assertJson(['recorded' => true]);
        $this->leave('hidden')->assertJson(['recorded' => false]);
        $this->back()->assertJson(['recorded' => true]);
        $this->back()->assertJson(['recorded' => false]);

        $this->assertSame(1, ExaminationFocusEvent::query()->count());
        $this->assertSame('blur', ExaminationFocusEvent::query()->sole()->reason);
    }

    public function test_a_return_without_a_departure_records_nothing(): void
    {
        $this->back()->assertOk()->assertJson(['recorded' => false]);
        $this->assertSame(0, ExaminationFocusEvent::query()->count());
    }

    public function test_a_delayed_report_is_placed_back_in_time_but_never_before_the_attempt_or_previous_event(): void
    {
        $this->travel(5)->minutes();
        $this->leave('hidden', 60_000);
        $event = ExaminationFocusEvent::query()->sole();
        $this->assertSame(now()->subMinute()->getTimestamp(), $event->left_at->getTimestamp());

        $this->back();
        // A huge delay is bounded by the previous event and the attempt start.
        $this->leave('hidden', 86_000_000);
        $second = ExaminationFocusEvent::query()->latest('id')->first();
        $this->assertGreaterThanOrEqual($event->fresh()->returned_at->getTimestamp(), $second->left_at->getTimestamp());
        $this->assertGreaterThanOrEqual($this->attempt->started_at->getTimestamp(), $second->left_at->getTimestamp());
    }

    public function test_reports_are_validated(): void
    {
        $this->report(['event' => 'teleported', 'reason' => 'hidden'])->assertUnprocessable()->assertJsonValidationErrors('event');
        $this->report(['event' => 'left', 'reason' => 'webcam'])->assertUnprocessable()->assertJsonValidationErrors('reason');
        $this->report(['event' => 'left'])->assertUnprocessable()->assertJsonValidationErrors('reason');
        $this->report(['event' => 'left', 'reason' => 'hidden', 'delay_ms' => -5])->assertUnprocessable()->assertJsonValidationErrors('delay_ms');
        $this->report(['event' => ['left'], 'reason' => 'hidden'])->assertUnprocessable();

        $this->assertSame(0, ExaminationFocusEvent::query()->count());
    }

    public function test_only_the_attempt_owner_can_report(): void
    {
        $other = Candidate::factory()->create(['class_batch_id' => $this->batchA->id]);

        $this->report(['event' => 'left', 'reason' => 'hidden'], $other)->assertForbidden();
        $this->actingAs($this->alpha)->postJson('/portal/attempts/'.$this->attempt->id.'/focus', ['event' => 'left', 'reason' => 'hidden'])->assertForbidden();
        auth()->logout();
        $this->postJson('/portal/attempts/'.$this->attempt->id.'/focus', ['event' => 'left', 'reason' => 'hidden'])->assertUnauthorized();

        $this->assertSame(0, ExaminationFocusEvent::query()->count());
    }

    public function test_nothing_is_recorded_after_the_attempt_ends(): void
    {
        $this->actingAs($this->candidateInA->user)
            ->post('/portal/attempts/'.$this->attempt->id.'/submit', ['position' => 0, 'revision' => $this->attempt->fresh()->revision, 'answer' => null])
            ->assertRedirect();

        $this->leave()->assertOk()->assertJson(['recorded' => false, 'status' => 'submitted']);
        $this->assertSame(0, ExaminationFocusEvent::query()->count());
    }

    public function test_reporting_does_not_change_answers_revision_or_scores(): void
    {
        $this->actingAs($this->candidateInA->user);
        $this->saveAnswer($this->attempt, 0, null)->assertOk();
        $before = $this->attempt->fresh();

        $this->leave();
        $this->travel(10)->seconds();
        $this->back();

        $after = $this->attempt->fresh();
        $this->assertSame($before->revision, $after->revision);
        $this->assertSame($before->answers, $after->answers);
        $this->assertSame($before->status, $after->status);
    }

    public function test_departures_per_attempt_are_capped(): void
    {
        $rows = [];
        for ($i = 0; $i < ExaminationFocusService::MAX_EVENTS_PER_ATTEMPT; $i++) {
            $rows[] = ['examination_attempt_id' => $this->attempt->id, 'reason' => 'blur', 'left_at' => now()->subSeconds(1000 - $i), 'returned_at' => now()->subSeconds(1000 - $i)];
        }
        ExaminationFocusEvent::query()->insert($rows);

        $this->leave()->assertJson(['recorded' => false]);
        $this->assertSame(ExaminationFocusService::MAX_EVENTS_PER_ATTEMPT, ExaminationFocusEvent::query()->count());
    }

    public function test_the_instructor_sees_departures_in_live_monitoring_and_the_candidate_is_marked_away(): void
    {
        $this->leave();
        $this->travel(30)->seconds();
        $this->back();
        $this->travel(5)->seconds();
        $this->leave('blur');
        $this->travel(20)->seconds();

        $props = $this->inertiaProps($this->actingAs($this->alpha)->get('/examinations/'.$this->exam->id)->assertOk());
        $row = collect($props['monitoring']['candidates'])->firstWhere('candidate.id', $this->candidateInA->id);

        $this->assertSame(2, $row['focus']['count']);
        $this->assertSame(50, $row['focus']['awaySeconds']);
        $this->assertNotNull($row['focus']['awaySince']);
        $this->assertSame(1, $props['monitoring']['totals']['leftScreen']);

        $untouched = collect($props['monitoring']['candidates'])->firstWhere('candidate.id', '!=', $this->candidateInA->id);
        $this->assertNull($untouched['focus']);
    }

    public function test_an_open_departure_counts_until_the_attempt_ends_and_is_listed_on_the_grading_page(): void
    {
        $this->leave();
        $this->travel(15)->seconds();
        $this->actingAs($this->candidateInA->user)
            ->post('/portal/attempts/'.$this->attempt->id.'/submit', ['position' => 0, 'revision' => $this->attempt->fresh()->revision, 'answer' => null]);
        $this->travel(10)->minutes();

        $summary = app(ExaminationFocusService::class)->summaries([$this->attempt->fresh()])[$this->attempt->id];
        $this->assertSame(15, $summary['awaySeconds']);
        $this->assertNull($summary['awaySince']);

        $events = $this->inertiaProps($this->actingAs($this->alpha)->get('/examination-attempts/'.$this->attempt->id.'/grading')->assertOk())['focusEvents'];
        $this->assertCount(1, $events);
        $this->assertNull($events[0]['returnedAt']);
        $this->assertSame(15, $events[0]['seconds']);

        $this->actingAs($this->bravo)->get('/examination-attempts/'.$this->attempt->id.'/grading')->assertForbidden();
    }

    public function test_the_database_rejects_invalid_reasons(): void
    {
        $this->expectException(QueryException::class);
        DB::table('examination_focus_events')->insert(['examination_attempt_id' => $this->attempt->id, 'reason' => 'camera', 'left_at' => now()]);
    }

    public function test_saving_the_return_keeps_the_departure_time(): void
    {
        $this->leave();
        $leftAt = ExaminationFocusEvent::query()->sole()->left_at;
        $this->travel(3)->minutes();
        $this->back();

        $this->assertSame($leftAt->getTimestamp(), ExaminationFocusEvent::query()->sole()->left_at->getTimestamp());
    }

    public function test_the_database_rejects_a_return_before_the_departure(): void
    {
        $this->expectException(QueryException::class);
        DB::table('examination_focus_events')->insert(['examination_attempt_id' => $this->attempt->id, 'reason' => 'hidden', 'left_at' => now(), 'returned_at' => now()->subMinute()]);
    }
}
