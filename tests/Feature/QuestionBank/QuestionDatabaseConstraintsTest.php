<?php

namespace Tests\Feature\QuestionBank;

use App\Models\QuestionTopic;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The database backs the question rules on its own
 * (2026_09_30_000100_create_question_bank_tables): known types, points from
 * 0.01 to 100, choice positions 1–6 and unique per question, at most one
 * correct choice, topics of the question's own subject, and unique topic
 * names per subject. Rows are written directly, bypassing the application.
 */
class QuestionDatabaseConstraintsTest extends TestCase
{
    use BuildsQuestionBankFixtures;

    private QuestionTopic $topicOfSubject1;

    private QuestionTopic $topicOfSubject2;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildQuestionBankFixtures();

        $this->topicOfSubject1 = $this->topic($this->subject1, 'Topic 1');
        $this->topicOfSubject2 = $this->topic($this->subject2, 'Topic 1');
    }

    public function test_database_accepts_valid_question_and_choice_rows(): void
    {
        $question = $this->insertQuestion(['question_topic_id' => $this->topicOfSubject1->id]);
        $this->insertChoice($question, ['position' => 1, 'is_correct' => true]);
        $this->insertChoice($question, ['position' => 6]);
        $this->insertQuestion(['type' => 'true_false', 'points' => '0.01']);
        $this->insertQuestion(['type' => 'essay', 'points' => '100']);

        $this->assertSame(3, DB::table('questions')->count());
        $this->assertSame(2, DB::table('question_choices')->count());
        $this->assertSame($question, (int) DB::table('question_choices')->where('is_correct', true)->value('correct_marker'));
        $this->assertNull(DB::table('question_choices')->where('is_correct', false)->value('correct_marker'));
    }

    public function test_database_rejects_unknown_question_types(): void
    {
        $this->assertRejected(fn () => $this->insertQuestion(['type' => 'matching']), 'An unknown type must be rejected.');
        $this->assertRejected(fn () => $this->insertQuestion(['type' => '']), 'An empty type must be rejected.');

        $question = $this->insertQuestion();
        $this->assertRejected(fn () => $this->updateQuestion($question, ['type' => 'short_answer']), 'Changing to an unknown type must be rejected.');
    }

    public function test_database_rejects_points_outside_0_01_to_100(): void
    {
        foreach (['0', '-1', '100.01', '999.99'] as $points) {
            $this->assertRejected(fn () => $this->insertQuestion(['points' => $points]), "points = {$points} must be rejected.");
        }

        $question = $this->insertQuestion();
        $this->assertRejected(fn () => $this->updateQuestion($question, ['points' => '0']), 'Updating points to 0 must be rejected.');
        $this->assertSame('1.00', DB::table('questions')->where('id', $question)->value('points'));
    }

    public function test_database_rejects_choice_positions_outside_1_to_6(): void
    {
        $question = $this->insertQuestion();

        foreach ([0, 7, 255] as $position) {
            $this->assertRejected(fn () => $this->insertChoice($question, ['position' => $position]), "position {$position} must be rejected.");
        }

        $choice = $this->insertChoice($question, ['position' => 1]);
        $this->assertRejected(fn () => DB::table('question_choices')->where('id', $choice)->update(['position' => 7]), 'Moving a choice to position 7 must be rejected.');
    }

    public function test_database_keeps_choice_positions_unique_per_question(): void
    {
        $question = $this->insertQuestion();
        $other = $this->insertQuestion();
        $this->insertChoice($question, ['position' => 1]);

        $this->assertRejected(fn () => $this->insertChoice($question, ['position' => 1, 'text' => 'Another']), 'A second choice at position 1 must be rejected.');

        $this->insertChoice($other, ['position' => 1]);
        $this->assertSame(2, DB::table('question_choices')->where('position', 1)->count());
    }

    public function test_database_allows_at_most_one_correct_choice_per_question(): void
    {
        $question = $this->insertQuestion();
        $other = $this->insertQuestion();
        $first = $this->insertChoice($question, ['position' => 1, 'is_correct' => true]);
        $second = $this->insertChoice($question, ['position' => 2]);

        $this->assertRejected(fn () => $this->insertChoice($question, ['position' => 3, 'is_correct' => true]), 'A second correct choice must be rejected.');
        $this->assertRejected(
            fn () => DB::table('question_choices')->where('id', $second)->update(['is_correct' => true]),
            'Marking a second choice correct must be rejected.',
        );

        // Moving the correct answer works when the old one is unmarked first.
        DB::table('question_choices')->where('id', $first)->update(['is_correct' => false]);
        DB::table('question_choices')->where('id', $second)->update(['is_correct' => true]);
        $this->assertSame($second, (int) DB::table('question_choices')->where('question_id', $question)->where('is_correct', true)->value('id'));

        // Each question has its own correct choice.
        $this->insertChoice($other, ['position' => 1, 'is_correct' => true]);
        $this->assertSame(2, DB::table('question_choices')->where('is_correct', true)->count());
    }

    public function test_database_requires_the_topic_to_belong_to_the_question_subject(): void
    {
        $this->assertRejected(
            fn () => $this->insertQuestion(['question_topic_id' => $this->topicOfSubject2->id]),
            'A topic of Subject 2 must not be usable for a question of Subject 1.',
        );
        $this->assertRejected(fn () => $this->insertQuestion(['question_topic_id' => 999999]), 'An unknown topic must be rejected.');

        $question = $this->insertQuestion(['question_topic_id' => $this->topicOfSubject1->id]);
        $this->assertRejected(
            fn () => $this->updateQuestion($question, ['question_topic_id' => $this->topicOfSubject2->id]),
            'Changing to a topic of another subject must be rejected.',
        );
        $this->assertRejected(
            fn () => $this->updateQuestion($question, ['subject_id' => $this->subject2->id]),
            'Moving a question to another subject while keeping its topic must be rejected.',
        );

        // Without a topic the question may be in any subject.
        $this->updateQuestion($question, ['question_topic_id' => null]);
        $this->insertQuestion(['subject_id' => $this->subject3->id, 'question_topic_id' => null]);
        $this->assertSame(1, DB::table('questions')->whereNull('question_topic_id')->where('subject_id', $this->subject1->id)->count());
    }

    public function test_topic_names_are_unique_per_subject_ignoring_case(): void
    {
        $this->assertRejected(fn () => $this->insertTopic($this->subject1->id, 'topic 1'), 'A topic name differing only in case must be rejected.');
        $this->assertRejected(fn () => $this->insertTopic($this->subject1->id, 'Topic 1'), 'A duplicate topic name must be rejected.');

        $this->insertTopic($this->subject3->id, 'Topic 1');
        $this->assertSame(3, DB::table('question_topics')->where('name', 'Topic 1')->count());
    }

    public function test_database_protects_the_subject_topic_and_author_of_a_question(): void
    {
        $question = $this->insertQuestion(['question_topic_id' => $this->topicOfSubject1->id]);

        $this->assertRejected(fn () => DB::table('subjects')->where('id', $this->subject1->id)->delete(), 'A subject with questions must not be deletable.');
        $this->assertRejected(fn () => DB::table('question_topics')->where('id', $this->topicOfSubject1->id)->delete(), 'A topic with questions must not be deletable.');
        $this->assertRejected(fn () => DB::table('users')->where('id', $this->alpha->id)->delete(), 'The author of a question must not be deletable.');
        $this->assertRejected(fn () => $this->updateQuestion($question, ['created_by' => 999999]), 'The author must be an existing user.');
        $this->assertRejected(fn () => $this->updateQuestion($question, ['updated_by' => 999999]), 'The last editor must be an existing user.');
        $this->assertRejected(fn () => $this->insertChoice(999999, ['position' => 1]), 'A choice must belong to an existing question.');

        $this->assertSame(1, DB::table('questions')->where('id', $question)->count());
    }

    public function test_choices_are_removed_together_with_their_question(): void
    {
        // The application never deletes questions; the foreign key still keeps choices consistent.
        $question = $this->insertQuestion();
        $this->insertChoice($question, ['position' => 1, 'is_correct' => true]);
        $this->insertChoice($question, ['position' => 2]);

        DB::table('questions')->where('id', $question)->delete();

        $this->assertSame(0, DB::table('question_choices')->where('question_id', $question)->count());
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function insertQuestion(array $overrides = []): int
    {
        return DB::table('questions')->insertGetId([
            'subject_id' => $this->subject1->id,
            'question_topic_id' => null,
            'type' => 'multiple_choice',
            'prompt' => 'Raw question?',
            'points' => '1.00',
            'explanation' => null,
            'is_active' => true,
            'locked_at' => null,
            'created_by' => $this->alpha->id,
            'updated_by' => null,
            'created_at' => now(),
            'updated_at' => now(),
            ...$overrides,
        ]);
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function updateQuestion(int $questionId, array $values): void
    {
        DB::table('questions')->where('id', $questionId)->update($values);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function insertChoice(int $questionId, array $overrides = []): int
    {
        return DB::table('question_choices')->insertGetId([
            'question_id' => $questionId,
            'position' => 1,
            'text' => 'Raw choice',
            'is_correct' => false,
            'created_at' => now(),
            'updated_at' => now(),
            ...$overrides,
        ]);
    }

    private function insertTopic(int $subjectId, string $name): void
    {
        DB::table('question_topics')->insert(['subject_id' => $subjectId, 'name' => $name, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function assertRejected(callable $write, string $message): void
    {
        try {
            $write();
        } catch (QueryException) {
            $this->addToAssertionCount(1);

            return;
        }

        $this->fail($message);
    }
}
