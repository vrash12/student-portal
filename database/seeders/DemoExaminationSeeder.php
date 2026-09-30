<?php

namespace Database\Seeders;

use App\Models\ClassSubject;
use App\Models\Examination;
use App\Models\User;
use App\Services\Examinations\ExaminationService;
use App\Services\QuestionBank\QuestionBankService;
use App\Services\QuestionBank\QuestionContent;
use App\Services\QuestionBank\QuestionData;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * A published demonstration quiz candidates can take right away: Sample
 * Batch A, Subject 1, taught by Instructor Alpha. Open from now for 7 days,
 * 20 minutes, up to 3 attempts, results released. Generic placeholder content
 * only. Created through the question bank and examination services, so the
 * normal rules and audit entries apply. Idempotent (skips when the quiz
 * exists). Never runs in production.
 *
 * php artisan db:seed --class=DemoExaminationSeeder
 */
class DemoExaminationSeeder extends Seeder
{
    public const TITLE = 'Demo Quiz — Subject 1';

    public function run(QuestionBankService $questions, ExaminationService $examinations): void
    {
        if (app()->isProduction()) {
            throw new RuntimeException('Demo examination data must not be seeded in production.');
        }

        $instructor = User::query()->where('username', 'instructor.alpha')->first();
        $offering = ClassSubject::query()
            ->whereHas('classBatch', fn ($query) => $query->where('name', 'Sample Batch A'))
            ->whereHas('subject', fn ($query) => $query->where('code', 'SUBJ-1'))
            ->first();

        if ($instructor === null || $offering === null) {
            $this->command?->warn('Demo quiz skipped: run DemoAccountsSeeder and DemoAcademicSeeder first.');

            return;
        }

        if (Examination::query()->where('class_subject_id', $offering->id)->where('title', self::TITLE)->exists()) {
            $this->command?->info('Demo quiz already exists.');

            return;
        }

        $subject = $offering->subject;
        $items = [
            QuestionContent::multipleChoice('Demo question 1: Which option is marked correct in this sample question?', [
                ['text' => 'Option A', 'is_correct' => false],
                ['text' => 'Option B (correct)', 'is_correct' => true],
                ['text' => 'Option C', 'is_correct' => false],
                ['text' => 'Option D', 'is_correct' => false],
            ]),
            QuestionContent::multipleChoice('Demo question 2: Choose the third option.', [
                ['text' => 'First option', 'is_correct' => false],
                ['text' => 'Second option', 'is_correct' => false],
                ['text' => 'Third option', 'is_correct' => true],
            ]),
            QuestionContent::trueFalse('Demo question 3: This statement is true.', true),
            QuestionContent::trueFalse('Demo question 4: This statement is false.', false),
            QuestionContent::essay('Demo question 5: In two or three sentences, describe what you would check before submitting an examination.'),
        ];

        $selected = [];
        foreach ($items as $content) {
            $question = $questions->create($subject, new QuestionData('Demo Topic', '2', null, $content), $instructor);
            $selected[] = ['question_id' => $question->id, 'points' => '2'];
        }

        $exam = $examinations->create($instructor, [
            'class_subject_id' => $offering->id,
            'kind' => 'quiz',
            'title' => self::TITLE,
            'description' => 'A short demonstration quiz. Answer every question, then submit. The multiple-choice and true/false questions are scored automatically; the essay is graded by your instructor.',
            'duration_minutes' => 20,
            'attempt_limit' => 3,
            'passing_score' => '60',
            'opens_at' => now()->subMinute(),
            'closes_at' => now()->addDays(7),
            'access_code' => null,
            'release_results' => true,
            'randomize_questions' => false,
            'randomize_choices' => false,
            'one_question_at_a_time' => true,
            'allow_back_navigation' => true,
            'auto_submit' => true,
        ]);
        $examinations->syncQuestions($instructor, $exam, $selected);
        $examinations->publish($instructor, $exam);

        $this->command?->info('Published "'.self::TITLE.'" for Sample Batch A, open for 7 days.');
    }
}
