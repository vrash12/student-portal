<?php

namespace Tests\Feature\QuestionBank;

use App\Models\Question;
use Tests\TestCase;

/**
 * Milestone 18: after saving, "Save and Add Another" returns to a new form
 * for the same subject; the normal save opens the saved question.
 */
class QuestionAuthoringFlowTest extends TestCase
{
    use BuildsQuestionBankFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildQuestionBankFixtures();
    }

    public function test_save_and_add_another_returns_to_a_new_form_for_the_same_subject(): void
    {
        $this->actingAs($this->alpha)
            ->post('/question-bank', $this->multipleChoicePayload(['add_another' => true]))
            ->assertRedirect('/question-bank/create?subject='.$this->subject1->id)
            ->assertSessionHasNoErrors();

        $question = Question::query()->sole();
        $this->assertSame($this->subject1->id, $question->subject_id);
    }

    public function test_a_normal_save_opens_the_saved_question(): void
    {
        $this->actingAs($this->alpha)->post('/question-bank', $this->multipleChoicePayload());

        $this->assertSame(1, Question::query()->count());
        $this->actingAs($this->alpha)
            ->post('/question-bank', $this->multipleChoicePayload(['prompt' => 'A second question?']))
            ->assertRedirect('/question-bank/'.Question::query()->latest('id')->value('id'));
    }

    public function test_an_invalid_question_is_not_saved_even_with_add_another(): void
    {
        $this->actingAs($this->alpha)
            ->from('/question-bank/create')
            ->post('/question-bank', $this->multipleChoicePayload(['prompt' => '', 'add_another' => true]))
            ->assertRedirect('/question-bank/create')
            ->assertSessionHasErrors('prompt');

        $this->assertSame(0, Question::query()->count());
    }
}
