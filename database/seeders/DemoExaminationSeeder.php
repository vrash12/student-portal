<?php

namespace Database\Seeders;

use App\Models\ClassSubject;
use App\Models\Examination;
use App\Models\Question;
use App\Models\Subject;
use App\Models\User;
use App\Services\Examinations\ExaminationService;
use App\Services\QuestionBank\QuestionBankService;
use App\Services\QuestionBank\QuestionContent;
use App\Services\QuestionBank\QuestionData;
use App\Services\QuestionBank\QuestionMediaService;
use Illuminate\Database\Seeder;
use Illuminate\Http\UploadedFile;
use RuntimeException;

/**
 * Published demonstration quizzes candidates can take right away, for Sample
 * Batch A, Subject 1, taught by Instructor Alpha: a basic quiz and one whose
 * questions include a generated image. Open from now for 7 days, 20 minutes,
 * up to 3 attempts, results released. Generic placeholder content only.
 * Created through the question bank and examination services, so the normal
 * rules and audit entries apply. Idempotent (skips a quiz that exists).
 * Never runs in production.
 *
 * php artisan db:seed --class=DemoExaminationSeeder
 */
class DemoExaminationSeeder extends Seeder
{
    public const TITLE = 'Demo Quiz — Subject 1';

    public const MEDIA_TITLE = 'Demo Image Quiz — Subject 1';

    public function __construct(
        private readonly QuestionBankService $questions,
        private readonly ExaminationService $examinations,
        private readonly QuestionMediaService $media,
    ) {}

    public function run(): void
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
            $this->command?->warn('Demo quizzes skipped: run DemoAccountsSeeder and DemoAcademicSeeder first.');

            return;
        }

        $this->basicQuiz($offering, $instructor);
        $this->imageQuiz($offering, $instructor);
    }

    private function basicQuiz(ClassSubject $offering, User $instructor): void
    {
        if ($this->exists($offering, self::TITLE)) {
            return;
        }

        $ids = $this->createQuestions($offering->subject, $instructor, [
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
        ]);

        $this->publish($offering, $instructor, self::TITLE, 'A short demonstration quiz. Answer every question, then submit. The multiple-choice and true/false questions are scored automatically; the essay is graded by your instructor.', $ids);
    }

    private function imageQuiz(ClassSubject $offering, User $instructor): void
    {
        if ($this->exists($offering, self::MEDIA_TITLE)) {
            return;
        }

        $ids = $this->createQuestions($offering->subject, $instructor, [
            QuestionContent::multipleChoice('Image question 1: Look at the figure. Which labelled shape is the circle?', [
                ['text' => 'Shape A', 'is_correct' => true],
                ['text' => 'Shape B', 'is_correct' => false],
                ['text' => 'Shape C', 'is_correct' => false],
            ]),
            QuestionContent::trueFalse('Image question 2: In the figure, shape B is a square.', true),
            QuestionContent::essay('Image question 3: Describe the three shapes in the figure and how they differ.'),
        ]);

        $figure = 'Figure: three shapes side by side, labelled A (a circle), B (a square), and C (a triangle).';
        foreach ($ids as $id) {
            $question = Question::query()->findOrFail($id);
            $this->media->add($question, $this->shapesImage(), $figure, $instructor);
        }

        $this->publish($offering, $instructor, self::MEDIA_TITLE, 'A demonstration quiz with an image in every question. Look at the figure before answering.', $ids);
    }

    private function exists(ClassSubject $offering, string $title): bool
    {
        $exists = Examination::query()->where('class_subject_id', $offering->id)->where('title', $title)->exists();
        if ($exists) {
            $this->command?->info("\"{$title}\" already exists.");
        }

        return $exists;
    }

    /**
     * @param  list<QuestionContent>  $contents
     * @return list<int>
     */
    private function createQuestions(Subject $subject, User $instructor, array $contents): array
    {
        return array_map(
            fn (QuestionContent $content): int => $this->questions->create($subject, new QuestionData('Demo Topic', '2', null, $content), $instructor)->id,
            $contents,
        );
    }

    /**
     * @param  list<int>  $questionIds
     */
    private function publish(ClassSubject $offering, User $instructor, string $title, string $description, array $questionIds): void
    {
        $exam = $this->examinations->create($instructor, [
            'class_subject_id' => $offering->id,
            'kind' => 'quiz',
            'title' => $title,
            'description' => $description,
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
        $this->examinations->syncQuestions($instructor, $exam, array_map(fn (int $id): array => ['question_id' => $id, 'points' => '2'], $questionIds));
        $this->examinations->publish($instructor, $exam);

        $this->command?->info("Published \"{$title}\" for Sample Batch A, open for 7 days.");
    }

    /**
     * A generated PNG: a circle (A), a square (B), and a triangle (C).
     */
    private function shapesImage(): UploadedFile
    {
        $image = imagecreatetruecolor(600, 260);
        $white = imagecolorallocate($image, 255, 255, 255);
        $ink = imagecolorallocate($image, 30, 60, 40);
        $fill = imagecolorallocate($image, 214, 232, 214);
        imagefill($image, 0, 0, $white);

        imagefilledellipse($image, 110, 120, 150, 150, $fill);
        imageellipse($image, 110, 120, 150, 150, $ink);
        imagefilledrectangle($image, 230, 45, 380, 195, $fill);
        imagerectangle($image, 230, 45, 380, 195, $ink);
        $triangle = [490, 45, 565, 195, 415, 195];
        imagefilledpolygon($image, $triangle, $fill);
        imagepolygon($image, $triangle, $ink);
        foreach ([[105, 'A'], [300, 'B'], [485, 'C']] as [$x, $label]) {
            imagestring($image, 5, $x, 225, $label, $ink);
        }

        $path = tempnam(sys_get_temp_dir(), 'shapes').'.png';
        imagepng($image, $path);
        imagedestroy($image);

        return new UploadedFile($path, 'shapes.png', 'image/png', null, true);
    }
}
