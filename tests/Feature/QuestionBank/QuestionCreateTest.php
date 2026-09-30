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
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Adding questions (UI_UX_DESIGN.md §55): the create page, each question type,
 * the subject and topic rules, tampering, and validation of every field.
 */
class QuestionCreateTest extends TestCase
{
    use BuildsQuestionBankFixtures;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildQuestionBankFixtures();
    }

    // ---------------------------------------------------------------------
    // Create page
    // ---------------------------------------------------------------------

    public function test_create_page_offers_the_taught_subjects_with_their_topics_and_the_form_limits(): void
    {
        $this->topic($this->subject1, 'Topic B');
        $this->topic($this->subject1, 'Topic A');
        $this->topic($this->subject2, 'Subject 2 Topic');

        $this->actingAs($this->alpha)
            ->get('/question-bank/create')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('staff/question-bank/create')
                ->where('subjects', [['id' => $this->subject1->id, 'code' => 'SUBJ-1', 'name' => 'Subject 1', 'topics' => ['Topic A', 'Topic B']]])
                // The only subject Alpha teaches is preselected.
                ->where('selectedSubjectId', $this->subject1->id)
                ->where('types', QuestionType::options())
                ->where('limits', [
                    'minChoices' => 2,
                    'maxChoices' => 6,
                    'promptLength' => 5000,
                    'choiceLength' => 1000,
                    'topicLength' => 100,
                    'explanationLength' => 5000,
                ]));
    }

    public function test_create_page_preselects_the_requested_subject_only_when_the_user_teaches_it(): void
    {
        // Bravo teaches Subjects 1 and 2.
        $selected = fn (string $query): mixed => $this->propsOf($this->actingAs($this->bravo)->get('/question-bank/create'.$query))['selectedSubjectId'];

        $this->assertSame($this->subject2->id, $selected('?subject='.$this->subject2->id));
        $this->assertNull($selected(''));
        $this->assertNull($selected('?subject='.$this->subject3->id));
        $this->assertNull($selected('?subject=abc'));
        $this->assertNull($selected('?subject[]='.$this->subject1->id));

        $this->assertSame(
            ['Subject 1', 'Subject 2'],
            array_column($this->propsOf($this->actingAs($this->bravo)->get('/question-bank/create'))['subjects'], 'name'),
        );
    }

    // ---------------------------------------------------------------------
    // Each question type
    // ---------------------------------------------------------------------

    public function test_instructor_adds_a_multiple_choice_question(): void
    {
        $response = $this->actingAs($this->alpha)->post('/question-bank', $this->multipleChoicePayload(['points' => '2.5']));

        $question = Question::query()->with(['choices', 'topic'])->sole();
        $response->assertSessionHasNoErrors()
            ->assertRedirect("/question-bank/{$question->id}")
            ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Question saved.']);

        $this->assertSame($this->subject1->id, $question->subject_id);
        $this->assertSame('Topic 1', $question->topic->name);
        $this->assertSame($this->subject1->id, $question->topic->subject_id);
        $this->assertSame(QuestionType::MultipleChoice, $question->type);
        $this->assertSame('Which option is correct?', $question->prompt);
        $this->assertSame('2.50', $question->points);
        $this->assertSame('Option B is correct because of the sample rule.', $question->explanation);
        $this->assertTrue($question->is_active);
        $this->assertNull($question->locked_at);
        $this->assertSame($this->alpha->id, $question->created_by);
        $this->assertNull($question->updated_by);
        $this->assertSame(
            [[1, 'Option A', false], [2, 'Option B', true], [3, 'Option C', false], [4, 'Option D', false]],
            $this->choiceRows($question),
        );

        $entry = AuditLog::query()->where('action', AuditAction::QuestionCreated->value)->sole();
        $this->assertSame($this->alpha->id, $entry->actor_id);
        $this->assertSame('question', $entry->auditable_type);
        $this->assertSame($question->id, (int) $entry->auditable_id);
        $this->assertNull($entry->old_values);
        $this->assertSame([
            'subject_id' => $this->subject1->id,
            'topic' => 'Topic 1',
            'type' => 'multiple_choice',
            'points' => '2.50',
            'is_active' => true,
            'choice_count' => 4,
        ], $entry->new_values);
    }

    public function test_instructor_adds_true_false_questions_with_either_answer(): void
    {
        foreach (['true' => [true, false], 'false' => [false, true]] as $answer => [$trueIsCorrect, $falseIsCorrect]) {
            $this->actingAs($this->alpha)
                ->post('/question-bank', [
                    'subject_id' => $this->subject1->id,
                    'type' => 'true_false',
                    'prompt' => "The sample statement is {$answer}.",
                    'points' => '1',
                    'correct_answer' => $answer,
                    // Choices sent for a true / false question are ignored.
                    'choices' => $this->choices([['Maybe', true], ['Never', true], ['Sometimes', false]]),
                ])
                ->assertSessionHasNoErrors();

            $question = Question::query()->where('prompt', "The sample statement is {$answer}.")->sole();
            $this->assertSame(QuestionType::TrueFalse, $question->type);
            $this->assertSame([[1, 'True', $trueIsCorrect], [2, 'False', $falseIsCorrect]], $this->choiceRows($question));
        }
    }

    public function test_instructor_adds_an_essay_question_and_choices_sent_with_it_are_ignored(): void
    {
        $this->actingAs($this->alpha)
            ->post('/question-bank', [
                'subject_id' => $this->subject1->id,
                'type' => 'essay',
                'prompt' => 'Explain the sample idea.',
                'points' => '10',
                'explanation' => 'Grading guidance.',
                // Even invalid choices (seven of them, several correct) are ignored for essays.
                'choices' => array_fill(0, 7, ['text' => 'Ignored', 'is_correct' => true]),
                'correct_answer' => 'true',
            ])
            ->assertSessionHasNoErrors();

        $question = Question::query()->sole();
        $this->assertSame(QuestionType::Essay, $question->type);
        $this->assertSame('10.00', $question->points);
        $this->assertSame(0, $question->choices()->count());
        $this->assertSame(0, AuditLog::query()->where('action', AuditAction::QuestionCreated->value)->sole()->new_values['choice_count']);
    }

    public function test_a_multiple_choice_question_ignores_a_true_false_answer(): void
    {
        $this->actingAs($this->alpha)
            ->post('/question-bank', $this->multipleChoicePayload(['correct_answer' => 'false']))
            ->assertSessionHasNoErrors();

        $this->assertSame(
            [[1, 'Option A', false], [2, 'Option B', true], [3, 'Option C', false], [4, 'Option D', false]],
            $this->choiceRows(Question::query()->sole()),
        );
    }

    public function test_text_keeps_its_line_breaks_and_loses_surrounding_whitespace(): void
    {
        $this->actingAs($this->alpha)
            ->post('/question-bank', $this->multipleChoicePayload([
                'prompt' => "  First line\r\nSecond line\rThird line\n\n  ",
                'topic' => '   ',
                'explanation' => "  Why:\r\n  because.  ",
                'choices' => $this->choices([['  Option A  ', false], ["\tOption B", true]]),
            ]))
            ->assertSessionHasNoErrors();

        $question = Question::query()->sole();
        $this->assertSame("First line\nSecond line\nThird line", $question->prompt);
        $this->assertSame("Why:\n  because.", $question->explanation);
        $this->assertNull($question->question_topic_id);
        $this->assertSame([[1, 'Option A', false], [2, 'Option B', true]], $this->choiceRows($question));
        $this->assertSame(0, QuestionTopic::query()->count());
    }

    // ---------------------------------------------------------------------
    // Subject and topic
    // ---------------------------------------------------------------------

    public function test_questions_can_only_be_added_to_a_subject_the_user_teaches(): void
    {
        $cases = [
            [$this->subject2->id, 'Select one of the subjects you teach.'],
            [$this->subject3->id, 'Select one of the subjects you teach.'],
            [999999, 'Select one of the subjects you teach.'],
            ['abc', 'Select one of the subjects you teach.'],
            [[$this->subject1->id], 'Select one of the subjects you teach.'],
            [null, 'Select the subject this question belongs to.'],
        ];

        foreach ($cases as [$subjectId, $message]) {
            $this->actingAs($this->alpha)
                ->post('/question-bank', $this->multipleChoicePayload(['subject_id' => $subjectId]))
                ->assertSessionHasErrors(['subject_id' => $message]);
        }

        $this->assertSame(0, Question::query()->count());
        $this->assertSame(0, QuestionTopic::query()->count());
    }

    public function test_submitted_ownership_status_lock_and_topic_id_fields_are_ignored(): void
    {
        $otherTopic = $this->topic($this->subject2, 'Topic 1');

        $this->actingAs($this->alpha)
            ->post('/question-bank', $this->multipleChoicePayload([
                'id' => 424242,
                'is_active' => false,
                'locked_at' => '2026-09-01 10:00:00',
                'created_by' => $this->bravo->id,
                'updated_by' => $this->bravo->id,
                'question_topic_id' => $otherTopic->id,
                'correct_marker' => 1,
            ]))
            ->assertSessionHasNoErrors();

        $question = Question::query()->sole();
        $this->assertNotSame(424242, $question->id);
        $this->assertTrue($question->is_active);
        $this->assertNull($question->locked_at);
        $this->assertSame($this->alpha->id, $question->created_by);
        $this->assertNull($question->updated_by);
        // The topic name is resolved in the question's own subject.
        $this->assertNotSame($otherTopic->id, $question->question_topic_id);
        $this->assertSame($this->subject1->id, QuestionTopic::query()->findOrFail($question->question_topic_id)->subject_id);
    }

    public function test_an_existing_topic_is_reused_ignoring_case_and_a_new_name_creates_it_in_the_same_subject(): void
    {
        $existing = $this->topic($this->subject1, 'Topic 1');

        foreach (['topic 1', 'TOPIC 1', '  Topic 1  '] as $index => $name) {
            $this->actingAs($this->alpha)
                ->post('/question-bank', $this->multipleChoicePayload(['topic' => $name, 'prompt' => "Reuse {$index}?"]))
                ->assertSessionHasNoErrors();

            $this->assertSame($existing->id, Question::query()->where('prompt', "Reuse {$index}?")->sole()->question_topic_id);
        }
        $this->assertSame(1, QuestionTopic::query()->count());
        $this->assertSame(['Topic 1'], AuditLog::query()->where('action', AuditAction::QuestionCreated->value)->get()->pluck('new_values.topic')->unique()->values()->all());

        $this->actingAs($this->alpha)
            ->post('/question-bank', $this->multipleChoicePayload(['topic' => 'Topic 3', 'prompt' => 'New topic?']))
            ->assertSessionHasNoErrors();
        $created = QuestionTopic::query()->where('name', 'Topic 3')->sole();
        $this->assertSame($this->subject1->id, $created->subject_id);

        // The same name in another subject is a different topic.
        $this->actingAs($this->bravo)
            ->post('/question-bank', $this->multipleChoicePayload(['subject_id' => $this->subject2->id, 'topic' => 'topic 1', 'prompt' => 'Other subject?']))
            ->assertSessionHasNoErrors();
        $otherSubjectTopic = QuestionTopic::query()->where('subject_id', $this->subject2->id)->sole();
        $this->assertSame('topic 1', $otherSubjectTopic->name);
        $this->assertSame($otherSubjectTopic->id, Question::query()->where('prompt', 'Other subject?')->sole()->question_topic_id);
    }

    // ---------------------------------------------------------------------
    // Validation: multiple choice
    // ---------------------------------------------------------------------

    public function test_multiple_choice_needs_two_to_six_choices(): void
    {
        $choice = fn (int $number, bool $correct = false): array => ['text' => "Option {$number}", 'is_correct' => $correct];

        $this->assertRejected(['choices' => [$choice(1, true)]], ['choices' => 'Add at least 2 choices.']);
        $this->assertRejected(['choices' => array_map(fn (int $n): array => $choice($n, $n === 1), range(1, 7))], ['choices' => 'Use at most 6 choices.']);
        $this->assertRejected(['choices' => []], ['choices' => QuestionBankService::CHOICE_COUNT_MESSAGE]);
        $this->assertRejected(['choices' => null], ['choices' => QuestionBankService::CHOICE_COUNT_MESSAGE]);

        foreach ([2, 6] as $count) {
            $this->actingAs($this->alpha)
                ->post('/question-bank', $this->multipleChoicePayload([
                    'prompt' => "With {$count} choices?",
                    'choices' => array_map(fn (int $n): array => $choice($n, $n === $count), range(1, $count)),
                ]))
                ->assertSessionHasNoErrors();

            $question = Question::query()->where('prompt', "With {$count} choices?")->sole();
            $this->assertSame($count, $question->choices()->count());
            $this->assertSame($count, $question->choices()->where('is_correct', true)->sole()->position);
        }
    }

    public function test_multiple_choice_needs_exactly_one_correct_choice(): void
    {
        $this->assertRejected(
            ['choices' => $this->choices([['Option A', false], ['Option B', false], ['Option C', false]])],
            ['choices' => QuestionBankService::CORRECT_CHOICE_MESSAGE],
        );
        $this->assertRejected(
            ['choices' => $this->choices([['Option A', true], ['Option B', true], ['Option C', false]])],
            ['choices' => QuestionBankService::CORRECT_CHOICE_MESSAGE],
        );
        // A missing flag means "not correct".
        $this->assertRejected(
            ['choices' => [['text' => 'Option A'], ['text' => 'Option B']]],
            ['choices' => QuestionBankService::CORRECT_CHOICE_MESSAGE],
        );
    }

    public function test_choice_texts_are_required_and_limited_in_length(): void
    {
        $this->assertRejected(
            ['choices' => $this->choices([['Option A', true], ['', false], ['Option C', false]])],
            ['choices.1.text' => QuestionBankService::EMPTY_CHOICE_MESSAGE],
        );
        $this->assertRejected(
            ['choices' => $this->choices([['Option A', true], ['   ', false]])],
            ['choices.1.text' => QuestionBankService::EMPTY_CHOICE_MESSAGE],
        );
        $this->assertRejected(
            ['choices' => $this->choices([['Option A', true], [str_repeat('b', 1001), false]])],
            ['choices.1.text' => 'Use at most 1,000 characters.'],
        );

        $this->actingAs($this->alpha)
            ->post('/question-bank', $this->multipleChoicePayload(['choices' => $this->choices([['Option A', true], [str_repeat('b', 1000), false]])]))
            ->assertSessionHasNoErrors();
        $this->assertSame(1000, mb_strlen(QuestionChoice::query()->where('position', 2)->sole()->text));
    }

    public function test_choice_texts_must_be_distinct_ignoring_case_and_surrounding_spaces(): void
    {
        $this->assertRejected(
            ['choices' => $this->choices([['Option A', true], ['Option B', false], ['  option a ', false]])],
            ['choices.0.text' => QuestionBankService::DUPLICATE_CHOICE_MESSAGE, 'choices.2.text' => QuestionBankService::DUPLICATE_CHOICE_MESSAGE],
        );

        $this->actingAs($this->alpha)
            ->post('/question-bank', $this->multipleChoicePayload(['choices' => $this->choices([['Option A', true], ['Option A.', false]])]))
            ->assertSessionHasNoErrors()
            ->assertSessionDoesntHaveErrors(['choices.0.text', 'choices.1.text']);
    }

    public function test_malformed_choices_are_rejected_without_server_errors(): void
    {
        $unreadable = 'The answer choices could not be read. Reload the page and try again.';

        $this->assertRejected(['choices' => 'Option A, Option B'], ['choices' => $unreadable]);
        $this->assertRejected(['choices' => ['Option A', 'Option B']], ['choices.0' => $unreadable, 'choices.1' => $unreadable]);
        $this->assertRejected(['choices' => [5 => ['text' => 'Option A', 'is_correct' => true], 9 => ['text' => 'Option B', 'is_correct' => false]]], ['choices' => $unreadable]);
        $this->assertRejected(
            ['choices' => [['id' => 5, 'text' => 'Option A', 'is_correct' => true], ['text' => 'Option B', 'is_correct' => false]]],
            ['choices.0' => $unreadable],
        );
        $this->assertRejected(
            ['choices' => [['text' => ['Option A'], 'is_correct' => true], ['text' => 'Option B', 'is_correct' => false]]],
            ['choices.0.text' => 'Enter the choice as text.'],
        );
        $this->assertRejected(
            ['choices' => [['text' => 'Option A', 'is_correct' => 'yes'], ['text' => 'Option B', 'is_correct' => [true]]]],
            ['choices.0.is_correct' => $unreadable, 'choices.1.is_correct' => $unreadable],
        );
    }

    // ---------------------------------------------------------------------
    // Validation: true / false, type, and the other fields
    // ---------------------------------------------------------------------

    public function test_true_false_needs_its_correct_answer(): void
    {
        $base = ['type' => 'true_false', 'choices' => null];

        foreach ([null, '', 'yes', 'TRUE', true, ['true']] as $answer) {
            $this->assertRejected([...$base, 'correct_answer' => $answer], ['correct_answer' => QuestionBankService::TRUE_FALSE_MESSAGE]);
        }
    }

    public function test_type_is_required_and_must_be_a_known_type(): void
    {
        $this->assertRejected(['type' => null], ['type' => 'Select a question type.']);

        foreach (['matching', 'MULTIPLE_CHOICE', ['essay'], 5] as $type) {
            $this->assertRejected(['type' => $type], 'type');
        }
    }

    public function test_the_question_text_is_required_and_limited_to_five_thousand_characters(): void
    {
        $this->assertRejected(['prompt' => null], ['prompt' => 'Enter the question.']);
        $this->assertRejected(['prompt' => "  \n  "], ['prompt' => 'Enter the question.']);
        $this->assertRejected(['prompt' => ['Which option?']], ['prompt' => 'Enter the question as text.']);
        $this->assertRejected(['prompt' => str_repeat('q', 5001)], ['prompt' => 'Use at most 5,000 characters.']);

        $this->actingAs($this->alpha)->post('/question-bank', $this->multipleChoicePayload(['prompt' => str_repeat('q', 5000)]))->assertSessionHasNoErrors();
        $this->assertSame(5000, mb_strlen(Question::query()->sole()->prompt));
    }

    public function test_points_are_required_from_0_01_to_100_with_at_most_two_decimals(): void
    {
        foreach (['', '0', '0.00', '-1', '100.01', '101', '1.005', 'abc', '1e2', ['1']] as $points) {
            $this->assertRejected(['points' => $points], 'points');
        }
        $this->assertRejected(['points' => null], ['points' => 'Enter the points, for example 1.']);
        $this->assertRejected(['points' => '0'], ['points' => QuestionBankService::POINTS_MESSAGE]);
        $this->assertRejected(['points' => '1.005'], ['points' => 'Use at most two decimal places.']);

        foreach (['0.01' => '0.01', '100' => '100.00', '2.5' => '2.50', '99.99' => '99.99', '7' => '7.00'] as $points => $stored) {
            $this->actingAs($this->alpha)
                ->post('/question-bank', $this->multipleChoicePayload(['points' => $points, 'prompt' => "Worth {$points}?"]))
                ->assertSessionHasNoErrors();

            $this->assertSame($stored, Question::query()->where('prompt', "Worth {$points}?")->sole()->points);
        }
    }

    public function test_topic_and_explanation_are_optional_text_with_length_limits(): void
    {
        $this->assertRejected(['topic' => str_repeat('t', 101)], ['topic' => 'Use at most 100 characters for the topic.']);
        $this->assertRejected(['topic' => ['Topic 1']], ['topic' => 'Enter the topic as text.']);
        $this->assertRejected(['explanation' => str_repeat('e', 5001)], ['explanation' => 'Use at most 5,000 characters.']);
        $this->assertRejected(['explanation' => ['Because.']], ['explanation' => 'Enter the explanation as text.']);

        $this->actingAs($this->alpha)
            ->post('/question-bank', $this->multipleChoicePayload(['topic' => str_repeat('t', 100), 'explanation' => str_repeat('e', 5000)]))
            ->assertSessionHasNoErrors();
        $question = Question::query()->with('topic')->sole();
        $this->assertSame(100, mb_strlen($question->topic->name));
        $this->assertSame(5000, mb_strlen((string) $question->explanation));

        $this->actingAs($this->alpha)
            ->post('/question-bank', $this->multipleChoicePayload(['prompt' => 'No topic or explanation?', 'topic' => null, 'explanation' => null]))
            ->assertSessionHasNoErrors();
        $plain = Question::query()->where('prompt', 'No topic or explanation?')->sole();
        $this->assertNull($plain->question_topic_id);
        $this->assertNull($plain->explanation);
    }

    public function test_arrays_in_place_of_text_are_validation_errors_not_server_errors(): void
    {
        $this->actingAs($this->alpha)
            ->post('/question-bank', [
                'subject_id' => [$this->subject1->id],
                'topic' => ['a'],
                'type' => ['multiple_choice'],
                'prompt' => ['b'],
                'points' => ['1'],
                'explanation' => ['c'],
                'choices' => [['text' => ['d'], 'is_correct' => ['e']]],
                'correct_answer' => ['true'],
            ])
            ->assertStatus(302)
            ->assertSessionHasErrors(['subject_id', 'topic', 'type', 'prompt', 'points', 'explanation']);

        $this->assertSame(0, Question::query()->count());
    }

    // ---------------------------------------------------------------------
    // The service enforces the rules for every caller
    // ---------------------------------------------------------------------

    public function test_the_service_enforces_the_question_rules_behind_the_form_request(): void
    {
        $invalid = [
            'one choice' => [$this->multipleChoice(count: 1, correct: 1), 'choices'],
            'seven choices' => [$this->multipleChoice(count: 7, correct: 1), 'choices'],
            'no correct choice' => [$this->multipleChoice(correct: 0), 'choices'],
            'two correct choices' => [QuestionContent::multipleChoice('Q?', $this->choices([['A', true], ['B', true]])), 'choices'],
            'duplicate texts' => [QuestionContent::multipleChoice('Q?', $this->choices([['Same', true], ['same', false]])), 'choices.1.text'],
            'empty choice' => [QuestionContent::multipleChoice('Q?', $this->choices([['A', true], ['  ', false]])), 'choices.1.text'],
            'long choice' => [QuestionContent::multipleChoice('Q?', $this->choices([['A', true], [str_repeat('b', 1001), false]])), 'choices.1.text'],
            'empty prompt' => [QuestionContent::essay('   '), 'prompt'],
            'long prompt' => [QuestionContent::essay(str_repeat('p', 5001)), 'prompt'],
        ];

        foreach ($invalid as $case => [$content, $errorKey]) {
            $this->assertServiceRejects(new QuestionData(null, '1', null, $content), $errorKey, $case);
        }

        foreach (['0', '0.00', '-1', '100.01', 'abc'] as $points) {
            $this->assertServiceRejects(new QuestionData(null, $points, null, QuestionContent::essay('Q?')), 'points', "points {$points}");
        }
        $this->assertServiceRejects(new QuestionData(str_repeat('t', 101), '1', null, QuestionContent::essay('Q?')), 'topic', 'long topic');
        $this->assertServiceRejects(new QuestionData(null, '1', str_repeat('e', 5001), QuestionContent::essay('Q?')), 'explanation', 'long explanation');

        $this->assertSame(0, Question::query()->count());
        $this->assertSame(0, QuestionTopic::query()->count());
        $this->assertSame(0, AuditLog::query()->where('action', 'like', 'question.%')->count());
    }

    /**
     * Posts the multiple choice payload with the overrides and expects the
     * errors, with nothing stored.
     *
     * @param  array<string, mixed>  $overrides
     * @param  array<string, string>|string  $errors
     */
    private function assertRejected(array $overrides, array|string $errors): void
    {
        $before = Question::query()->count();

        $this->actingAs($this->alpha)
            ->post('/question-bank', $this->multipleChoicePayload($overrides))
            ->assertStatus(302)
            ->assertSessionHasErrors($errors);

        $this->assertSame($before, Question::query()->count());
    }

    private function assertServiceRejects(QuestionData $data, string $errorKey, string $case): void
    {
        try {
            $this->service()->create($this->subject1, $data, $this->alpha);
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey($errorKey, $exception->errors(), "Case [{$case}]");

            return;
        }

        $this->fail("The service accepted an invalid question [{$case}].");
    }

    /**
     * @return list<array{int, string, bool}> [position, text, is correct]
     */
    private function choiceRows(Question $question): array
    {
        return $question->choices()
            ->get()
            ->map(fn (QuestionChoice $choice): array => [$choice->position, $choice->text, $choice->is_correct])
            ->all();
    }
}
