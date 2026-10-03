<?php

namespace Tests\Feature\Examinations\Delivery;

use App\Enums\CandidateStatus;
use App\Enums\SystemRole;
use App\Models\Candidate;
use App\Models\Examination;
use App\Models\ExaminationAttempt;
use App\Models\ExaminationQuestion;
use App\Models\Question;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Candidate eligibility, start, delivery snapshot, autosave, navigation,
 * server deadline, submission and heartbeat (Milestones 9–10).
 */
class AttemptLifecycleTest extends TestCase
{
    use BuildsDeliveryFixtures;

    private Examination $exam;

    /** @var list<ExaminationQuestion> */
    private array $items = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildDeliveryFixtures();
        $this->exam = $this->makeExamination();
        $this->items = [
            $this->addItem($this->exam, $this->mcq(2)),
            $this->addItem($this->exam, $this->trueFalse(false)),
            $this->addItem($this->exam, $this->essay()),
        ];
    }

    private function start(): ExaminationAttempt
    {
        return $this->startFor($this->candidateInA, $this->exam);
    }

    // Eligibility -------------------------------------------------------------

    public function test_candidate_of_another_class_cannot_start(): void
    {
        $this->actingAs($this->candidateInB->user)->post('/portal/examinations/'.$this->exam->id.'/start')->assertForbidden();
        $this->assertSame(0, ExaminationAttempt::count());
    }

    public function test_candidate_without_a_class_cannot_view_or_start(): void
    {
        $unassigned = Candidate::factory()->create();
        $this->actingAs($unassigned->user);
        $this->get('/portal/examinations/'.$this->exam->id)->assertForbidden();
        $this->post('/portal/examinations/'.$this->exam->id.'/start')->assertForbidden();
        $this->assertSame(0, ExaminationAttempt::count());
    }

    public function test_draft_and_archived_examinations_cannot_be_viewed_or_started(): void
    {
        $this->actingAs($this->candidateInA->user);
        foreach (['draft', 'archived'] as $status) {
            $this->exam->status = $status;
            $this->exam->save();
            $this->get('/portal/examinations/'.$this->exam->id)->assertForbidden();
            $this->post('/portal/examinations/'.$this->exam->id.'/start')->assertForbidden();
        }
        $this->assertSame(0, ExaminationAttempt::count());
    }

    public function test_not_yet_open_and_ended_examinations_are_visible_but_cannot_be_started(): void
    {
        $this->actingAs($this->candidateInA->user);
        $windows = [[now()->addHour(), now()->addHours(2)], [now()->subHours(2), now()->subMinute()]];
        foreach ($windows as [$opens, $closes]) {
            $this->exam->opens_at = $opens;
            $this->exam->closes_at = $closes;
            $this->exam->save();
            $this->get('/portal/examinations/'.$this->exam->id)->assertOk()->assertInertia(fn (Assert $page) => $page
                ->component('portal/examinations/show')
                ->where('examination.available', false)
                ->where('examination.resumeId', null));
            $this->post('/portal/examinations/'.$this->exam->id.'/start')->assertSessionHasErrors('examination');
        }
        $this->assertSame(0, ExaminationAttempt::count());
    }

    public function test_examinations_without_duration_or_questions_cannot_be_started(): void
    {
        $this->actingAs($this->candidateInA->user);
        $untimed = $this->makeExamination(['duration_minutes' => null]);
        $this->addItem($untimed, $this->mcq());
        $this->post('/portal/examinations/'.$untimed->id.'/start')->assertSessionHasErrors('examination');
        $empty = $this->makeExamination();
        $this->post('/portal/examinations/'.$empty->id.'/start')->assertSessionHasErrors('examination');
        $this->assertSame(0, ExaminationAttempt::count());
    }

    public function test_correct_access_code_starts_and_resuming_does_not_need_it_again(): void
    {
        $this->exam->access_code = 'Sample-Code-7';
        $this->exam->save();
        $this->actingAs($this->candidateInA->user);
        $url = '/portal/examinations/'.$this->exam->id.'/start';
        $this->post($url)->assertSessionHasErrors('access_code');
        $this->post($url, ['access_code' => 'sample-code-7'])->assertSessionHasErrors('access_code');
        $this->assertSame(0, ExaminationAttempt::count());
        $this->post($url, ['access_code' => 'Sample-Code-7'])->assertRedirect();
        $attempt = ExaminationAttempt::sole();
        $this->post($url)->assertRedirect('/portal/attempts/'.$attempt->id);
        $this->assertSame(1, ExaminationAttempt::count());
    }

    public function test_staff_and_guests_cannot_use_the_candidate_portal(): void
    {
        $attempt = $this->start();
        $this->actingAs($this->alpha)->get('/portal/attempts/'.$attempt->id)->assertForbidden();
        $this->actingAs($this->userWithRole(SystemRole::SuperAdministrator))->post('/portal/examinations/'.$this->exam->id.'/start')->assertForbidden();
        auth()->logout();
        $this->get('/portal/attempts/'.$attempt->id)->assertRedirect('/login');
        $this->putJson('/portal/attempts/'.$attempt->id.'/answers', ['revision' => 0, 'position' => 0])->assertUnauthorized();
    }

    public function test_deactivated_candidate_is_signed_out_and_cannot_save(): void
    {
        $attempt = $this->start();
        $this->candidateInA->user->forceFill(['is_active' => false])->save();
        $this->actingAs($this->candidateInA->user->fresh());
        $this->saveAnswer($attempt, 0, $attempt->delivery[0]['question']['choices'][0]['id']);
        $this->assertGuest();
        $this->assertSame([], $attempt->fresh()->answers);
        $this->assertSame(0, $attempt->fresh()->revision);
    }

    public function test_candidate_withdrawn_or_transferred_mid_attempt_can_no_longer_save_but_work_is_kept(): void
    {
        $attempt = $this->start();
        $this->actingAs($this->candidateInA->user);
        $choice = $attempt->delivery[0]['question']['choices'][0]['id'];
        $this->saveAnswer($attempt, 0, $choice)->assertOk();
        $this->candidateInA->status = CandidateStatus::Withdrawn;
        $this->candidateInA->save();
        // Each real request loads the user afresh; drop the cached candidate relation.
        $this->actingAs($this->candidateInA->user->fresh());
        $this->saveAnswer($attempt, 0, null)->assertForbidden();
        $this->postJson('/portal/attempts/'.$attempt->id.'/activity')->assertForbidden();
        $this->candidateInA->status = CandidateStatus::Enrolled;
        $this->candidateInA->class_batch_id = $this->batchB->id;
        $this->candidateInA->save();
        // Each real request loads the user afresh; drop the cached candidate relation.
        $this->actingAs($this->candidateInA->user->fresh());
        $this->saveAnswer($attempt, 0, null)->assertForbidden();
        $this->post('/portal/attempts/'.$attempt->id.'/submit', ['revision' => 1, 'position' => 0, 'answer' => $choice])->assertForbidden();
        $attempt->refresh();
        $this->assertSame('in_progress', $attempt->status);
        $this->assertSame($choice, $attempt->answers[$attempt->delivery[0]['id']]['value']);
    }

    public function test_other_candidates_cannot_heartbeat_or_view_results_of_an_attempt(): void
    {
        $attempt = $this->start();
        $other = Candidate::query()->where('class_batch_id', $this->batchA->id)->where('id', '!=', $this->candidateInA->id)->where('status', 'enrolled')->firstOrFail();
        $this->actingAs($this->candidateInA->user)->post('/portal/attempts/'.$attempt->id.'/submit', ['revision' => 0, 'position' => 0])->assertRedirect();
        $this->actingAs($other->user);
        $this->get('/portal/attempts/'.$attempt->id.'/success')->assertForbidden();
        $this->postJson('/portal/attempts/'.$attempt->id.'/activity')->assertForbidden();
        $this->get('/portal/attempts/'.$attempt->id)->assertForbidden();
    }

    // Start idempotency and attempt limits -----------------------------------

    public function test_repeated_start_requests_resume_the_same_attempt(): void
    {
        $this->actingAs($this->candidateInA->user);
        $first = $this->post('/portal/examinations/'.$this->exam->id.'/start');
        $second = $this->post('/portal/examinations/'.$this->exam->id.'/start');
        $attempt = ExaminationAttempt::sole();
        $first->assertRedirect('/portal/attempts/'.$attempt->id);
        $second->assertRedirect('/portal/attempts/'.$attempt->id);
        $this->assertSame(1, $attempt->attempt_number);
        $this->get('/portal/examinations/'.$this->exam->id)->assertInertia(fn (Assert $page) => $page
            ->where('examination.resumeId', $attempt->id)
            ->where('examination.attemptsUsed', 1));
    }

    public function test_each_candidate_takes_an_examination_once_with_no_retakes(): void
    {
        $this->actingAs($this->candidateInA->user);
        $url = '/portal/examinations/'.$this->exam->id.'/start';
        $this->post($url)->assertRedirect();
        $attempt = ExaminationAttempt::query()->where('status', 'in_progress')->sole();
        $this->assertSame(1, $attempt->attempt_number);
        $this->post('/portal/attempts/'.$attempt->id.'/submit', ['revision' => $attempt->revision, 'position' => 0])->assertRedirect('/portal/attempts/'.$attempt->id.'/success');

        $this->post($url)->assertSessionHasErrors(['examination' => 'You have already taken this examination.']);
        $this->assertSame(1, ExaminationAttempt::count());
    }

    public function test_start_after_an_unfinished_attempt_ran_out_reconciles_it_instead_of_creating_another(): void
    {
        $attempt = $this->start();
        $this->travel(31)->minutes();
        $this->exam->closes_at = now()->addHour();
        $this->exam->save();
        $this->actingAs($this->candidateInA->user)->post('/portal/examinations/'.$this->exam->id.'/start')->assertRedirect('/portal/attempts/'.$attempt->id);
        $attempt->refresh();
        $this->assertSame('submitted', $attempt->status);
        $this->assertSame('automatic', $attempt->submission_kind);
        $this->post('/portal/examinations/'.$this->exam->id.'/start')->assertSessionHasErrors('examination');
        $this->assertSame(1, ExaminationAttempt::count());
    }

    public function test_database_rejects_duplicate_attempt_numbers_and_unknown_statuses(): void
    {
        $attempt = $this->start();
        $duplicate = $attempt->replicate();
        try {
            $duplicate->save();
            $this->fail('Duplicate attempt number was stored.');
        } catch (QueryException) {
            $this->assertSame(1, ExaminationAttempt::count());
        }
        $this->expectException(QueryException::class);
        ExaminationAttempt::whereKey($attempt->id)->update(['status' => 'auto_submitted']);
    }

    // Delivery snapshot ------------------------------------------------------

    public function test_delivery_keeps_examination_order_and_exposes_only_candidate_fields(): void
    {
        $attempt = $this->start();
        $this->assertSame(array_map(fn (ExaminationQuestion $item) => $item->id, $this->items), array_column($attempt->delivery, 'id'));
        $props = $this->inertiaProps($this->actingAs($this->candidateInA->user)->get('/portal/attempts/'.$attempt->id)->assertOk());
        $this->assertCount(3, $props['questions']);
        foreach ($props['questions'] as $question) {
            $this->assertSame(['id', 'points', 'question'], array_keys($question));
            $this->assertSame(['id', 'type', 'prompt', 'choices', 'media'], array_keys($question['question']));
            foreach ($question['question']['choices'] as $choice) {
                $this->assertSame(['id', 'text', 'image'], array_keys($choice));
            }
        }
        $this->assertSame(['True', 'False'], array_column($props['questions'][1]['question']['choices'], 'text'));
    }

    public function test_randomized_delivery_is_a_stable_permutation_and_true_false_is_not_shuffled(): void
    {
        $exam = $this->makeExamination(['randomize_questions' => true, 'randomize_choices' => true]);
        $items = [];
        for ($i = 0; $i < 8; $i++) {
            $items[] = $this->addItem($exam, $this->mcq(1, 6));
        }
        $trueFalse = $this->addItem($exam, $this->trueFalse());
        $attempt = $this->startFor($this->candidateInA, $exam);
        $deliveredIds = array_column($attempt->delivery, 'id');
        $expectedIds = array_map(fn (ExaminationQuestion $item) => $item->id, [...$items, $trueFalse]);
        $this->assertEqualsCanonicalizing($expectedIds, $deliveredIds);
        // 9! orders and (6!)^8 choice orders: the odds of an unshuffled result are negligible.
        $this->assertNotSame($expectedIds, $deliveredIds);
        $choicesShuffled = false;
        foreach ($attempt->delivery as $delivered) {
            $stored = Question::find($delivered['question']['id'])->choices()->orderBy('position')->pluck('id')->all();
            $this->assertEqualsCanonicalizing($stored, array_column($delivered['question']['choices'], 'id'));
            if ($delivered['id'] === $trueFalse->id) {
                $this->assertSame($stored, array_column($delivered['question']['choices'], 'id'));
            } elseif ($stored !== array_column($delivered['question']['choices'], 'id')) {
                $choicesShuffled = true;
            }
        }
        $this->assertTrue($choicesShuffled);
        $this->actingAs($this->candidateInA->user);
        $firstRead = $this->inertiaProps($this->get('/portal/attempts/'.$attempt->id))['questions'];
        $secondRead = $this->inertiaProps($this->get('/portal/attempts/'.$attempt->id))['questions'];
        $this->assertSame($attempt->delivery, $firstRead);
        $this->assertSame($firstRead, $secondRead);
        $this->assertSame($attempt->delivery, $this->startFor($this->candidateInA, $exam)->delivery);
    }

    public function test_delivery_snapshot_is_unaffected_by_later_question_bank_edits(): void
    {
        $attempt = $this->start();
        $original = $attempt->delivery[0]['question']['prompt'];
        Question::whereKey($attempt->delivery[0]['question']['id'])->update(['prompt' => 'Edited after start.']);
        $props = $this->inertiaProps($this->actingAs($this->candidateInA->user)->get('/portal/attempts/'.$attempt->id));
        $this->assertSame($original, $props['questions'][0]['question']['prompt']);
    }

    // Autosave ---------------------------------------------------------------

    public function test_autosave_increments_revision_and_identical_retry_is_idempotent(): void
    {
        $attempt = $this->start();
        $this->actingAs($this->candidateInA->user);
        $choice = $attempt->delivery[0]['question']['choices'][1]['id'];
        $payload = ['revision' => 0, 'position' => 0, 'answer' => $choice, 'next_position' => 1];
        $this->putJson('/portal/attempts/'.$attempt->id.'/answers', $payload)->assertOk()->assertExactJson(['revision' => 1, 'status' => 'in_progress']);
        // The response was lost; the tablet retries the same payload.
        $this->putJson('/portal/attempts/'.$attempt->id.'/answers', $payload)->assertOk()->assertJsonPath('revision', 1);
        $attempt->refresh();
        $this->assertSame(1, $attempt->revision);
        $this->assertSame(1, $attempt->current_position);
        $this->assertSame(['value' => $choice], $attempt->answers[$attempt->delivery[0]['id']]);
    }

    public function test_stale_revision_with_different_content_is_rejected_without_overwriting(): void
    {
        $attempt = $this->start();
        $this->actingAs($this->candidateInA->user);
        $choices = array_column($attempt->delivery[0]['question']['choices'], 'id');
        $this->saveAnswer($attempt, 0, $choices[0])->assertOk();
        $this->putJson('/portal/attempts/'.$attempt->id.'/answers', ['revision' => 0, 'position' => 0, 'answer' => $choices[1]])->assertUnprocessable()->assertJsonValidationErrors('attempt');
        $this->assertSame($choices[0], $attempt->fresh()->answers[$attempt->delivery[0]['id']]['value']);
        $this->assertSame(1, $attempt->fresh()->revision);
    }

    public function test_answers_outside_the_attempt_or_of_the_wrong_shape_are_rejected(): void
    {
        $attempt = $this->start();
        $this->actingAs($this->candidateInA->user);
        $trueFalseChoice = $attempt->delivery[1]['question']['choices'][0]['id'];
        $mcqChoice = $attempt->delivery[0]['question']['choices'][0]['id'];
        $this->saveAnswer($attempt, 3, 'Not in attempt')->assertUnprocessable()->assertJsonValidationErrors('position');
        $this->saveAnswer($attempt, 0, $trueFalseChoice)->assertUnprocessable()->assertJsonValidationErrors('answer');
        $this->saveAnswer($attempt, 0, (string) $mcqChoice)->assertUnprocessable()->assertJsonValidationErrors('answer');
        $this->saveAnswer($attempt, 0, [$mcqChoice])->assertUnprocessable()->assertJsonValidationErrors('answer');
        $this->saveAnswer($attempt, 2, $mcqChoice)->assertUnprocessable()->assertJsonValidationErrors('answer');
        $this->putJson('/portal/attempts/'.$attempt->id.'/answers', ['position' => 0, 'answer' => $mcqChoice])->assertUnprocessable()->assertJsonValidationErrors('revision');
        $this->saveAnswer($attempt, 0, $mcqChoice, ['next_position' => 9])->assertUnprocessable()->assertJsonValidationErrors('position');
        $this->assertSame([], $attempt->fresh()->answers);
        $this->assertSame(0, $attempt->fresh()->revision);
        $this->assertSame(0, $attempt->fresh()->current_position);
    }

    public function test_essay_text_is_limited_to_twenty_thousand_characters(): void
    {
        $attempt = $this->start();
        $this->actingAs($this->candidateInA->user);
        $this->saveAnswer($attempt, 2, Str::repeat('é', 20001))->assertUnprocessable()->assertJsonValidationErrors('answer');
        $this->assertSame([], $attempt->fresh()->answers);
        $this->saveAnswer($attempt, 2, Str::repeat('é', 20000))->assertOk();
        $this->assertSame(20000, mb_strlen($attempt->fresh()->answers[$attempt->delivery[2]['id']]['value']));
        $this->saveAnswer($attempt, 2, null)->assertOk();
        $this->assertNull($attempt->fresh()->answers[$attempt->delivery[2]['id']]['value']);
    }

    public function test_answers_carry_no_review_flag_even_when_one_is_sent(): void
    {
        $attempt = $this->start();
        $this->actingAs($this->candidateInA->user);
        $this->saveAnswer($attempt, 0, null, ['flagged' => true])->assertOk();
        $this->assertSame(['value' => null], $attempt->fresh()->answers[$attempt->delivery[0]['id']]);
    }

    // Navigation -------------------------------------------------------------

    public function test_forward_only_navigation_allows_one_step_forward_only(): void
    {
        $this->exam->allow_back_navigation = false;
        $this->exam->save();
        $attempt = $this->start();
        $this->actingAs($this->candidateInA->user);
        $this->saveAnswer($attempt, 0, null, ['next_position' => 2])->assertUnprocessable();
        $this->saveAnswer($attempt, 0, null, ['next_position' => 1])->assertOk();
        $this->saveAnswer($attempt, 1, null, ['next_position' => 0])->assertUnprocessable();
        $this->saveAnswer($attempt, 2, 'Skipping ahead')->assertUnprocessable();
        $this->saveAnswer($attempt, 1, null, ['next_position' => 2])->assertOk();
        $this->assertSame(2, $attempt->fresh()->current_position);
        $this->assertArrayNotHasKey($attempt->delivery[2]['id'], $attempt->fresh()->answers);
    }

    public function test_free_navigation_allows_jumping_between_questions(): void
    {
        $attempt = $this->start();
        $this->actingAs($this->candidateInA->user);
        $this->saveAnswer($attempt, 2, 'Draft essay', ['next_position' => 0])->assertOk();
        $this->saveAnswer($attempt, 0, null, ['next_position' => 2])->assertOk();
        $this->assertSame(2, $attempt->fresh()->current_position);
        $this->get('/portal/attempts/'.$attempt->id)->assertInertia(fn (Assert $page) => $page
            ->where('attempt.position', 2)
            ->where('attempt.revision', 2)
            ->where('attempt.allowBackNavigation', true)
            ->where('attempt.answers.'.$attempt->delivery[2]['id'].'.value', 'Draft essay'));
    }

    // Server deadline --------------------------------------------------------

    public function test_deadline_is_the_earlier_of_duration_and_window_close(): void
    {
        $attempt = $this->start();
        $this->assertTrue($attempt->expires_at->equalTo(now()->addMinutes(30)));
        $short = $this->makeExamination(['closes_at' => now()->addMinutes(10)]);
        $this->addItem($short, $this->mcq());
        $this->assertTrue($this->startFor($this->candidateInA, $short)->expires_at->equalTo(now()->addMinutes(10)));
        $this->actingAs($this->candidateInA->user)->get('/portal/attempts/'.$attempt->id)->assertInertia(fn (Assert $page) => $page
            ->where('attempt.expiresAt', now()->addMinutes(30)->toIso8601String())
            ->where('attempt.serverNow', now()->toIso8601String()));
    }

    public function test_saves_just_before_the_deadline_are_kept_and_saves_at_the_deadline_are_rejected(): void
    {
        $attempt = $this->start();
        $this->actingAs($this->candidateInA->user);
        $choice = $this->correctChoiceId($attempt->delivery[0]);
        $this->travel(30 * 60 - 1)->seconds();
        $this->saveAnswer($attempt, 0, $choice)->assertOk()->assertJsonPath('status', 'in_progress');
        $this->travel(1)->seconds();
        $this->saveAnswer($attempt, 0, $this->wrongChoiceId($attempt->delivery[0]))->assertOk()->assertJsonPath('status', 'submitted');
        $attempt->refresh();
        $this->assertSame($choice, $attempt->answers[$attempt->delivery[0]['id']]['value']);
        $this->assertSame('automatic', $attempt->submission_kind);
        $this->assertTrue($attempt->submitted_at->equalTo($attempt->expires_at));
    }

    public function test_window_close_ends_the_attempt_before_its_duration(): void
    {
        $this->exam->closes_at = now()->addMinutes(5);
        $this->exam->save();
        $attempt = $this->start();
        $this->actingAs($this->candidateInA->user);
        $this->saveAnswer($attempt, 2, 'Written in time')->assertOk();
        $this->travel(5)->minutes();
        $this->saveAnswer($attempt, 2, 'Written too late')->assertOk()->assertJsonPath('status', 'submitted');
        $this->assertSame('Written in time', $attempt->fresh()->answers[$attempt->delivery[2]['id']]['value']);
    }

    public function test_late_manual_submit_is_recorded_as_automatic_without_the_late_payload(): void
    {
        $attempt = $this->start();
        $this->actingAs($this->candidateInA->user);
        $this->saveAnswer($attempt, 2, 'Saved essay')->assertOk();
        $this->travel(45)->minutes();
        $this->post('/portal/attempts/'.$attempt->id.'/submit', ['revision' => 1, 'position' => 2, 'answer' => 'Late essay'])->assertRedirect('/portal/attempts/'.$attempt->id.'/success');
        $attempt->refresh();
        $this->assertSame('submitted', $attempt->status);
        $this->assertSame('automatic', $attempt->submission_kind);
        $this->assertTrue($attempt->submitted_at->equalTo($attempt->expires_at));
        $this->assertSame('Saved essay', $attempt->answers[$attempt->delivery[2]['id']]['value']);
    }

    public function test_without_auto_submit_late_submit_expires_the_attempt_unscored(): void
    {
        $this->exam->auto_submit = false;
        $this->exam->save();
        $attempt = $this->start();
        $this->actingAs($this->candidateInA->user);
        $this->travel(31)->minutes();
        $this->post('/portal/attempts/'.$attempt->id.'/submit', ['revision' => 0, 'position' => 0, 'answer' => $this->correctChoiceId($attempt->delivery[0])])->assertRedirect('/portal/attempts/'.$attempt->id.'/success');
        $attempt->refresh();
        $this->assertSame('expired', $attempt->status);
        $this->assertNull($attempt->submission_kind);
        $this->assertNull($attempt->scored_at);
        $this->assertNull($attempt->result_status);
        $this->assertSame([], $attempt->answers);
        $this->get('/portal/attempts/'.$attempt->id.'/success')->assertOk()->assertInertia(fn (Assert $page) => $page->where('status', 'expired'));
    }

    // Submission -------------------------------------------------------------

    public function test_manual_submit_saves_the_final_answer_and_is_idempotent(): void
    {
        $attempt = $this->start();
        $this->actingAs($this->candidateInA->user);
        $payload = ['revision' => 0, 'position' => 2, 'answer' => 'Final essay'];
        $this->post('/portal/attempts/'.$attempt->id.'/submit', $payload)->assertRedirect('/portal/attempts/'.$attempt->id.'/success');
        $first = $attempt->fresh();
        $this->assertSame('manual', $first->submission_kind);
        $this->assertSame(1, $first->revision);
        $this->travel(1)->minutes();
        $this->post('/portal/attempts/'.$attempt->id.'/submit', $payload)->assertRedirect('/portal/attempts/'.$attempt->id.'/success');
        $this->post('/portal/attempts/'.$attempt->id.'/submit', ['revision' => 1, 'position' => 2, 'answer' => 'Changed after submit'])->assertRedirect('/portal/attempts/'.$attempt->id.'/success');
        $this->saveAnswer($attempt, 2, 'Autosave after submit')->assertOk()->assertJsonPath('status', 'submitted');
        $second = $attempt->fresh();
        $this->assertTrue($first->submitted_at->equalTo($second->submitted_at));
        $this->assertTrue($first->scored_at->equalTo($second->scored_at));
        $this->assertSame(1, $second->revision);
        $this->assertSame('Final essay', $second->answers[$attempt->delivery[2]['id']]['value']);
        $this->get('/portal/attempts/'.$attempt->id)->assertRedirect('/portal/attempts/'.$attempt->id.'/success');
    }

    public function test_submit_retry_after_a_lost_autosave_response_still_submits(): void
    {
        $attempt = $this->start();
        $this->actingAs($this->candidateInA->user);
        $payload = ['revision' => 0, 'position' => 2, 'answer' => 'Essay'];
        $this->putJson('/portal/attempts/'.$attempt->id.'/answers', $payload)->assertOk();
        $this->post('/portal/attempts/'.$attempt->id.'/submit', $payload)->assertRedirect('/portal/attempts/'.$attempt->id.'/success');
        $this->assertSame('submitted', $attempt->fresh()->status);
        $this->assertSame('manual', $attempt->fresh()->submission_kind);
    }

    public function test_stale_tab_cannot_submit_over_newer_answers(): void
    {
        $attempt = $this->start();
        $this->actingAs($this->candidateInA->user);
        $this->saveAnswer($attempt, 2, 'Newer tab text')->assertOk();
        $this->post('/portal/attempts/'.$attempt->id.'/submit', ['revision' => 0, 'position' => 2, 'answer' => 'Older tab text'])->assertSessionHasErrors('attempt');
        $attempt->refresh();
        $this->assertSame('in_progress', $attempt->status);
        $this->assertSame('Newer tab text', $attempt->answers[$attempt->delivery[2]['id']]['value']);
    }

    public function test_success_page_is_available_only_to_the_owner_after_submission(): void
    {
        $attempt = $this->start();
        $this->actingAs($this->candidateInA->user);
        $this->get('/portal/attempts/'.$attempt->id.'/success')->assertNotFound();
        $this->post('/portal/attempts/'.$attempt->id.'/submit', ['revision' => 0, 'position' => 0]);
        $this->get('/portal/attempts/'.$attempt->id.'/success')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('portal/examinations/success')
            ->where('status', 'submitted')
            ->where('attemptId', $attempt->id));
    }

    // Heartbeat --------------------------------------------------------------

    public function test_heartbeat_updates_last_activity_without_touching_answers_or_revision(): void
    {
        $attempt = $this->start();
        $this->actingAs($this->candidateInA->user);
        $this->saveAnswer($attempt, 2, 'Essay')->assertOk();
        $before = $attempt->fresh();
        $this->travel(90)->seconds();
        $this->postJson('/portal/attempts/'.$attempt->id.'/activity')->assertOk()->assertExactJson([
            'status' => 'in_progress',
            'serverNow' => now()->toIso8601String(),
            'expiresAt' => $before->expires_at->toIso8601String(),
        ]);
        $after = $attempt->fresh();
        $this->assertTrue($after->last_activity_at->equalTo(now()));
        $this->assertSame($before->revision, $after->revision);
        $this->assertSame($before->answers, $after->answers);
        $this->assertSame($before->current_position, $after->current_position);
    }

    public function test_heartbeat_after_the_deadline_reconciles_the_attempt(): void
    {
        $attempt = $this->start();
        $this->travel(31)->minutes();
        $this->actingAs($this->candidateInA->user)->postJson('/portal/attempts/'.$attempt->id.'/activity')->assertOk()->assertJsonPath('status', 'submitted');
        $this->assertSame('automatic', $attempt->fresh()->submission_kind);
    }
}
