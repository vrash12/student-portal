<?php

namespace Database\Seeders;

use App\Models\Question;
use App\Models\Subject;
use App\Models\User;
use App\Services\QuestionBank\QuestionBankService;
use App\Services\QuestionBank\QuestionContent;
use App\Services\QuestionBank\QuestionData;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Synthetic question bank for local development and demonstrations: a few
 * generic questions of every type in two topics ("Topic 1", "Topic 2") for
 * each subject the demo instructors teach, including one inactive question
 * per subject. Placeholder text only; nothing resembles real examination
 * content.
 *
 * Questions are created through QuestionBankService as a demo instructor who
 * teaches the subject, so they have a real author and audit entries.
 * Idempotent: a question whose prompt already exists in the subject is
 * skipped. Never runs in production.
 *
 * Requires DemoAccountsSeeder and DemoAcademicSeeder to have run first.
 */
class DemoQuestionBankSeeder extends Seeder
{
    /**
     * Demo instructor accounts (DemoAccountsSeeder), in order of preference
     * as the author of a subject's questions.
     */
    private const DEMO_INSTRUCTORS = ['instructor.alpha', 'instructor.bravo'];

    public function __construct(private readonly QuestionBankService $questions) {}

    public function run(): void
    {
        if (app()->isProduction()) {
            throw new RuntimeException('Demo question bank data must not be seeded in production.');
        }

        $authors = $this->authorsBySubject();

        $subjects = Subject::query()->whereKey(array_keys($authors))->orderBy('code')->get();

        foreach ($subjects as $subject) {
            // One transaction per subject, so a failure never leaves a
            // half-seeded subject behind.
            DB::transaction(fn () => $this->seedSubject($subject, $authors[$subject->id]));
        }
    }

    /**
     * The first demo instructor who teaches each subject.
     *
     * @return array<int, User>
     */
    private function authorsBySubject(): array
    {
        $instructors = User::query()
            ->whereIn('username', self::DEMO_INSTRUCTORS)
            ->get()
            ->sortBy(fn (User $user): int|false => array_search($user->username, self::DEMO_INSTRUCTORS, true));

        $authors = [];
        foreach ($instructors as $instructor) {
            foreach ($instructor->taughtSubjectIds() as $subjectId) {
                $authors[$subjectId] ??= $instructor;
            }
        }

        return $authors;
    }

    private function seedSubject(Subject $subject, User $author): void
    {
        foreach ($this->definitions($subject) as $definition) {
            $content = $definition['content'];

            $exists = Question::query()->forSubject($subject->id)->where('prompt', $content->prompt)->exists();
            if ($exists) {
                continue;
            }

            $question = $this->questions->create(
                $subject,
                new QuestionData($definition['topic'], $definition['points'], $definition['explanation'], $content),
                $author,
            );

            if (! $definition['active']) {
                $this->questions->deactivate($question, $author);
            }
        }
    }

    /**
     * @return list<array{topic: string, points: string, explanation: string|null, content: QuestionContent, active: bool}>
     */
    private function definitions(Subject $subject): array
    {
        $name = $subject->name;

        return [
            [
                'topic' => 'Topic 1',
                'points' => '1',
                'explanation' => 'Sample explanation: Option B is the correct answer of this demo question.',
                'content' => QuestionContent::multipleChoice("Sample question 1 for {$name}: which option is correct?", $this->options(4, correct: 2)),
                'active' => true,
            ],
            [
                'topic' => 'Topic 1',
                'points' => '1',
                'explanation' => null,
                'content' => QuestionContent::trueFalse("Sample statement 1 for {$name}: this statement is true.", true),
                'active' => true,
            ],
            [
                'topic' => 'Topic 2',
                'points' => '2',
                'explanation' => null,
                'content' => QuestionContent::multipleChoice("Sample question 2 for {$name}: which option is correct?", $this->options(3, correct: 3)),
                'active' => true,
            ],
            [
                'topic' => 'Topic 2',
                'points' => '1',
                'explanation' => null,
                'content' => QuestionContent::trueFalse("Sample statement 2 for {$name}: this statement is false.", false),
                'active' => true,
            ],
            [
                'topic' => 'Topic 2',
                'points' => '10',
                'explanation' => 'Sample grading guidance: award full points for a clear answer written in complete sentences.',
                'content' => QuestionContent::essay("Sample essay prompt for {$name}: explain the main idea of the topic in your own words."),
                'active' => true,
            ],
            [
                'topic' => 'Topic 1',
                'points' => '1',
                'explanation' => null,
                'content' => QuestionContent::multipleChoice("Sample question 3 for {$name} (no longer used): which option is correct?", $this->options(4, correct: 1)),
                'active' => false,
            ],
        ];
    }

    /**
     * "Option A", "Option B", ...; $correct is 1-based.
     *
     * @return list<array{text: string, is_correct: bool}>
     */
    private function options(int $count, int $correct): array
    {
        return array_map(fn (int $position): array => [
            'text' => 'Option '.chr(ord('A') + $position - 1),
            'is_correct' => $position === $correct,
        ], range(1, $count));
    }
}
