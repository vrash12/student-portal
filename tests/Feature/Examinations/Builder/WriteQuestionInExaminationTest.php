<?php

namespace Tests\Feature\Examinations\Builder;

use App\Enums\AuditAction;
use App\Models\AuditLog;
use App\Models\Examination;
use App\Models\ExaminationQuestion;
use App\Models\Question;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Write a New Question from an examination's Questions step (owner request,
 * 2026-10-03): the question is saved to the question bank of the
 * examination's subject and added as the draft's last question.
 */
class WriteQuestionInExaminationTest extends TestCase
{
    use BuildsExaminationBuilderFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildExaminationBuilderFixtures();
    }

    private function url(Examination $exam): string
    {
        return "/examinations/{$exam->id}/questions/new";
    }

    public function test_the_questions_step_offers_the_question_form_for_the_examinations_subject(): void
    {
        $exam = $this->draft();

        $this->actingAs($this->alpha)->get("/examinations/{$exam->id}/questions")->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('staff/examinations/questions')
                ->where('subject.id', $this->subject1->id)
                ->has('topics')->has('types', 3)->has('limits.maxChoices'));
    }

    public function test_a_written_question_is_saved_to_the_bank_and_added_as_the_last_question(): void
    {
        $exam = $this->readyDraft();

        $this->actingAs($this->alpha)->post($this->url($exam), $this->multipleChoicePayload(['prompt' => 'Written in the builder', 'points' => '3']))
            ->assertRedirect("/examinations/{$exam->id}/questions")->assertSessionHasNoErrors();

        $question = Question::query()->where('prompt', 'Written in the builder')->sole();
        $this->assertSame($this->subject1->id, $question->subject_id);
        $this->assertTrue($question->is_active);
        $this->assertSame($this->alpha->id, $question->created_by);
        $this->assertSame(4, $question->choices()->count());

        $row = ExaminationQuestion::query()->where('examination_id', $exam->id)->where('question_id', $question->id)->sole();
        $this->assertSame(3, $row->position);
        $this->assertSame('3.00', (string) $row->points);
        $this->assertSame(1, AuditLog::query()->where('action', AuditAction::QuestionCreated->value)->where('auditable_id', $question->id)->count());
        // The draft's first questions were one change; this question is the second.
        $this->assertSame(2, AuditLog::query()->where('action', AuditAction::ExaminationQuestionsUpdated->value)->where('auditable_id', $exam->id)->count());
    }

    public function test_true_false_and_essay_questions_can_be_written_too(): void
    {
        $exam = $this->draft();
        $this->actingAs($this->alpha);

        $this->post($this->url($exam), ['type' => 'true_false', 'prompt' => 'Synthetic statement.', 'correct_answer' => 'false', 'points' => '1'])->assertSessionHasNoErrors();
        $this->post($this->url($exam), ['type' => 'essay', 'prompt' => 'Explain the synthetic rule.', 'points' => '10'])->assertSessionHasNoErrors();

        $this->assertSame([1, 2], ExaminationQuestion::query()->where('examination_id', $exam->id)->orderBy('position')->pluck('position')->all());
    }

    public function test_the_subject_is_always_the_examinations_never_the_one_sent(): void
    {
        $exam = $this->draft();

        // Alpha teaches only Subject 1; Subject 2 is Bravo's.
        $this->actingAs($this->alpha)->post($this->url($exam), $this->multipleChoicePayload(['subject_id' => $this->subject2->id, 'prompt' => 'Sent with another subject']))
            ->assertSessionHasNoErrors();

        $this->assertSame($this->subject1->id, Question::query()->where('prompt', 'Sent with another subject')->value('subject_id'));
    }

    public function test_an_invalid_question_adds_nothing(): void
    {
        $exam = $this->draft();
        $before = Question::query()->count();

        $this->actingAs($this->alpha)->post($this->url($exam), $this->multipleChoicePayload(['prompt' => '', 'choices' => [['text' => 'Only one', 'is_correct' => true]]]))
            ->assertSessionHasErrors(['prompt', 'choices']);

        $this->assertSame($before, Question::query()->count());
        $this->assertSame(0, ExaminationQuestion::query()->where('examination_id', $exam->id)->count());
    }

    public function test_a_published_examination_takes_no_new_questions(): void
    {
        $exam = $this->publishedExam();
        $before = Question::query()->count();

        $this->actingAs($this->alpha)->post($this->url($exam), $this->multipleChoicePayload())
            ->assertSessionHasErrors(['examination' => 'Only draft examinations may be edited.']);

        $this->assertSame($before, Question::query()->count());
        $this->assertSame(2, ExaminationQuestion::query()->where('examination_id', $exam->id)->count());
    }

    public function test_instructors_of_other_classes_administrators_and_candidates_cannot_write_into_the_examination(): void
    {
        $exam = $this->draft();
        $before = Question::query()->count();

        // Bravo teaches Subject 1 too, but not this class.
        foreach ([$this->bravo, $this->academicAdmin, $this->superAdmin, $this->candidateInA->user] as $user) {
            $this->actingAs($user)->post($this->url($exam), $this->multipleChoicePayload())->assertForbidden();
        }

        $this->assertSame($before, Question::query()->count());
        $this->assertSame(0, ExaminationQuestion::query()->where('examination_id', $exam->id)->count());
    }
}
