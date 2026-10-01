<?php

namespace Database\Seeders;

use App\Models\Candidate;
use App\Models\ClassSubject;
use App\Models\Examination;
use App\Models\ExaminationAttempt;
use App\Models\User;
use App\Services\Examinations\CandidateAttemptService;
use App\Services\Examinations\ExaminationService;
use App\Services\Examinations\ManualEssayGradingService;
use App\Services\QuestionBank\QuestionBankService;
use App\Services\QuestionBank\QuestionContent;
use App\Services\QuestionBank\QuestionData;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * More examination activity for the client demo set (owner request,
 * 2026-10-02, "populate with data"): for each taught subject of Class A,
 * ten more questions and a completed "Diagnostic Quiz" held on
 * 14 September 2026 that most candidates took, with varied scores, graded
 * essays and released results. Everything goes through the application
 * services (attempts, scoring, essay grading), with the clock set to the
 * quiz day and restored afterwards. Synthetic content only.
 *
 * Safe to run again: subjects that already have the quiz are skipped.
 * Never runs in production.
 *
 * php artisan db:seed --class=DemoActivitySeeder
 */
class DemoActivitySeeder extends Seeder
{
    private const TOPIC = 'Review Topic';

    /** Candidates who did not take the quiz (the list shows them as not submitted). */
    private const ABSENT = ['student09', 'student17'];

    public function __construct(
        private readonly QuestionBankService $questions,
        private readonly ExaminationService $examinations,
        private readonly CandidateAttemptService $attempts,
        private readonly ManualEssayGradingService $grading,
    ) {}

    public function run(): void
    {
        if (app()->isProduction()) {
            throw new RuntimeException('Demo activity must not be seeded in production.');
        }

        $offerings = ClassSubject::query()
            ->with(['subject', 'classBatch', 'instructorAssignments.instructor'])
            ->whereHas('classBatch', fn ($query) => $query->where('name', 'Class A'))
            ->get();

        foreach ($offerings as $offering) {
            $instructor = $offering->instructorAssignments->first()?->instructor;
            if ($instructor === null) {
                continue;
            }
            $title = "Diagnostic Quiz — {$offering->subject->name}";
            if ($offering->examinations()->where('title', $title)->exists()) {
                continue;
            }

            $previous = Carbon::getTestNow();
            try {
                $this->diagnosticQuiz($offering, $instructor, $title);
            } finally {
                Carbon::setTestNow($previous);
            }
        }
    }

    private function diagnosticQuiz(ClassSubject $offering, User $instructor, string $title): void
    {
        $timezone = config('institution.timezone');
        Carbon::setTestNow(Carbon::parse('2026-09-14 07:30', $timezone));

        $selected = [];
        foreach ($this->questionSet($offering->subject->name) as $content) {
            $question = $this->questions->create($offering->subject, new QuestionData(self::TOPIC, '2', null, $content), $instructor);
            $selected[] = ['question_id' => $question->id, 'points' => '2'];
        }

        $exam = $this->examinations->create($instructor, [
            'class_subject_id' => $offering->id,
            'kind' => 'quiz',
            'title' => $title,
            'description' => 'Diagnostic quiz at the start of the semester. Answer every question; the essay is graded by your instructor.',
            'duration_minutes' => 30,
            'attempt_limit' => 1,
            'passing_score' => '60',
            'opens_at' => Carbon::parse('2026-09-14 08:00', $timezone)->utc()->format('Y-m-d H:i:s'),
            'closes_at' => Carbon::parse('2026-09-14 17:00', $timezone)->utc()->format('Y-m-d H:i:s'),
            'access_code' => null,
            'release_results' => true,
            'randomize_questions' => false,
            'randomize_choices' => false,
            'one_question_at_a_time' => true,
            'allow_back_navigation' => true,
            'auto_submit' => true,
        ]);
        // Ten questions in the bank; the quiz uses eight of them.
        $this->examinations->syncQuestions($instructor, $exam, array_slice($selected, 0, 8));
        $this->examinations->publish($instructor, $exam);

        $candidates = Candidate::query()->with('user')->where('class_batch_id', $offering->class_batch_id)->orderBy('candidate_number')->get();
        foreach ($candidates as $seat => $candidate) {
            if ($candidate->user === null || in_array($candidate->candidate_number, self::ABSENT, true) || ! $candidate->isGradableIn((int) $offering->class_batch_id)) {
                continue;
            }
            Carbon::setTestNow(Carbon::parse('2026-09-14 09:00', $timezone)->addMinutes($seat * 3));
            $this->takeQuiz($exam->fresh(), $candidate, $seat, (int) $offering->subject_id);
        }

        // The instructor grades the essays the next morning.
        Carbon::setTestNow(Carbon::parse('2026-09-15 08:00', $timezone));
        $submitted = ExaminationAttempt::query()->with('examination')->where('examination_id', $exam->id)->where('status', 'submitted')->orderBy('id')->get();
        foreach ($submitted as $index => $attempt) {
            foreach ($attempt->scoring_key as $itemId => $key) {
                if ($key['type'] !== 'essay') {
                    continue;
                }
                $score = number_format([2.0, 1.5, 1.5, 1.0, 2.0, 0.5][$index % 6], 2, '.', '');
                $comment = ['Clear and complete.', 'Good points; add one example.', 'Answer the whole question.', 'Too short; explain your reasoning.'][$index % 4];
                $this->grading->grade($instructor, $attempt, (int) $itemId, $score, $comment, 0, null);
            }
        }
    }

