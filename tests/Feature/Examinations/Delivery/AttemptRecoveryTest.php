<?php

namespace Tests\Feature\Examinations\Delivery;

use App\Models\Examination;
use App\Models\ExaminationAttempt;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Milestone 19: candidate exam recovery. Reopening an attempt keeps the
 * server deadline; saves queued on the tablet during a disconnection are
 * accepted in order afterwards; submission locks the attempt row so two
 * simultaneous submits cannot both apply.
 */
class AttemptRecoveryTest extends TestCase
{
    use BuildsDeliveryFixtures;

    private Examination $exam;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildDeliveryFixtures();
        $this->exam = $this->makeExamination();
        $this->addItem($this->exam, $this->mcq(2));
        $this->addItem($this->exam, $this->trueFalse(false));
        $this->addItem($this->exam, $this->essay());
    }

    public function test_reopening_after_time_passes_keeps_the_deadline_and_the_saved_answers(): void
    {
        $attempt = $this->startFor($this->candidateInA, $this->exam);
        $this->actingAs($this->candidateInA->user);
        $deadline = $attempt->expires_at->toIso8601String();
        $this->saveAnswer($attempt, 2, 'Saved before the refresh', ['next_position' => 2])->assertOk();

        // The tablet is reloaded twelve minutes later.
        $this->travel(12)->minutes();
        $this->get('/portal/attempts/'.$attempt->id)->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('portal/examinations/attempt')
            ->where('attempt.expiresAt', $deadline)
            ->where('attempt.serverNow', now()->toIso8601String())
            ->where('attempt.position', 2)
            ->where('attempt.revision', 1)
            ->where('attempt.answers.'.$attempt->delivery[2]['id'].'.value', 'Saved before the refresh'));

        // Starting again resumes the same attempt instead of creating a new one or a new deadline.
        $this->post('/portal/examinations/'.$this->exam->id.'/start')->assertRedirect('/portal/attempts/'.$attempt->id);
        $this->assertSame(1, ExaminationAttempt::query()->count());
        $this->assertSame($deadline, $attempt->fresh()->expires_at->toIso8601String());
    }

    public function test_saves_queued_during_a_disconnection_are_accepted_in_order_afterwards(): void
    {
        $attempt = $this->startFor($this->candidateInA, $this->exam);
        $this->actingAs($this->candidateInA->user);
        $url = '/portal/attempts/'.$attempt->id.'/answers';
        $choice = $this->correctChoiceId($attempt->delivery[0]);
        $falseChoice = collect($attempt->delivery[1]['question']['choices'])->firstWhere('label', 'False')['id']
            ?? $attempt->delivery[1]['question']['choices'][1]['id'];

        $this->putJson($url, ['revision' => 0, 'position' => 0, 'answer' => $choice, 'next_position' => 1])->assertOk()->assertJsonPath('revision', 1);

        // Offline: the tablet queues three saves, each expecting the revision after the previous one.
        $queue = [
            ['revision' => 1, 'position' => 1, 'answer' => $falseChoice, 'next_position' => 2],
            ['revision' => 2, 'position' => 2, 'answer' => 'Written offline', 'next_position' => 2],
            ['revision' => 3, 'position' => 2, 'answer' => 'Written offline, then corrected', 'next_position' => 2, 'flagged' => true],
        ];

        // The connection returns four minutes later and the queue is replayed in order.
        $this->travel(4)->minutes();
        foreach ($queue as $index => $payload) {
            $this->putJson($url, $payload)->assertOk()->assertExactJson(['revision' => $index + 2, 'status' => 'in_progress']);
        }

        // The response to the last save was lost; resending it changes nothing.
        $this->putJson($url, $queue[2])->assertOk()->assertJsonPath('revision', 4);

        $attempt->refresh();
        $this->assertSame(4, $attempt->revision);
        $this->assertSame($choice, $attempt->answers[$attempt->delivery[0]['id']]['value']);
        $this->assertSame($falseChoice, $attempt->answers[$attempt->delivery[1]['id']]['value']);
        $this->assertSame(['value' => 'Written offline, then corrected', 'flagged' => true], $attempt->answers[$attempt->delivery[2]['id']]);
    }

    public function test_a_queued_save_that_arrives_after_the_deadline_is_not_applied(): void
    {
        $attempt = $this->startFor($this->candidateInA, $this->exam);
        $this->actingAs($this->candidateInA->user);
        $this->saveAnswer($attempt, 2, 'Saved in time')->assertOk();

        // The tablet stayed offline past the deadline.
        $this->travel(31)->minutes();
        $this->putJson('/portal/attempts/'.$attempt->id.'/answers', ['revision' => 1, 'position' => 2, 'answer' => 'Too late'])
            ->assertOk()->assertJsonPath('status', 'submitted');

        $this->assertSame('Saved in time', $attempt->fresh()->answers[$attempt->delivery[2]['id']]['value']);
    }

    public function test_submission_locks_the_attempt_row_so_simultaneous_submits_cannot_both_apply(): void
    {
        $attempt = $this->startFor($this->candidateInA, $this->exam);
        $this->actingAs($this->candidateInA->user);

        $locks = [];
        DB::listen(function (QueryExecuted $query) use (&$locks): void {
            if (str_contains($query->sql, 'examination_attempts') && str_contains(strtolower($query->sql), 'for update')) {
                $locks[] = $query->sql;
            }
        });

        $this->post('/portal/attempts/'.$attempt->id.'/submit', ['revision' => 0, 'position' => 2, 'answer' => 'Final'])
            ->assertRedirect('/portal/attempts/'.$attempt->id.'/success');
        $this->assertNotEmpty($locks, 'Submission must read the attempt with SELECT … FOR UPDATE inside its transaction.');

        // The second of two simultaneous submits finds the attempt already submitted and changes nothing.
        $first = $attempt->fresh();
        $this->post('/portal/attempts/'.$attempt->id.'/submit', ['revision' => 0, 'position' => 2, 'answer' => 'Second request'])
            ->assertRedirect('/portal/attempts/'.$attempt->id.'/success');
        $second = $attempt->fresh();
        $this->assertTrue($first->submitted_at->equalTo($second->submitted_at));
        $this->assertSame('Final', $second->answers[$attempt->delivery[2]['id']]['value']);
        $this->assertSame(1, ExaminationAttempt::query()->where('status', 'submitted')->count());
    }
}
