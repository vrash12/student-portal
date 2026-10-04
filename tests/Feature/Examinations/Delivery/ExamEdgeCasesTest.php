<?php

namespace Tests\Feature\Examinations\Delivery;

use App\Enums\CampusCode;
use App\Enums\SystemRole;
use App\Models\Candidate;
use App\Models\ClassBatch;
use App\Models\Examination;
use App\Models\ExaminationAttempt;
use App\Models\User;
use App\Services\Examinations\CandidateAttemptService;
use Database\Factories\CampusFactory;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Quizzes and examinations at their edges (2026-10-04 review): campuses,
 * the one-attempt rule after an expired attempt, late submits after the
 * expiry job, an attempt the job cannot close, archiving with overdue
 * attempts, the portal lists, and signing in on another tablet mid-attempt.
 */
class ExamEdgeCasesTest extends TestCase
{
    use BuildsDeliveryFixtures;

    private Examination $exam;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildDeliveryFixtures();
        $this->exam = $this->makeExamination();
        $this->addItem($this->exam, $this->mcq());
        $this->addItem($this->exam, $this->trueFalse());
    }

    public function test_candidates_and_staff_of_another_campus_cannot_reach_an_examination(): void
    {
        // The North Campus may have a class of the same name, taking the same subject.
        $north = CampusFactory::fixed(CampusCode::North);
        $northClass = ClassBatch::factory()->for($this->activePeriod)->onCampus($north)->create(['name' => $this->batchA->name]);
        $northInstructor = User::factory()->withRole(SystemRole::Instructor)->onCampus($north)->create();
        $this->teach($northInstructor, $this->offering($northClass, $this->offering->subject));
        $northCandidate = Candidate::factory()->create(['class_batch_id' => $northClass->id]);
        $attempt = $this->startFor($this->candidateInA, $this->exam);

        // The candidate never sees it, cannot open or start it, nor open another's attempt.
        $this->actingAs($northCandidate->user);
        $this->get('/portal/examinations')->assertOk()->assertInertia(fn (Assert $page) => $page->has('available.data', 0)->has('upcoming.data', 0));
        $this->get('/portal/examinations/'.$this->exam->id)->assertForbidden();
        $this->post('/portal/examinations/'.$this->exam->id.'/start')->assertForbidden();
        $this->get('/portal/attempts/'.$attempt->id)->assertForbidden();
        $this->putJson('/portal/attempts/'.$attempt->id.'/answers', ['revision' => 0, 'position' => 0, 'answer' => null])->assertForbidden();
        $this->assertSame(1, ExaminationAttempt::query()->count());

        // The instructor teaching the same subject on the North Campus reaches none of its pages.
        $this->actingAs($northInstructor);
        $this->get('/examinations')->assertOk()->assertInertia(fn (Assert $page) => $page->where('examinations.total', 0));
        foreach (['', '/edit', '/questions', '/grading', '/analysis', '/gradebook'] as $page) {
            $this->assertContains($this->get('/examinations/'.$this->exam->id.$page)->status(), [403, 404], "/examinations/{id}{$page}");
        }
        $this->assertContains($this->post('/examinations/'.$this->exam->id.'/archive', ['reason' => 'Not mine'])->status(), [403, 404]);
        $this->assertContains($this->get('/examination-attempts/'.$attempt->id.'/grading')->status(), [403, 404]);
        $this->assertSame('published', $this->exam->fresh()->status->value);
    }

    public function test_an_attempt_that_expired_unsubmitted_is_still_the_one_attempt(): void
    {
        $exam = $this->makeExamination(['auto_submit' => false]);
        $this->addItem($exam, $this->mcq());
        $attempt = $this->startFor($this->candidateInA, $exam);

        $this->travel(31)->minutes();
        Artisan::call('examinations:expire');
        $this->assertSame('expired', $attempt->fresh()->status);

        $this->actingAs($this->candidateInA->user)
            ->post('/portal/examinations/'.$exam->id.'/start')
            ->assertSessionHasErrors(['examination' => 'You have already taken this examination.']);
        $this->assertSame(1, ExaminationAttempt::query()->where('examination_id', $exam->id)->count());
    }

    public function test_a_submit_after_the_expiry_job_closed_the_attempt_changes_nothing(): void
    {
        $attempt = $this->startFor($this->candidateInA, $this->exam);
        $this->actingAs($this->candidateInA->user);
        [$position, $item] = [0, $attempt->delivery[0]];
        $answer = $item['question']['choices'][0]['id'];
        $this->saveAnswer($attempt, $position, $answer)->assertOk();

        $this->travel(31)->minutes();
        Artisan::call('examinations:expire');
        $closed = $attempt->fresh();
        $this->assertSame(['submitted', 'automatic'], [$closed->status, $closed->submission_kind]);

        // A tablet that was offline sends its submit late, with another answer.
        $this->post('/portal/attempts/'.$attempt->id.'/submit', ['revision' => $closed->revision, 'position' => $position, 'answer' => $item['question']['choices'][1]['id']])
            ->assertRedirect('/portal/attempts/'.$attempt->id.'/success');

        $after = $attempt->fresh();
        $this->assertSame([$closed->revision, 'automatic', $closed->answers, (string) $closed->submitted_at], [$after->revision, $after->submission_kind, $after->answers, (string) $after->submitted_at]);
    }

    public function test_one_attempt_that_cannot_be_closed_does_not_keep_the_others_open(): void
    {
        Log::spy();
        $broken = $this->startFor($this->candidateInA, $this->exam);
        // An attempt from an old version without its questions: scoring it fails.
        DB::table('examination_attempts')->where('id', $broken->id)->update(['delivery' => '[]', 'scoring_key' => null]);
        $other = Candidate::factory()->create(['class_batch_id' => $this->batchA->id]);
        $healthy = $this->startFor($other, $this->exam);

        $this->travel(31)->minutes();
        $closed = app(CandidateAttemptService::class)->expireDue();

        $this->assertSame(1, $closed);
        $this->assertSame('submitted', $healthy->fresh()->status);
        // The broken one rolled back: still open, retried on the next run, and logged by id only.
        $this->assertSame('in_progress', $broken->fresh()->status);
        Log::shouldHaveReceived('error')->once()->withArgs(fn (string $message, array $context): bool => $context['attempt_id'] === $broken->id
            && ! array_key_exists('answers', $context));
    }

    public function test_archiving_closes_attempts_whose_time_has_run_out(): void
    {
        $attempt = $this->startFor($this->candidateInA, $this->exam);
        $this->travel(31)->minutes();

        // Nothing closed the attempt yet (no expiry job ran), but its time is over.
        $this->assertSame('in_progress', $attempt->fresh()->status);
        $this->actingAs($this->alpha)->post('/examinations/'.$this->exam->id.'/archive', ['reason' => 'Term closed'])->assertSessionHasNoErrors();

        $this->assertSame('archived', $this->exam->fresh()->status->value);
        $this->assertSame('submitted', $attempt->fresh()->status);
    }

    public function test_an_attempt_still_being_taken_keeps_the_examination_from_being_archived(): void
    {
        $attempt = $this->startFor($this->candidateInA, $this->exam);

        $this->actingAs($this->alpha)->post('/examinations/'.$this->exam->id.'/archive', ['reason' => 'Term closed'])
            ->assertSessionHasErrors(['examination' => 'Wait for all attempts to finish before archiving.']);

        $this->assertSame('published', $this->exam->fresh()->status->value);
        $this->assertSame('in_progress', $attempt->fresh()->status);
    }

    public function test_drafts_and_archived_examinations_of_the_candidates_own_class_are_not_listed(): void
    {
        $draft = $this->makeExamination(['title' => 'Synthetic draft', 'status' => 'draft']);
        $archived = $this->makeExamination(['title' => 'Synthetic archived', 'status' => 'archived']);
        $later = $this->makeExamination(['title' => 'Synthetic later', 'status' => 'draft', 'opens_at' => now()->addDay(), 'closes_at' => now()->addDays(2)]);
        $titles = fn (array $exams): array => array_column($exams, 'title');

        $this->actingAs($this->candidateInA->user);
        foreach (['/portal', '/portal/examinations'] as $page) {
            $this->get($page)->assertOk()->assertInertia(function (Assert $page) use ($titles, $draft, $archived, $later): void {
                $props = $page->toArray()['props'];
                $listed = [...$titles($props['available']['data']), ...$titles($props['upcoming']['data'])];
                $this->assertContains('Synthetic delivery exam', $listed);
                $this->assertNotContains($draft->title, $listed);
                $this->assertNotContains($archived->title, $listed);
                $this->assertNotContains($later->title, $listed);
            });
        }
    }

    public function test_signing_in_on_another_tablet_continues_the_same_attempt(): void
    {
        $attempt = $this->startFor($this->candidateInA, $this->exam);
        $this->actingAs($this->candidateInA->user);
        $answer = $attempt->delivery[0]['question']['choices'][0]['id'];
        $this->saveAnswer($attempt, 0, $answer)->assertOk();
        $deadline = (string) $attempt->fresh()->expires_at;
        // Tablet A's session, ended when the candidate signs in on tablet B.
        DB::table('sessions')->insert(['id' => 'tablet-a', 'user_id' => $this->candidateInA->user_id, 'ip_address' => '127.0.0.1', 'user_agent' => 'Tablet A', 'payload' => '', 'last_activity' => now()->getTimestamp()]);
        $this->candidateInA->user->forceFill(['password' => 'tablet-password'])->save();

        $this->post('/logout');
        $this->post('/login', ['username' => $this->candidateInA->user->username, 'password' => 'tablet-password'])->assertRedirect();

        $this->assertSame(0, DB::table('sessions')->where('id', 'tablet-a')->count());
        // Starting again on tablet B resumes the same attempt with its deadline and saved answer.
        $this->post('/portal/examinations/'.$this->exam->id.'/start')->assertRedirect('/portal/attempts/'.$attempt->id);
        $this->get('/portal/attempts/'.$attempt->id)->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('portal/examinations/attempt')
            ->where('attempt.id', $attempt->id));
        $this->assertSame(1, ExaminationAttempt::query()->count());
        $this->assertSame($deadline, (string) $attempt->fresh()->expires_at);
        $this->assertSame($answer, $attempt->fresh()->answers[(string) $attempt->delivery[0]['id']]['value']);
    }
}