    /**
     * Answers every question, getting about "ability" of the objective
     * items right (from 40 % to 100 % across the class), then submits.
     */
    private function takeQuiz(Examination $exam, Candidate $candidate, int $seat, int $variant): void
    {
        // The attempt services read $user->candidate; give it the loaded record.
        $candidate->user->setRelation('candidate', $candidate);
        $attempt = $this->attempts->start($candidate->user, $exam, null);
        // Each subject gives the class a different spread of results.
        $ability = 0.4 + (($seat * 7 + $variant * 5) % 13) / 20;
        $last = count($attempt->delivery) - 1;

        foreach ($attempt->delivery as $position => $item) {
            $key = $attempt->scoring_key[$item['id']] ?? $attempt->scoring_key[(string) $item['id']];
            if ($key['type'] === 'essay') {
                $answer = 'A leader sets the example, keeps the team informed, and checks the work before reporting that the task is done.';
            } else {
                $correct = (($position * 37 + $seat * 11 + $variant * 23) % 100) / 100 < $ability;
                $wrong = collect($item['question']['choices'])->pluck('id')->reject(fn (int $id): bool => $id === $key['correct_choice_id'])->values();
                $answer = $correct || $wrong->isEmpty() ? $key['correct_choice_id'] : $wrong[($seat + $position) % $wrong->count()];
            }

            Carbon::setTestNow(Carbon::now()->addSeconds(40));
            $data = ['revision' => $attempt->revision, 'position' => $position, 'answer' => $answer];
            if ($position < $last) {
                $data['next_position'] = $position + 1;
            }
            $attempt = $this->attempts->save($candidate->user, $attempt, $data, $position === $last);
        }
    }

    /**
     * Ten questions: four multiple choice, four true/false, two essays.
     *
     * @return list<QuestionContent>
     */
    private function questionSet(string $subject): array
    {
        $choices = fn (array $texts, int $correct): array => array_map(fn (string $text, int $index): array => ['text' => $text, 'is_correct' => $index === $correct], $texts, array_keys($texts));

        return [
            QuestionContent::multipleChoice("{$subject}: Which action best shows leadership by example?", $choices(['Giving orders from a distance', 'Doing the task to the standard you expect of others', 'Waiting for others to start first', 'Reporting problems without solutions'], 1)),
            QuestionContent::multipleChoice("{$subject}: Before reporting that a task is complete, a candidate should first:", $choices(['Check the work against the instructions', 'Ask a classmate to report it', 'Report it, then check later', 'Leave it to the next shift'], 0)),
            QuestionContent::multipleChoice("{$subject}: The best way to keep a team informed is to:", $choices(['Share updates only when asked', 'Give clear, regular updates to everyone involved', 'Tell only the team leader', 'Post updates after the task ends'], 1)),
            QuestionContent::trueFalse("{$subject}: Accountability means accepting responsibility for the results of your actions.", true),
            QuestionContent::trueFalse("{$subject}: A good team member hides mistakes to protect the team's record.", false),
            QuestionContent::multipleChoice("{$subject}: When instructions are unclear, the right step is to:", $choices(['Guess and continue', 'Stop and ask for clarification', 'Skip that part', 'Wait until the deadline passes'], 1)),
            QuestionContent::trueFalse("{$subject}: Punctuality is part of discipline.", true),
            QuestionContent::essay("{$subject}: In three to five sentences, explain how you would prepare your team for an inspection."),
            QuestionContent::trueFalse("{$subject}: Safety checks can be skipped when time is short.", false),
            QuestionContent::essay("{$subject}: Describe one lesson you learned from working in a team and how you applied it."),
        ];
    }
}
