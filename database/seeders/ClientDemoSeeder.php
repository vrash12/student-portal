<?php

namespace Database\Seeders;

use App\Enums\CandidateStatus;
use App\Enums\SystemRole;
use App\Models\AcademicPeriod;
use App\Models\Candidate;
use App\Models\ClassBatch;
use App\Models\ClassSubject;
use App\Models\InstructorAssignment;
use App\Models\Role;
use App\Models\Subject;
use App\Models\User;
use App\Services\CandidateService;
use App\Services\Examinations\ExaminationService;
use App\Services\QuestionBank\QuestionBankService;
use App\Services\QuestionBank\QuestionContent;
use App\Services\QuestionBank\QuestionData;
use App\Services\QuestionBank\QuestionMediaService;
use Illuminate\Database\Seeder;
use Illuminate\Http\UploadedFile;
use RuntimeException;

/**
 * Small, simple data set for client demonstrations (requested by the owner
 * on 2026-10-01), meant for an otherwise empty database:
 *
 *   admin                      Super Administrator
 *   instructor1, instructor2   Instructors (Subject 1 and Subject 2)
 *   student01 … student20      Candidates in Class A
 *
 * Every account uses the password "password" and is not asked to change it.
 * Adds grading weights, passing/warning grades, finalized scores (through
 * DemoGradingSeeder), five questions per subject, and one published online
 * quiz per subject open for 7 days. Synthetic content only. Never runs in
 * production.
 *
 * php artisan migrate:fresh
 * php artisan db:seed --class=AccessControlSeeder
 * php artisan db:seed --class=ClientDemoSeeder
 */
class ClientDemoSeeder extends Seeder
{
    public const PASSWORD = 'password';

    private const STUDENTS = 20;

    public function __construct(
        private readonly QuestionBankService $questions,
        private readonly QuestionMediaService $media,
        private readonly ExaminationService $examinations,
    ) {}

    public function run(): void
    {
        if (app()->isProduction()) {
            throw new RuntimeException('Client demo data must not be seeded in production.');
        }

        $this->staff('admin', 'Administrator', SystemRole::SuperAdministrator);
        $instructors = [
            1 => $this->staff('instructor1', 'Instructor One', SystemRole::Instructor),
            2 => $this->staff('instructor2', 'Instructor Two', SystemRole::Instructor),
        ];

        $period = AcademicPeriod::query()->firstOrCreate(
            ['name' => 'First Semester 2026-2027'],
            ['starts_on' => '2026-08-03', 'ends_on' => '2026-12-18'],
        );
        if (! AcademicPeriod::query()->active()->exists()) {
            $period->forceFill(['is_active' => true])->save();
        }

        $class = ClassBatch::query()->where('academic_period_id', $period->id)->where('name', 'Class A')->first();
        if ($class === null) {
            $class = new ClassBatch(['name' => 'Class A']);
            $class->academicPeriod()->associate($period);
            $class->save();
        }

        $offerings = [];
        foreach ([1, 2] as $number) {
            $subject = Subject::query()->firstOrCreate(['code' => "SUBJ-{$number}"], ['name' => "Subject {$number}", 'description' => null]);
            $offerings[$number] = $this->offering($class, $subject, $instructors[$number]);
        }

        $this->students($class);

        // Grading weights, 75/80 thresholds and finalized scores for every taught subject.
        $this->call(DemoGradingSeeder::class);

        foreach ($offerings as $number => $offering) {
            $this->onlineQuiz($offering, $instructors[$number], $number === 1);
        }

        // Sample fitness events and a diagnostic test with synthetic results.
        $this->call(DemoFitnessSeeder::class);

        // The Finance Officer finance1 and synthetic charges and credits on the candidates' statements of account.
        $this->call(DemoAccountStatementsSeeder::class);
    }

    private function staff(string $username, string $name, SystemRole $role): User
    {
        $user = User::query()->where('username', $username)->first() ?? new User(['username' => $username, 'email' => null]);
        $user->fill(['name' => $name, 'password' => self::PASSWORD]);
        $user->role()->associate(Role::query()->where('code', $role->value)->firstOrFail());
        $user->is_active = true;
        $user->password_change_required = false;
        $user->save();

        return $user;
    }

