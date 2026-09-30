<?php

namespace Tests\Feature\QuestionBank;

use App\Enums\AuditAction;
use App\Enums\QuestionType;
use App\Models\AuditLog;
use App\Models\Question;
use App\Models\QuestionChoice;
use App\Models\QuestionTopic;
use App\Services\QuestionBank\QuestionBankService;
use App\Services\QuestionBank\QuestionContent;
use App\Services\QuestionBank\QuestionData;
use App\Services\QuestionBank\QuestionLocking;
use App\Support\DecimalValue;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Editing questions: the edit page, what changes and what is audited, stable
 * choice rows, type changes, and the rules for locked questions (part of a
 * published examination), which keep their type, text, and answers.
 */
class QuestionEditTest extends TestCase
{
    use BuildsQuestionBankFixtures;

    private Question $question;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildQuestionBankFixtures();

        // Subject 1, Topic 1, choices A–D with B correct, 1 point.
        $this->question = $this->createQuestion($this->subject1, $this->multipleChoice(), topic: 'Topic 1', explanation: 'Because of the sample rule.');
    }

    // ---------------------------------------------------------------------
    // Edit page
    // ---------------------------------------------------------------------

    public function test_edit_page_shows_the_question_with_its_answer_and_the_subjects_topics(): void
    {
        $this->topic($this->subject1, 'Another Topic');
        $this->topic($this->subject2, 'Subject 2 Topic');
        $choices = $this->question->choices()->get();

        $this->actingAs($this->alpha)
            ->get("/question-bank/{$this->question->id}/edit")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('staff/question-bank/edit')
                ->where('question', [
                    'id' => $this->question->id,
                    'subject' => ['id' => $this->subject1->id, 'code' => 'SUBJ-1', 'name' => 'Subject 1'],
                    'topic' => ['id' => $this->question->question_topic_id, 'name' => 'Topic 1'],
                    'type' => ['value' => 'multiple_choice', 'label' => 'Multiple Choice'],
                    'prompt' => 'Which option is correct?',
                    'points' => '1',
                    'explanation' => 'Because of the sample rule.',
                    'isActive' => true,
                    'isLocked' => false,
                    'choices' => $choices->map(fn (QuestionChoice $choice): array => [
                        'id' => $choice->id,
                        'position' => $choice->position,
                        'label' => $choice->letter(),
                        'text' => $choice->text,
                        'isCorrect' => $choice->position === 2,
                    ])->all(),
                    'media' => [],
                ])
                ->where('topics', ['Another Topic', 'Topic 1'])
                ->where('types', QuestionType::options())
                ->has('limits'));
    }

    // ---------------------------------------------------------------------
    // Updates
    // ---------------------------------------------------------------------

    public function test_updating_saves_the_changes_and_audits_names_of_changed_content_without_its_values(): void
    {
        $this->travel(10)->minutes();

        $this->actingAs($this->bravo)
            ->put("/question-bank/{$this->question->id}", $this->payloadFor($this->question, [
                'topic' => 'Topic 2',
                'prompt' => 'Which option is right now?',
                'points' => '2',
                'explanation' => 'New explanation.',
                'choices' => $this->choices([['Option A', false], ['Option B', false], ['Option C', false], ['Option D', true]]),
            ]))
            ->assertSessionHasNoErrors()
            ->assertRedirect("/question-bank/{$this->question->id}")
            ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Question saved.']);

        $question = $this->question->fresh(['topic', 'choices']);
        $this->assertSame('Which option is right now?', $question->prompt);
        $this->assertSame('2.00', $question->points);
        $this->assertSame('Topic 2', $question->topic->name);
        $this->assertSame('New explanation.', $question->explanation);
        $this->assertSame(4, $question->choices->where('is_correct', true)->sole()->position);
        $this->assertSame($this->alpha->id, $question->created_by);
        $this->assertSame($this->bravo->id, $question->updated_by);
        $this->assertTrue($question->updated_at->greaterThan($question->created_at));

        $entry = AuditLog::query()->where('action', AuditAction::QuestionUpdated->value)->sole();
        $this->assertSame($this->bravo->id, $entry->actor_id);
        $this->assertSame('question', $entry->auditable_type);
        $this->assertSame($this->question->id, (int) $entry->auditable_id);
        $this->assertSame(['topic' => 'Topic 1', 'points' => '1.00'], $entry->old_values);
        $this->assertSame(['topic' => 'Topic 2', 'points' => '2.00', 'changed' => ['prompt', 'correct_choice', 'explanation']], $entry->new_values);
    }

    public function test_choice_rows_keep_their_ids_where_their_position_is_kept(): void
    {
        $ids = $this->question->choices()->pluck('id', 'position')->all();

        // Change B's text, move the correct answer from B to D, and add E.
        $this->actingAs($this->alpha)
            ->put("/question-bank/{$this->question->id}", $this->payloadFor($this->question, [
                'choices' => $this->choices([['Option A', false], ['Option B changed', false], ['Option C', false], ['Option D', true], ['Option E', false]]),
            ]))
            ->assertSessionHasNoErrors();

        $after = $this->question->choices()->get();
        $this->assertSame([$ids[1], $ids[2], $ids[3], $ids[4]], $after->take(4)->pluck('id')->all());
        $this->assertNotContains($after[4]->id, $ids);
        $this->assertSame(['Option A', 'Option B changed', 'Option C', 'Option D', 'Option E'], $after->pluck('text')->all());
        $this->assertSame([false, false, false, true, false], $after->pluck('is_correct')->all());
        $this->assertSame(['choices', 'correct_choice'], AuditLog::query()->where('action', AuditAction::QuestionUpdated->value)->sole()->new_values['changed']);

        // Remove C: the rows at positions 1-4 are kept with the new texts and position 5 is deleted.
        $this->actingAs($this->alpha)
            ->put("/question-bank/{$this->question->id}", $this->payloadFor($this->question, [
                'choices' => $this->choices([['Option A', true], ['Option B changed', false], ['Option D', false], ['Option E', false]]),
            ]))
            ->assertSessionHasNoErrors();

        $final = $this->question->choices()->get();
        $this->assertSame([$ids[1], $ids[2], $ids[3], $ids[4]], $final->pluck('id')->all());
        $this->assertSame([1, 2, 3, 4], $final->pluck('position')->all());
        $this->assertSame(['Option A', 'Option B changed', 'Option D', 'Option E'], $final->pluck('text')->all());
        $this->assertSame(1, $final->where('is_correct', true)->sole()->position);
        $this->assertSame(4, QuestionChoice::query()->where('question_id', $this->question->id)->count());
    }

    public function test_a_change_of_choices_alone_records_who_changed_the_question_and_when(): void
    {
        $this->actingAs($this->alpha)
            ->put("/question-bank/{$this->question->id}", $this->payloadFor($this->question, ['points' => '2']))
            ->assertSessionHasNoErrors();
        $firstEdit = $this->question->fresh()->updated_at;

        $this->travel(10)->minutes();
        $this->actingAs($this->alpha)
            ->put("/question-bank/{$this->question->id}", $this->payloadFor($this->question, [
                'choices' => $this->choices([['Option A', false], ['Option B', true], ['Option C', false], ['Option D changed', false]]),
            ]))
            ->assertSessionHasNoErrors();

        $question = $this->question->fresh();
        $this->assertSame($this->alpha->id, $question->updated_by);
        $this->assertTrue($question->updated_at->greaterThan($firstEdit));
        $this->assertSame(
            ['changed' => ['choices']],
            AuditLog::query()->where('action', AuditAction::QuestionUpdated->value)->latest('id')->firstOrFail()->new_values,
        );
    }

    public function test_changing_the_type_replaces_the_answer_choices(): void
    {
        $ids = $this->question->choices()->pluck('id', 'position')->all();

        // Multiple choice -> true / false: positions 1 and 2 become True and False.
        $this->actingAs($this->alpha)
            ->put("/question-bank/{$this->question->id}", $this->payloadFor($this->question, ['type' => 'true_false', 'correct_answer' => 'false', 'choices' => null]))
            ->assertSessionHasNoErrors();

        $question = $this->question->fresh('choices');
        $this->assertSame(QuestionType::TrueFalse, $question->type);
        $this->assertSame([$ids[1], $ids[2]], $question->choices->pluck('id')->all());
        $this->assertSame(['True', 'False'], $question->choices->pluck('text')->all());
        $this->assertSame([false, true], $question->choices->pluck('is_correct')->all());
        $entry = AuditLog::query()->where('action', AuditAction::QuestionUpdated->value)->latest('id')->firstOrFail();
        $this->assertSame(['type' => 'multiple_choice'], $entry->old_values);
        $this->assertSame(['type' => 'true_false', 'changed' => ['choices']], $entry->new_values);

        // True / false -> essay: no choices.
        $this->actingAs($this->alpha)
            ->put("/question-bank/{$this->question->id}", $this->payloadFor($question, ['type' => 'essay', 'correct_answer' => null]))
            ->assertSessionHasNoErrors();
        $question = $this->question->fresh('choices');
        $this->assertSame(QuestionType::Essay, $question->type);
        $this->assertCount(0, $question->choices);

        // Essay -> multiple choice: new choices.
        $this->actingAs($this->alpha)
            ->put("/question-bank/{$this->question->id}", $this->payloadFor($question, [
                'type' => 'multiple_choice',
                'choices' => $this->choices([['Yes', false], ['No', true]]),
            ]))
            ->assertSessionHasNoErrors();
        $question = $this->question->fresh('choices');
        $this->assertSame(QuestionType::MultipleChoice, $question->type);
        $this->assertSame(['Yes', 'No'], $question->choices->pluck('text')->all());
        $this->assertSame(2, $question->choices->where('is_correct', true)->sole()->position);
    }

    public function test_saving_without_changes_writes_nothing(): void
    {
        $this->travel(10)->minutes();
        $before = $this->question->fresh();
        $choiceRows = DB::table('question_choices')->where('question_id', $this->question->id)->orderBy('position')->get()->toArray();

        $variants = [
            $this->payloadFor($this->question),
            // The same values written differently.
            $this->payloadFor($this->question, ['points' => '1.00', 'topic' => 'topic 1', 'prompt' => '  Which option is correct?  ']),
        ];

        foreach ($variants as $payload) {
            $this->actingAs($this->alpha)
                ->put("/question-bank/{$this->question->id}", $payload)
                ->assertSessionHasNoErrors()
                ->assertRedirect("/question-bank/{$this->question->id}")
                ->assertInertiaFlash('toast', ['type' => 'info', 'message' => 'No changes to save.']);
        }

        $after = $this->question->fresh();
        $this->assertNull($after->updated_by);
        $this->assertEquals($before->updated_at, $after->updated_at);
        $this->assertSame($before->question_topic_id, $after->question_topic_id);
        $this->assertEquals($choiceRows, DB::table('question_choices')->where('question_id', $this->question->id)->orderBy('position')->get()->toArray());
        $this->assertSame(0, AuditLog::query()->where('action', AuditAction::QuestionUpdated->value)->count());
        $this->assertSame(1, QuestionTopic::query()->count());
    }

    public function test_the_subject_status_lock_and_authorship_cannot_be_changed_through_the_edit_form(): void
    {
        $this->actingAs($this->alpha)
            ->put("/question-bank/{$this->question->id}", $this->payloadFor($this->question, [
                'subject_id' => $this->subject2->id,
                'topic' => 'Topic 9',
                'points' => '4',
                'is_active' => false,
                'locked_at' => '2026-09-01 10:00:00',
                'created_by' => $this->bravo->id,
                'updated_by' => $this->bravo->id,
                'question_topic_id' => $this->topic($this->subject2, 'Topic 9')->id,
            ]))
            ->assertSessionHasNoErrors();

        $question = $this->question->fresh('topic');
        $this->assertSame($this->subject1->id, $question->subject_id);
        $this->assertSame($this->subject1->id, $question->topic->subject_id);
        $this->assertSame('Topic 9', $question->topic->name);
        $this->assertTrue($question->is_active);
        $this->assertNull($question->locked_at);
        $this->assertSame($this->alpha->id, $question->created_by);
        $this->assertSame($this->alpha->id, $question->updated_by);
        $this->assertSame('4.00', $question->points);
    }

    public function test_editing_reuses_another_existing_topic_of_the_subject_ignoring_case(): void
    {
        $topic2 = $this->topic($this->subject1, 'Topic 2');
        $this->topic($this->subject2, 'Topic 3');

        $this->actingAs($this->alpha)
            ->put("/question-bank/{$this->question->id}", $this->payloadFor($this->question, ['topic' => 'TOPIC 2']))
            ->assertSessionHasNoErrors();
        $this->assertSame($topic2->id, $this->question->fresh()->question_topic_id);

        // A topic name used only in another subject creates the topic in this one.
        $this->actingAs($this->alpha)
            ->put("/question-bank/{$this->question->id}", $this->payloadFor($this->question, ['topic' => 'Topic 3']))
            ->assertSessionHasNoErrors();
        $topic = $this->question->fresh('topic')->topic;
        $this->assertSame('Topic 3', $topic->name);
        $this->assertSame($this->subject1->id, $topic->subject_id);
        $this->assertSame(2, QuestionTopic::query()->where('name', 'Topic 3')->count());

        $entries = AuditLog::query()->where('action', AuditAction::QuestionUpdated->value)->orderBy('id')->get();
        $this->assertSame([['topic' => 'Topic 1'], ['topic' => 'Topic 2']], $entries->pluck('old_values')->all());
        $this->assertSame([['topic' => 'Topic 2'], ['topic' => 'Topic 3']], $entries->pluck('new_values')->all());
    }

    public function test_clearing_the_topic_leaves_the_question_without_a_topic(): void
    {
        $this->actingAs($this->alpha)
            ->put("/question-bank/{$this->question->id}", $this->payloadFor($this->question, ['topic' => '']))
            ->assertSessionHasNoErrors();

        $this->assertNull($this->question->fresh()->question_topic_id);
        $entry = AuditLog::query()->where('action', AuditAction::QuestionUpdated->value)->sole();
        $this->assertSame(['topic' => 'Topic 1'], $entry->old_values);
        $this->assertSame(['topic' => null], $entry->new_values);
    }

    public function test_updates_are_validated_like_new_questions(): void
    {
        $before = $this->snapshot();

        $invalid = [
            [['choices' => $this->choices([['Option A', true], ['Option B', true]])], ['choices' => QuestionBankService::CORRECT_CHOICE_MESSAGE]],
            [['choices' => $this->choices([['Option A', true]])], ['choices' => 'Add at least 2 choices.']],
            [['choices' => $this->choices([['Same', true], ['same', false]])], ['choices.1.text' => QuestionBankService::DUPLICATE_CHOICE_MESSAGE]],
            [['prompt' => ''], ['prompt' => 'Enter the question.']],
            [['points' => '0'], ['points' => QuestionBankService::POINTS_MESSAGE]],
            [['type' => 'matching'], 'type'],
            [['type' => 'true_false', 'correct_answer' => 'maybe'], ['correct_answer' => QuestionBankService::TRUE_FALSE_MESSAGE]],
            [['prompt' => ['text']], 'prompt'],
        ];

        foreach ($invalid as [$overrides, $errors]) {
            $this->actingAs($this->alpha)
                ->put("/question-bank/{$this->question->id}", $this->payloadFor($this->question, $overrides))
                ->assertStatus(302)
                ->assertSessionHasErrors($errors);
        }

        $this->assertSame($before, $this->snapshot());
    }

    // ---------------------------------------------------------------------
    // Locked questions
    // ---------------------------------------------------------------------

    public function test_a_locked_question_can_change_its_topic_points_and_explanation_without_sending_its_content(): void
    {
        $this->lock($this->question);
        $choices = $this->question->choices()->get()->toArray();

        $this->actingAs($this->alpha)
            ->get("/question-bank/{$this->question->id}/edit")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('question.isLocked', true));

        // What the edit form sends for a locked question.
        $this->actingAs($this->alpha)
            ->put("/question-bank/{$this->question->id}", ['topic' => 'Topic 2', 'points' => '3', 'explanation' => 'Updated notes.'])
            ->assertSessionHasNoErrors()
            ->assertRedirect("/question-bank/{$this->question->id}");

        $question = $this->question->fresh('topic');
        $this->assertSame('Topic 2', $question->topic->name);
        $this->assertSame('3.00', $question->points);
        $this->assertSame('Updated notes.', $question->explanation);
        $this->assertSame('Which option is correct?', $question->prompt);
        $this->assertSame(QuestionType::MultipleChoice, $question->type);
        $this->assertSame($choices, $this->question->choices()->get()->toArray());
        $this->assertNotNull($question->locked_at);
        $this->assertSame($this->alpha->id, $question->updated_by);

        $entry = AuditLog::query()->where('action', AuditAction::QuestionUpdated->value)->sole();
        $this->assertSame(['topic' => 'Topic 1', 'points' => '1.00'], $entry->old_values);
        $this->assertSame(['topic' => 'Topic 2', 'points' => '3.00', 'changed' => ['explanation']], $entry->new_values);
    }

    public function test_a_locked_question_accepts_its_content_when_it_is_unchanged(): void
    {
        $this->lock($this->question);

        $this->actingAs($this->alpha)
            ->put("/question-bank/{$this->question->id}", $this->payloadFor($this->question, ['points' => '5']))
            ->assertSessionHasNoErrors();

        $this->assertSame('5.00', $this->question->fresh()->points);
    }

    public function test_a_locked_question_rejects_any_change_to_its_type_text_or_answers(): void
    {
        $this->lock($this->question);
        $before = $this->snapshot();

        $changes = [
            'prompt' => ['prompt' => 'A different question?'],
            'type' => ['type' => 'essay'],
            'choice text' => ['choices' => $this->choices([['Option A', false], ['Option B', true], ['Option C', false], ['Option Z', false]])],
            'correct answer' => ['choices' => $this->choices([['Option A', true], ['Option B', false], ['Option C', false], ['Option D', false]])],
            'added choice' => ['choices' => $this->choices([['Option A', false], ['Option B', true], ['Option C', false], ['Option D', false], ['Option E', false]])],
            'choice order' => ['choices' => $this->choices([['Option B', true], ['Option A', false], ['Option C', false], ['Option D', false]])],
        ];

        foreach ($changes as $case => $overrides) {
            $this->actingAs($this->alpha)
                ->put("/question-bank/{$this->question->id}", $this->payloadFor($this->question, [...$overrides, 'points' => '9']))
                ->assertStatus(302)
                ->assertSessionHasErrors(['content' => QuestionBankService::LOCKED_CONTENT_MESSAGE]);

            // Nothing is saved, not even the points.
            $this->assertSame($before, $this->snapshot(), "Case [{$case}]");
        }
    }

    public function test_a_locked_true_false_question_rejects_a_flipped_answer(): void
    {
        $question = $this->createQuestion($this->subject1, QuestionContent::trueFalse('The sample statement is true.', true));
        $this->lock($question);

        $this->actingAs($this->alpha)
            ->put("/question-bank/{$question->id}", $this->payloadFor($question, ['correct_answer' => 'false']))
            ->assertSessionHasErrors(['content' => QuestionBankService::LOCKED_CONTENT_MESSAGE]);

        $this->assertSame([true, false], $question->choices()->pluck('is_correct')->all());
    }

    public function test_a_question_locked_after_the_form_was_opened_rejects_the_changed_content(): void
    {
        $this->actingAs($this->alpha)->get("/question-bank/{$this->question->id}/edit")->assertOk();

        // An examination with this question is published meanwhile.
        $this->lock($this->question);

        $this->actingAs($this->alpha)
            ->put("/question-bank/{$this->question->id}", $this->payloadFor($this->question, ['prompt' => 'Edited in a stale form?', 'points' => '2']))
            ->assertSessionHasErrors(['content' => QuestionBankService::LOCKED_CONTENT_MESSAGE]);

        $question = $this->question->fresh();
        $this->assertSame('Which option is correct?', $question->prompt);
        $this->assertSame('1.00', $question->points);
    }

    public function test_the_service_rejects_content_changes_to_a_locked_question_for_every_caller(): void
    {
        $this->lock($this->question);

        try {
            $this->actingAs($this->alpha);
            $this->service()->update($this->question, new QuestionData('Topic 1', '1', null, QuestionContent::essay('Changed?')), $this->alpha);
            $this->fail('A locked question accepted new content.');
        } catch (ValidationException $exception) {
            $this->assertSame(['content' => [QuestionBankService::LOCKED_CONTENT_MESSAGE]], $exception->errors());
        }

        $this->assertSame(QuestionType::MultipleChoice, $this->question->fresh()->type);
        $this->assertSame(4, $this->question->choices()->count());
    }

    // ---------------------------------------------------------------------
    // Row locking
    // ---------------------------------------------------------------------

    public function test_an_update_locks_the_question_and_its_choices_before_changing_them(): void
    {
        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->actingAs($this->alpha)
            ->put("/question-bank/{$this->question->id}", $this->payloadFor($this->question, ['prompt' => 'Locked for update?']))
            ->assertSessionHasNoErrors();

        $queries = array_column(DB::getQueryLog(), 'query');
        DB::disableQueryLog();

        $questionLock = $this->firstIndex($queries, fn (string $sql): bool => str_contains($sql, 'from `questions`') && str_ends_with($sql, 'for update'));
        $choiceLock = $this->firstIndex($queries, fn (string $sql): bool => str_contains($sql, 'from `question_choices`') && str_ends_with($sql, 'for update'));
        $questionUpdate = $this->firstIndex($queries, fn (string $sql): bool => str_starts_with($sql, 'update `questions`'));

        $this->assertNotNull($questionLock, 'The question row must be locked.');
        $this->assertNotNull($choiceLock, 'The choice rows must be locked.');
        $this->assertNotNull($questionUpdate);
        $this->assertLessThan($questionUpdate, $questionLock);
        $this->assertLessThan($questionUpdate, $choiceLock);
    }

    /**
     * The edit form payload of a stored question, with overrides. Without
     * overrides it changes nothing.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payloadFor(Question $question, array $overrides = []): array
    {
        $question = $question->fresh(['choices', 'topic']);

        $payload = [
            'topic' => $question->topic?->name,
            'type' => $question->type->value,
            'prompt' => $question->prompt,
            'points' => DecimalValue::display($question->points),
            'explanation' => $question->explanation,
        ];

        if ($question->type === QuestionType::MultipleChoice) {
            $payload['choices'] = $question->choices->map(fn (QuestionChoice $choice): array => ['text' => $choice->text, 'is_correct' => $choice->is_correct])->all();
        }

        if ($question->type === QuestionType::TrueFalse) {
            $payload['correct_answer'] = $question->choices->firstOrFail()->is_correct ? 'true' : 'false';
        }

        return [...$payload, ...$overrides];
    }

    private function lock(Question $question): void
    {
        DB::transaction(function () use ($question): void {
            $locking = $this->app->make(QuestionLocking::class);
            $locking->lockRowsForPublication([$question->id]);
            $locking->lock([$question->id]);
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(): array
    {
        $question = $this->question->fresh(['choices']);

        return [
            'question' => $question->only(['type', 'prompt', 'points', 'question_topic_id', 'is_active', 'updated_by']),
            'explanation' => $question->explanation,
            'updated_at' => $question->updated_at?->toIso8601String(),
            'choices' => $question->choices->map(fn (QuestionChoice $choice): array => $choice->only(['id', 'position', 'text', 'is_correct']))->all(),
            'audits' => AuditLog::query()->where('action', AuditAction::QuestionUpdated->value)->count(),
        ];
    }

    /**
     * @param  list<string>  $queries
     */
    private function firstIndex(array $queries, callable $matches): ?int
    {
        foreach ($queries as $index => $sql) {
            if ($matches($sql)) {
                return $index;
            }
        }

        return null;
    }
}
