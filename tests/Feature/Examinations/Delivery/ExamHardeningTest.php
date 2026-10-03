<?php

namespace Tests\Feature\Examinations\Delivery;

use App\Enums\AuditAction;
use App\Enums\CandidateStatus;
use App\Models\AuditLog;
use App\Models\ExaminationFocusEvent;
use App\Services\QuestionBank\QuestionMediaService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Milestone 17 fixes on the candidate side: resent saves without back
 * navigation, access-code guessing, separate rate limits, and withdrawn
 * candidates.
 */
class ExamHardeningTest extends TestCase
{
    use BuildsDeliveryFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildDeliveryFixtures();
    }

    public function test_a_resent_save_after_a_lost_response_is_accepted_without_back_navigation(): void
    {
        $exam = $this->makeExamination(['allow_back_navigation' => false]);
        foreach (range(1, 3) as $i) {
            $this->addItem($exam, $this->mcq());
        }
        $attempt = $this->startFor($this->candidateInA, $exam);
        $this->actingAs($this->candidateInA->user);
        $first = $attempt->delivery[0];
        $payload = ['revision' => 0, 'position' => 0, 'next_position' => 1, 'answer' => $this->correctChoiceId($first)];

        $this->putJson('/portal/attempts/'.$attempt->id.'/answers', $payload)->assertOk();
        // The response was lost; the tablet resends the same request.
        $this->putJson('/portal/attempts/'.$attempt->id.'/answers', $payload)->assertOk()->assertJsonPath('revision', 1);
        $this->assertSame(1, $attempt->fresh()->current_position);
        $this->assertSame(1, $attempt->fresh()->revision);

        // Saving continues normally, and going back is still refused.
        $this->putJson('/portal/attempts/'.$attempt->id.'/answers', ['revision' => 1, 'position' => 1, 'next_position' => 2, 'answer' => null])->assertOk();
        $this->putJson('/portal/attempts/'.$attempt->id.'/answers', ['revision' => 2, 'position' => 0, 'next_position' => 0, 'answer' => $this->wrongChoiceId($first)])
            ->assertUnprocessable()->assertJsonValidationErrors('position');
        // A different answer with the old revision is not mistaken for a resend.
        $this->putJson('/portal/attempts/'.$attempt->id.'/answers', [...$payload, 'answer' => $this->wrongChoiceId($first)])->assertUnprocessable();
        $this->assertSame($this->correctChoiceId($first), $attempt->fresh()->answers[$first['id']]['value']);
    }

    public function test_wrong_access_codes_are_recorded_without_the_code_and_starts_are_rate_limited(): void
    {
        $exam = $this->makeExamination(['access_code' => 'ROOM-4471']);
        $this->addItem($exam, $this->mcq());
        $other = $this->makeExamination();
        $this->addItem($other, $this->mcq());
        $this->actingAs($this->candidateInA->user);

        for ($i = 0; $i < 10; $i++) {
            $this->post("/portal/examinations/{$exam->id}/start", ['access_code' => "GUESS-{$i}"])->assertSessionHasErrors('access_code');
        }
        $this->post("/portal/examinations/{$exam->id}/start", ['access_code' => 'ROOM-4471'])->assertTooManyRequests();

        $entries = AuditLog::query()->where('action', AuditAction::ExaminationAccessCodeRejected->value)->get();
        $this->assertCount(10, $entries);
        $this->assertSame(['code_entered' => true], $entries->first()->new_values);
        $this->assertSame($this->candidateInA->user->id, $entries->first()->actor_id);
        $this->assertStringNotContainsString('GUESS-', AuditLog::query()->get()->toJson());

        // The limit is per examination.
        $this->post("/portal/examinations/{$other->id}/start")->assertRedirect();
    }

    public function test_after_the_attempt_the_access_code_is_not_checked(): void
    {
        $exam = $this->makeExamination(['access_code' => 'ROOM-4471']);
        $this->addItem($exam, $this->mcq());
        $attempt = $this->startFor($this->candidateInA, $exam, 'ROOM-4471');
        $attempt->forceFill(['status' => 'submitted', 'submitted_at' => now()])->save();

        $this->actingAs($this->candidateInA->user)
            ->post("/portal/examinations/{$exam->id}/start", ['access_code' => 'WRONG-GUESS'])
            ->assertSessionHasErrors(['examination' => 'You have already taken this examination.'])
            ->assertSessionDoesntHaveErrors('access_code');
        $this->assertSame(0, AuditLog::query()->where('action', AuditAction::ExaminationAccessCodeRejected->value)->count());
    }

    public function test_access_codes_need_four_characters(): void
    {
        $exam = $this->makeExamination(['status' => 'draft']);

        $this->actingAs($this->alpha)->put('/examinations/'.$exam->id, [
            'kind' => 'quiz', 'title' => 'Short code', 'duration_minutes' => 30, 'access_code' => 'ab1',
            'release_results' => false, 'randomize_questions' => false, 'randomize_choices' => false,
            'one_question_at_a_time' => false, 'allow_back_navigation' => true, 'auto_submit' => true,
        ])->assertSessionHasErrors('access_code');
    }

    public function test_screen_reports_do_not_use_up_the_document_download_limit(): void
    {
        $exam = $this->makeExamination();
        $this->addItem($exam, $this->mcq());
        $attempt = $this->startFor($this->candidateInA, $exam);
        $this->actingAs($this->candidateInA->user);

        for ($i = 0; $i < 12; $i++) {
            $this->postJson('/portal/attempts/'.$attempt->id.'/focus', ['event' => $i % 2 === 0 ? 'left' : 'returned', 'reason' => 'hidden'])->assertOk();
        }

        $this->get('/portal/profile/documents/registration')->assertOk();
    }

    public function test_a_withdrawn_candidate_cannot_record_screen_events_or_load_media(): void
    {
        Storage::fake('local');
        $question = $this->essay();
        $media = app(QuestionMediaService::class)->add($question, UploadedFile::fake()->image('figure.png', 50, 50), 'A figure.', $this->alpha);
        $exam = $this->makeExamination();
        $this->addItem($exam, $question);
        $attempt = $this->startFor($this->candidateInA, $exam);

        $this->candidateInA->forceFill(['status' => CandidateStatus::Withdrawn->value])->save();
        $this->actingAs($this->candidateInA->user->fresh());

        $this->postJson('/portal/attempts/'.$attempt->id.'/focus', ['event' => 'left', 'reason' => 'hidden'])->assertOk()->assertJsonPath('recorded', false);
        $this->get('/portal/attempts/'.$attempt->id.'/media/'.$media->id)->assertForbidden();
        $this->assertSame(0, ExaminationFocusEvent::query()->count());
    }

    public function test_screen_events_after_the_deadline_close_the_attempt_instead(): void
    {
        $exam = $this->makeExamination(['auto_submit' => true]);
        $this->addItem($exam, $this->mcq());
        $attempt = $this->startFor($this->candidateInA, $exam);
        $this->travel(31)->minutes();

        $this->actingAs($this->candidateInA->user)
            ->postJson('/portal/attempts/'.$attempt->id.'/focus', ['event' => 'left', 'reason' => 'hidden'])
            ->assertOk()->assertJsonPath('recorded', false)->assertJsonPath('status', 'submitted');
        $this->assertSame(0, ExaminationFocusEvent::query()->count());
    }
}
