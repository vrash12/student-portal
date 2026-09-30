<?php

namespace Tests\Feature\Examinations\Delivery;

use App\Models\Examination;
use App\Models\ExaminationQuestion;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Tests\TestCase;

/**
 * `php artisan examinations:expire` reconciles overdue attempts even when
 * the candidate's tablet never contacts the server again.
 */
class ExpiryCommandTest extends TestCase
{
    use BuildsDeliveryFixtures;

    private Examination $autoSubmit;

    private Examination $manualOnly;

    private ExaminationQuestion $autoItem;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildDeliveryFixtures();
        $this->autoSubmit = $this->makeExamination(['passing_score' => '50.00']);
        $this->autoItem = $this->addItem($this->autoSubmit, $this->mcq(3), '2.00');
        $this->addItem($this->autoSubmit, $this->essay(), '1.00');
        $this->manualOnly = $this->makeExamination(['auto_submit' => false]);
        $this->addItem($this->manualOnly, $this->mcq());
    }

    public function test_command_submits_or_expires_only_overdue_attempts(): void
    {
        $submitted = $this->startFor($this->candidateInA, $this->autoSubmit);
        $this->actingAs($this->candidateInA->user);
        [$position, $delivered] = $this->deliveryFor($submitted, $this->autoItem);
        $this->saveAnswer($submitted, $position, $this->correctChoiceId($delivered))->assertOk();
        $expired = $this->startFor($this->candidateInA, $this->manualOnly);
        $manualDelivered = $expired->delivery[0];
        $this->saveAnswer($expired, 0, $this->correctChoiceId($manualDelivered))->assertOk();
        $longer = $this->makeExamination(['duration_minutes' => 60, 'closes_at' => now()->addHours(2)]);
        $this->addItem($longer, $this->mcq());
        $notDue = $this->startFor($this->candidateInA, $longer);

        $this->travel(31)->minutes();
        $this->artisan('examinations:expire')->expectsOutput('Reconciled 2 expired attempts.')->assertSuccessful();

        $submitted->refresh();
        $this->assertSame('submitted', $submitted->status);
        $this->assertSame('automatic', $submitted->submission_kind);
        $this->assertTrue($submitted->submitted_at->equalTo($submitted->expires_at));
        $this->assertSame('pending_review', $submitted->result_status);
        $this->assertSame('2.00', $submitted->objective_points);
        $this->assertNotNull($submitted->scored_at);

        $expired->refresh();
        $this->assertSame('expired', $expired->status);
        $this->assertNull($expired->submitted_at);
        $this->assertNull($expired->submission_kind);
        $this->assertNull($expired->scored_at);
        $this->assertSame($this->correctChoiceId($manualDelivered), $expired->answers[$expired->delivery[0]['id']]['value']);

        $this->assertSame('in_progress', $notDue->fresh()->status);
        $this->assertSame($notDue->revision, $notDue->fresh()->revision);
    }

    public function test_second_run_is_a_no_op_and_keeps_the_original_submission(): void
    {
        $attempt = $this->startFor($this->candidateInA, $this->autoSubmit);
        $this->travel(31)->minutes();
        $this->artisan('examinations:expire')->expectsOutput('Reconciled 1 expired attempts.')->assertSuccessful();
        $first = $attempt->fresh();
        $this->travel(5)->minutes();
        $this->artisan('examinations:expire')->expectsOutput('Reconciled 0 expired attempts.')->assertSuccessful();
        $second = $attempt->fresh();
        $this->assertTrue($first->submitted_at->equalTo($second->submitted_at));
        $this->assertTrue($first->scored_at->equalTo($second->scored_at));
        $this->assertSame($first->revision, $second->revision);
    }

    public function test_attempt_already_reconciled_by_a_request_is_not_counted_again(): void
    {
        $attempt = $this->startFor($this->candidateInA, $this->autoSubmit);
        $this->travel(31)->minutes();
        $this->actingAs($this->candidateInA->user)->get('/portal/attempts/'.$attempt->id)->assertRedirect('/portal/attempts/'.$attempt->id.'/success');
        $revision = $attempt->fresh()->revision;
        $this->artisan('examinations:expire')->expectsOutput('Reconciled 0 expired attempts.')->assertSuccessful();
        $this->assertSame($revision, $attempt->fresh()->revision);
    }

    public function test_after_the_command_late_saves_are_ignored_and_the_candidate_sees_the_receipt(): void
    {
        $attempt = $this->startFor($this->candidateInA, $this->autoSubmit);
        $this->travel(40)->minutes();
        $this->artisan('examinations:expire')->assertSuccessful();
        $this->actingAs($this->candidateInA->user);
        $this->putJson('/portal/attempts/'.$attempt->id.'/answers', ['revision' => 0, 'position' => 1, 'answer' => 'Too late'])->assertOk()->assertJsonPath('status', 'submitted');
        $this->assertSame([], $attempt->fresh()->answers);
        $this->get('/portal/attempts/'.$attempt->id)->assertRedirect('/portal/attempts/'.$attempt->id.'/success');
        $this->get('/portal/attempts/'.$attempt->id.'/success')->assertOk();
    }

    public function test_command_is_scheduled_every_minute_without_overlapping(): void
    {
        $events = collect(app(Schedule::class)->events())->filter(fn (Event $event) => str_contains((string) $event->command, 'examinations:expire'));
        $this->assertCount(1, $events);
        $event = $events->first();
        $this->assertSame('* * * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping);
    }
}
