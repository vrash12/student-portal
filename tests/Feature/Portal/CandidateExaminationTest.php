<?php

namespace Tests\Feature\Portal;

use App\Enums\CandidateStatus;
use App\Models\Examination;
use App\Models\ExaminationAttempt;
use App\Models\ExaminationQuestion;
use App\Models\Question;
use App\Services\Examinations\CandidateAttemptService;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Teaching\BuildsTeachingFixtures;
use Tests\TestCase;

class CandidateExaminationTest extends TestCase
{
    use BuildsTeachingFixtures;

    private Examination $exam;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildTeachingFixtures();
        $offering = $this->batchA->classSubjects()->firstOrFail();
        $exam = new Examination;
        $exam->class_subject_id = $offering->id;
        $exam->created_by = $this->alpha->id;
        $exam->title = 'Synthetic exam';
        $exam->status = 'published';
        $exam->duration_minutes = 30;
        $exam->opens_at = now()->subMinute();
        $exam->closes_at = now()->addHour();
        $exam->save();
        $this->exam = $exam->fresh();
        foreach ([Question::factory()->multipleChoice()->create(['subject_id' => $offering->subject_id]), Question::factory()->create(['subject_id' => $offering->subject_id])] as $index => $question) {
            $item = new ExaminationQuestion;
            $item->examination_id = $exam->id;
            $item->question_id = $question->id;
            $item->position = $index + 1;
            $item->points = 1;
            $item->save();
        }
    }

    private function startAttempt(): ExaminationAttempt
    {
        return app(CandidateAttemptService::class)->start($this->candidateInA->user, $this->exam, null);
    }

    public function test_start_resumes_and_payload_does_not_expose_answers(): void
    {
        $this->actingAs($this->candidateInA->user)->post('/portal/examinations/'.$this->exam->id.'/start')->assertRedirect();
        $attempt = ExaminationAttempt::sole();
        $this->assertSame($attempt->id, $this->startAttempt()->id);
        $this->get('/portal/attempts/'.$attempt->id)->assertInertia(fn (Assert $page) => $page->component('portal/examinations/attempt')->has('questions', 2)->missing('questions.0.question.choices.0.isCorrect')->missing('questions.0.question.explanation'));
        $this->assertSame(1, ExaminationAttempt::count());
    }

    public function test_answers_survive_refresh_and_submission_is_idempotent(): void
    {
        $attempt = $this->startAttempt();
        $choice = $attempt->delivery[0]['question']['choices'][0]['id'];
        $this->actingAs($this->candidateInA->user)->putJson('/portal/attempts/'.$attempt->id.'/answers', ['revision' => 0, 'position' => 0, 'answer' => $choice, 'next_position' => 1])->assertOk();
        $attempt->refresh();
        $this->assertSame($choice, $attempt->answers[$attempt->delivery[0]['id']]['value']);
        $data = ['revision' => 1, 'position' => 1, 'answer' => 'Essay response'];
        $this->post('/portal/attempts/'.$attempt->id.'/submit', $data)->assertRedirect();
        $this->post('/portal/attempts/'.$attempt->id.'/submit', $data)->assertRedirect();
        $this->assertSame('submitted', $attempt->fresh()->status);
        $this->assertSame('Essay response', $attempt->fresh()->answers[$attempt->delivery[1]['id']]['value']);
    }

    public function test_other_candidates_cannot_read_save_or_submit_attempt(): void
    {
        $attempt = $this->startAttempt();
        $this->actingAs($this->candidateInB->user);
        $this->get('/portal/attempts/'.$attempt->id)->assertForbidden();
        $this->putJson('/portal/attempts/'.$attempt->id.'/answers', ['revision' => 0, 'position' => 0])->assertForbidden();
        $this->post('/portal/attempts/'.$attempt->id.'/submit', ['revision' => 0, 'position' => 0])->assertForbidden();
        $this->get('/portal/examinations/'.$this->exam->id)->assertForbidden();
    }

    public function test_deadline_rejects_late_answers_and_preserves_saved_work(): void
    {
        $attempt = $this->startAttempt();
        $this->travel(31)->minutes();
        $this->actingAs($this->candidateInA->user)->putJson('/portal/attempts/'.$attempt->id.'/answers', ['revision' => 0, 'position' => 0, 'answer' => 999])->assertOk()->assertJsonPath('status', 'submitted');
        $this->assertSame([], $attempt->fresh()->answers);
    }

    public function test_invalid_choice_stale_revision_and_forward_only_navigation_are_rejected(): void
    {
        $this->exam->allow_back_navigation = false;
        $this->exam->save();
        $attempt = $this->startAttempt();
        $this->actingAs($this->candidateInA->user);
        $url = '/portal/attempts/'.$attempt->id.'/answers';
        $this->putJson($url, ['revision' => 0, 'position' => 0, 'answer' => 999999])->assertUnprocessable();
        $this->putJson($url, ['revision' => 0, 'position' => 0, 'next_position' => 1])->assertOk();
        $this->putJson($url, ['revision' => 0, 'position' => 1])->assertUnprocessable();
        $this->putJson($url, ['revision' => 1, 'position' => 0])->assertUnprocessable();
    }

    public function test_access_code_and_draft_visibility(): void
    {
        $this->exam->access_code = 'sample-code';
        $this->exam->save();
        $this->actingAs($this->candidateInA->user);
        $this->post('/portal/examinations/'.$this->exam->id.'/start', ['access_code' => 'wrong'])->assertSessionHasErrors('access_code');
        $this->exam->status = 'draft';
        $this->exam->save();
        $this->get('/portal/examinations/'.$this->exam->id)->assertForbidden();
    }

    public function test_success_requires_a_terminal_attempt(): void
    {
        $attempt = $this->startAttempt();
        $this->actingAs($this->candidateInA->user)->get('/portal/attempts/'.$attempt->id.'/success')->assertNotFound();
    }

    public function test_one_attempt_and_availability_are_enforced(): void
    {
        $attempt = $this->startAttempt();
        $attempt->status = 'submitted';
        $attempt->submitted_at = now();
        $attempt->save();
        $this->actingAs($this->candidateInA->user)->post('/portal/examinations/'.$this->exam->id.'/start')->assertSessionHasErrors('examination');
        $this->assertSame(1, ExaminationAttempt::count());
        $this->exam->opens_at = now()->addHour();
        $this->exam->closes_at = now()->addHours(2);
        $this->exam->save();
        $this->post('/portal/examinations/'.$this->exam->id.'/start')->assertSessionHasErrors('examination');
    }

    public function test_nonautomatic_expiration_and_stable_randomized_delivery(): void
    {
        $this->exam->auto_submit = false;
        $this->exam->randomize_questions = true;
        $this->exam->randomize_choices = true;
        $this->exam->save();
        $attempt = $this->startAttempt();
        $this->assertSame($attempt->delivery, $this->startAttempt()->delivery);
        $this->travel(31)->minutes();
        $this->actingAs($this->candidateInA->user)->get('/portal/attempts/'.$attempt->id)->assertRedirect('/portal/attempts/'.$attempt->id.'/success');
        $this->assertSame('expired', $attempt->fresh()->status);
        $this->assertNull($attempt->fresh()->submitted_at);
    }

    public function test_withdrawn_candidates_have_no_listing_or_start_access(): void
    {
        $this->candidateInA->status = CandidateStatus::Withdrawn;
        $this->candidateInA->save();
        $this->actingAs($this->candidateInA->user)->get('/portal')->assertInertia(fn (Assert $page) => $page
            ->has('available.data', 0)
            ->has('upcoming.data', 0));
        $this->post('/portal/examinations/'.$this->exam->id.'/start')->assertForbidden();
    }
}
