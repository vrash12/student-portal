<?php

namespace Tests\Feature\QuestionBank;

use App\Models\AuditLog;
use App\Models\Question;
use App\Models\QuestionChoice;
use App\Services\QuestionBank\QuestionContent;
use App\Services\QuestionBank\QuestionLocking;
use App\Services\QuestionBank\QuestionPresenter;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Correct answers and explanations are staff-only
 * (docs/question-bank-examination-contract.md): hidden from model
 * serialization, absent from candidate payloads and list rows, sent only to
 * staff who teach the subject, and never written to the audit log.
 */
class QuestionConfidentialityTest extends TestCase
{
    use BuildsQuestionBankFixtures;

    private const PROMPT = 'Which sample option is right?';

    private const RIGHT_CHOICE = 'Right option text';

    private const WRONG_CHOICE = 'Wrong option text';

    private const EXPLANATION = 'Secret explanation of the answer';

    /** Subject 2, taught by Bravo only. */
    private Question $question;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildQuestionBankFixtures();

        $this->question = $this->createQuestion(
            $this->subject2,
            QuestionContent::multipleChoice(self::PROMPT, $this->choices([[self::WRONG_CHOICE, false], [self::RIGHT_CHOICE, true]])),
            topic: 'Topic 1',
            explanation: self::EXPLANATION,
            author: $this->bravo,
        );
    }

    public function test_models_never_serialize_correct_answers_or_the_explanation(): void
    {
        $question = Question::query()->with('choices')->findOrFail($this->question->id);

        $this->assertArrayNotHasKey('explanation', $question->toArray());
        $this->assertStringNotContainsString(self::EXPLANATION, $question->toJson());

        foreach ($question->toArray()['choices'] as $choice) {
            $this->assertArrayNotHasKey('is_correct', $choice);
            $this->assertArrayNotHasKey('correct_marker', $choice);
        }

        $choice = QuestionChoice::query()->where('question_id', $question->id)->where('position', 2)->sole();
        $this->assertTrue($choice->is_correct);
        $this->assertArrayNotHasKey('is_correct', $choice->toArray());
        $this->assertArrayNotHasKey('correct_marker', $choice->toArray());
    }

    public function test_the_candidate_view_contains_only_what_is_needed_to_answer(): void
    {
        $presenter = $this->app->make(QuestionPresenter::class);
        $choiceIds = $this->question->choices()->pluck('id')->all();

        $this->assertSame([
            'id' => $this->question->id,
            'type' => ['value' => 'multiple_choice', 'label' => 'Multiple Choice'],
            'prompt' => self::PROMPT,
            'choices' => [
                ['id' => $choiceIds[0], 'text' => self::WRONG_CHOICE, 'image' => null],
                ['id' => $choiceIds[1], 'text' => self::RIGHT_CHOICE, 'image' => null],
            ],
            'media' => [],
        ], $presenter->forCandidate($this->question->fresh()));

        $trueFalse = $this->createQuestion($this->subject2, QuestionContent::trueFalse('The sample statement is false.', false), author: $this->bravo);
        $view = $presenter->forCandidate($trueFalse->fresh());
        $this->assertSame(['id', 'type', 'prompt', 'choices', 'media'], array_keys($view));
        $this->assertSame([['id', 'text', 'image'], ['id', 'text', 'image']], array_map('array_keys', $view['choices']));
        $this->assertSame(['True', 'False'], array_column($view['choices'], 'text'));

        $essay = $this->createQuestion($this->subject2, QuestionContent::essay('Explain the sample.'), explanation: 'Essay grading notes', author: $this->bravo);
        $view = $presenter->forCandidate($essay->fresh());
        $this->assertSame([], $view['choices']);
        $this->assertDoesNotReveal($view, ['Essay grading notes', 'isCorrect', 'explanation', 'locked', 'topic', 'points']);
    }

    public function test_the_staff_view_has_the_answer_and_the_list_row_does_not(): void
    {
        $presenter = $this->app->make(QuestionPresenter::class);

        $staff = $presenter->staff($this->question->fresh());
        $this->assertSame(self::EXPLANATION, $staff['explanation']);
        $this->assertSame([false, true], array_column($staff['choices'], 'isCorrect'));

        $summary = $presenter->summary($this->question->fresh());
        $this->assertSame(['id', 'subject', 'topic', 'type', 'excerpt', 'points', 'isActive', 'isLocked'], array_keys($summary));
        $this->assertDoesNotReveal($summary, [self::RIGHT_CHOICE, self::WRONG_CHOICE, self::EXPLANATION, 'isCorrect']);
    }

    public function test_answers_and_explanations_reach_only_staff_who_teach_the_subject(): void
    {
        foreach (["/question-bank/{$this->question->id}", "/question-bank/{$this->question->id}/edit"] as $url) {
            $this->actingAs($this->bravo)
                ->get($url)
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page
                    ->where('question.explanation', self::EXPLANATION)
                    ->where('question.choices.1.text', self::RIGHT_CHOICE)
                    ->where('question.choices.1.isCorrect', true)
                    ->where('question.choices.0.isCorrect', false));

            // Alpha teaches Subject 1 only; administrators and candidates have no access.
            foreach ([$this->alpha, $this->academicAdmin, $this->superAdmin, $this->candidateInA->user] as $user) {
                $response = $this->actingAs($user)->get($url)->assertForbidden();

                $this->assertDoesNotReveal($response->getContent(), [self::PROMPT, self::RIGHT_CHOICE, self::WRONG_CHOICE, self::EXPLANATION]);
            }
        }

        // Nor do they appear in anybody else's question bank list.
        foreach ([$this->alpha, $this->bravo] as $user) {
            $response = $this->actingAs($user)->get('/question-bank')->assertOk();

            $this->assertDoesNotReveal($response->getContent(), [self::RIGHT_CHOICE, self::WRONG_CHOICE, self::EXPLANATION]);
        }
    }

    public function test_the_candidate_portal_contains_no_question_data(): void
    {
        // Batch A takes Subject 2, the subject of the question.
        $response = $this->actingAs($this->candidateInA->user)
            ->get('/portal')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('portal/home'));

        $this->assertDoesNotReveal($response->getContent(), [self::PROMPT, self::RIGHT_CHOICE, self::WRONG_CHOICE, self::EXPLANATION, 'isCorrect', 'is_correct']);
        // The home page lists examinations and results, never question content.
        $this->assertSame([], array_diff(
            array_keys($this->propsOf($response)),
            ['app', 'auth', 'errors', 'flash', 'summary', 'available', 'upcoming', 'performance', 'sections'],
        ));
        // Nor do the other portal pages (Physical Fitness included when it is shown).
        config(['institution.portal.show_fitness' => true]);
        foreach (['/portal/examinations', '/portal/grades', '/portal/performance', '/portal/fitness', '/portal/profile'] as $url) {
            $this->assertDoesNotReveal($this->get($url)->assertOk()->getContent(), [self::PROMPT, self::RIGHT_CHOICE, self::WRONG_CHOICE, self::EXPLANATION, 'isCorrect', 'is_correct']);
        }
    }

    public function test_audit_entries_never_contain_question_text_answers_or_explanations(): void
    {
        $this->actingAs($this->bravo);

        // Create through the form, then change every content field.
        $this->post('/question-bank', $this->multipleChoicePayload([
            'subject_id' => $this->subject2->id,
            'prompt' => 'Created prompt text?',
            'explanation' => 'Created explanation text',
            'choices' => $this->choices([['Created choice one', true], ['Created choice two', false]]),
        ]))->assertSessionHasNoErrors();
        $created = Question::query()->where('prompt', 'Created prompt text?')->sole();

        $this->put("/question-bank/{$created->id}", $this->multipleChoicePayload([
            'prompt' => 'Updated prompt text?',
            'explanation' => 'Updated explanation text',
            'choices' => $this->choices([['Updated choice one', false], ['Updated choice two', true]]),
        ]))->assertSessionHasNoErrors();

        $this->put("/question-bank/{$created->id}", [
            ...$this->multipleChoicePayload(['prompt' => 'Updated prompt text?', 'explanation' => 'Updated explanation text']),
            'type' => 'true_false',
            'correct_answer' => 'false',
        ])->assertSessionHasNoErrors();

        $this->post("/question-bank/{$created->id}/deactivate")->assertRedirect();
        $this->post("/question-bank/{$created->id}/activate")->assertRedirect();
        $this->post("/question-bank/{$this->question->id}/duplicate")->assertRedirect();

        DB::transaction(function (): void {
            $this->app->make(QuestionLocking::class)->lockRowsForPublication([$this->question->id]);
            $this->app->make(QuestionLocking::class)->lock([$this->question->id]);
        });
        $this->put("/question-bank/{$this->question->id}", ['topic' => 'Topic 2', 'points' => '2', 'explanation' => 'Locked explanation text'])
            ->assertSessionHasNoErrors();

        $entries = AuditLog::query()->where('action', 'like', 'question.%')->get();
        $this->assertGreaterThanOrEqual(8, $entries->count());

        $values = $entries->map(fn (AuditLog $entry): string => json_encode([$entry->old_values, $entry->new_values, $entry->reason]))->implode("\n");
        $this->assertDoesNotReveal($values, [
            self::PROMPT, self::RIGHT_CHOICE, self::WRONG_CHOICE, self::EXPLANATION,
            'Created prompt text', 'Created explanation text', 'Created choice one', 'Created choice two',
            'Updated prompt text', 'Updated explanation text', 'Updated choice one', 'Updated choice two',
            'Locked explanation text', 'Option A', 'Option B', '"True"', '"False"',
            // No value keys for content fields (their names only appear in the "changed" list).
            'is_correct', 'isCorrect', '"prompt":', '"explanation":', '"choices":', '"text":',
        ]);
        // Changed content is named, never shown.
        $this->assertTrue($entries->contains(fn (AuditLog $entry): bool => ($entry->new_values['changed'] ?? null) === ['prompt', 'choices', 'correct_choice', 'explanation']));
    }
}
