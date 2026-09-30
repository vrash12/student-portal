<?php

namespace Tests\Feature\QuestionBank;

use App\Enums\AuditAction;
use App\Enums\QuestionType;
use App\Models\AuditLog;
use App\Models\Question;
use App\Models\QuestionChoice;
use App\Models\QuestionTopic;
use App\Models\Subject;
use Database\Seeders\DemoAcademicSeeder;
use Database\Seeders\DemoAccountsSeeder;
use Database\Seeders\DemoQuestionBankSeeder;
use RuntimeException;
use Tests\TestCase;

/**
 * The demo question bank: generic placeholder content for every subject the
 * demo instructors teach, written through the service by an instructor who
 * teaches the subject, created once however often the seeder runs.
 */
class DemoQuestionBankSeederTest extends TestCase
{
    private const QUESTIONS_PER_SUBJECT = 6;

    public function test_demo_questions_are_generic_created_by_a_teaching_instructor_and_seeded_once(): void
    {
        $this->seed([DemoAccountsSeeder::class, DemoAcademicSeeder::class, DemoQuestionBankSeeder::class]);

        $counts = fn (): array => [
            Question::query()->count(),
            QuestionTopic::query()->count(),
            QuestionChoice::query()->count(),
            AuditLog::query()->where('action', 'like', 'question.%')->count(),
        ];
        $before = $counts();

        $this->seed(DemoQuestionBankSeeder::class);

        $this->assertSame($before, $counts());

        // Subjects 1–4 are all taught by the demo instructors.
        $subjects = Subject::query()->orderBy('code')->get();
        $this->assertSame(['SUBJ-1', 'SUBJ-2', 'SUBJ-3', 'SUBJ-4'], $subjects->pluck('code')->all());
        $this->assertSame(4 * self::QUESTIONS_PER_SUBJECT, $before[0]);
        $this->assertSame(4 * 2, $before[1]);

        foreach ($subjects as $subject) {
            $questions = Question::query()->with(['topic', 'choices', 'creator'])->forSubject($subject->id)->get();

            $this->assertCount(self::QUESTIONS_PER_SUBJECT, $questions);
            $this->assertEqualsCanonicalizing(['Topic 1', 'Topic 2'], $subject->questionTopics()->pluck('name')->all());
            $this->assertSame(1, $questions->where('is_active', false)->count(), "{$subject->code} has one inactive question.");
            $this->assertEqualsCanonicalizing(
                [QuestionType::MultipleChoice, QuestionType::TrueFalse, QuestionType::Essay],
                $questions->pluck('type')->unique()->values()->all(),
            );

            foreach ($questions as $question) {
                $this->assertStringStartsWith('Sample ', $question->prompt);
                $this->assertStringContainsString($subject->name, $question->prompt);
                $this->assertTrue($question->creator->teachesSubject($subject->id), 'The author teaches the subject.');
                $this->assertContains($question->creator->username, ['instructor.alpha', 'instructor.bravo']);
                $this->assertNull($question->locked_at);

                foreach ($question->choices as $choice) {
                    $this->assertMatchesRegularExpression('/^(Option [A-F]|True|False)$/', $choice->text);
                }
                if ($question->type->isObjective()) {
                    $this->assertSame(1, $question->choices->where('is_correct', true)->count());
                }
            }
        }

        // Written through the service: every question has a creation entry by its author.
        $created = AuditLog::query()->where('action', AuditAction::QuestionCreated->value)->get();
        $this->assertCount($before[0], $created);
        foreach ($created as $entry) {
            $this->assertSame(Question::query()->findOrFail($entry->auditable_id)->created_by, $entry->actor_id);
        }
        $this->assertSame(4, AuditLog::query()->where('action', AuditAction::QuestionDeactivated->value)->count());
    }

    public function test_the_seeder_does_nothing_without_the_demo_instructors(): void
    {
        $this->seed(DemoQuestionBankSeeder::class);

        $this->assertSame(0, Question::query()->count());
    }

    public function test_demo_questions_are_never_seeded_in_production(): void
    {
        $this->app->detectEnvironment(fn (): string => 'production');

        $this->expectException(RuntimeException::class);

        $this->app->make(DemoQuestionBankSeeder::class)->run();
    }
}