    private function offering(ClassBatch $class, Subject $subject, User $instructor): ClassSubject
    {
        $offering = ClassSubject::query()->where('class_batch_id', $class->id)->where('subject_id', $subject->id)->first();
        if ($offering === null) {
            $offering = new ClassSubject;
            $offering->classBatch()->associate($class);
            $offering->subject()->associate($subject);
            $offering->save();
        }

        if (! InstructorAssignment::query()->where('class_subject_id', $offering->id)->where('instructor_id', $instructor->id)->exists()) {
            $assignment = new InstructorAssignment;
            $assignment->classSubject()->associate($offering);
            $assignment->instructor()->associate($instructor);
            $assignment->save();
        }

        return $offering;
    }

    private function students(ClassBatch $class): void
    {
        $role = Role::query()->where('code', SystemRole::Candidate->value)->firstOrFail();

        for ($seat = 1; $seat <= self::STUDENTS; $seat++) {
            $padded = str_pad((string) $seat, 2, '0', STR_PAD_LEFT);
            $number = "student{$padded}";
            if (Candidate::query()->where('candidate_number', $number)->exists()) {
                continue;
            }

            $account = new User([
                'name' => "Student {$padded}",
                'username' => CandidateService::usernameFor($number),
                'email' => null,
                'password' => self::PASSWORD,
            ]);
            $account->role()->associate($role);
            $account->is_active = true;
            $account->password_change_required = false;
            $account->save();

            $candidate = new Candidate(['candidate_number' => $number, 'first_name' => 'Student', 'last_name' => $padded]);
            $candidate->user()->associate($account);
            $candidate->classBatch()->associate($class);
            $candidate->status = CandidateStatus::Enrolled;
            $candidate->save();
        }
    }

    /**
     * Five questions (two multiple choice, two true/false, one essay) and a
     * published quiz open for 7 days. With $images, every question shows a
     * generated figure and choice B of question 2 shows one too.
     */
    private function onlineQuiz(ClassSubject $offering, User $instructor, bool $images): void
    {
        $subject = $offering->subject;
        $title = "Online Quiz 1 — {$subject->name}";
        if ($offering->examinations()->where('title', $title)->exists()) {
            return;
        }

        $contents = [
            QuestionContent::multipleChoice("{$subject->name}, question 1: Which option is marked correct in this sample question?", [
                ['text' => 'Option A', 'is_correct' => false],
                ['text' => 'Option B (correct)', 'is_correct' => true],
                ['text' => 'Option C', 'is_correct' => false],
                ['text' => 'Option D', 'is_correct' => false],
            ]),
            QuestionContent::multipleChoice($images
                ? "{$subject->name}, question 2: Look at the figure. Which labelled shape is the circle?"
                : "{$subject->name}, question 2: Choose the third option.", $images
                ? [['text' => 'Shape A', 'is_correct' => true], ['text' => 'Shape B', 'is_correct' => false], ['text' => 'Shape C', 'is_correct' => false]]
                : [['text' => 'First option', 'is_correct' => false], ['text' => 'Second option', 'is_correct' => false], ['text' => 'Third option', 'is_correct' => true]]),
            QuestionContent::trueFalse("{$subject->name}, question 3: This statement is true.", true),
            QuestionContent::trueFalse("{$subject->name}, question 4: This statement is false.", false),
            QuestionContent::essay("{$subject->name}, question 5: In two or three sentences, describe what you would check before submitting an examination."),
        ];

        $selected = [];
        foreach ($contents as $index => $content) {
            $question = $this->questions->create($subject, new QuestionData('Demo Topic', '2', null, $content), $instructor);
            if ($images && $index < 4) {
                $this->media->add($question, $this->shapesImage(), 'Figure: three shapes side by side, labelled A (a circle), B (a square), and C (a triangle).', $instructor);
            }
            if ($images && $index === 1) {
                $this->media->add($question, $this->shapesImage(), 'Picture of the three labelled shapes.', $instructor, choicePosition: 2);
            }
            $selected[] = ['question_id' => $question->id, 'points' => '2'];
        }

        $exam = $this->examinations->create($instructor, [
            'class_subject_id' => $offering->id,
            'kind' => 'quiz',
            'title' => $title,
            'description' => 'A short online quiz. Answer every question, then submit. Multiple-choice and true/false questions are scored automatically; the essay is graded by your instructor.',
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
        $this->examinations->syncQuestions($instructor, $exam, $selected);
        $this->examinations->publish($instructor, $exam);
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
