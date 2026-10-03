<?php

namespace Tests\Feature\Examinations\Builder;

use App\Enums\Permission as PermissionCode;
use App\Models\AuditLog;
use App\Models\ClassSubject;
use App\Models\Examination;
use App\Models\ExaminationAttempt;
use App\Models\Question;
use App\Models\Subject;
use App\Services\Examinations\ExaminationService;
use Tests\Feature\QuestionBank\BuildsQuestionBankFixtures;

/**
 * Examination builder setup on top of the question bank fixtures
 * (see BuildsQuestionBankFixtures and BuildsTeachingFixtures):
 *
 *   alphaOffering    Batch A / Subject 1, taught by Alpha
 *   bravoOffering    Batch A / Subject 2, taught by Bravo
 *   bravoS1Offering  Batch B / Subject 1, taught by Bravo
 */
trait BuildsExaminationBuilderFixtures
{
    use BuildsQuestionBankFixtures;

    protected ClassSubject $alphaOffering;

    protected ClassSubject $bravoOffering;

    protected ClassSubject $bravoS1Offering;

    protected function buildExaminationBuilderFixtures(): void
    {
        $this->buildQuestionBankFixtures();

        $this->alphaOffering = $this->offeringFor($this->batchA->id, $this->subject1);
        $this->bravoOffering = $this->offeringFor($this->batchA->id, $this->subject2);
        $this->bravoS1Offering = $this->offeringFor($this->batchB->id, $this->subject1);
    }

    protected function offeringFor(int $classBatchId, Subject $subject): ClassSubject
    {
        return ClassSubject::query()->where('class_batch_id', $classBatchId)->where('subject_id', $subject->id)->sole();
    }

    /**
     * A valid create/update form payload.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function examPayload(array $overrides = []): array
    {
        return [
            'class_subject_id' => $this->alphaOffering->id,
            'kind' => 'quiz',
            'title' => 'Synthetic Quiz 01',
            'description' => 'Synthetic instructions for the quiz.',
            'duration_minutes' => 30,
            'passing_score' => '75',
            'opens_at' => null,
            'closes_at' => null,
            'access_code' => null,
            'release_results' => false,
            'randomize_questions' => false,
            'randomize_choices' => false,
            'one_question_at_a_time' => false,
            'allow_back_navigation' => true,
            'auto_submit' => true,
            ...$overrides,
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function updatePayload(array $overrides = []): array
    {
        $payload = $this->examPayload($overrides);
        unset($payload['class_subject_id']);

        return $payload;
    }

    /**
     * Creates a draft through the service, as Alpha by default.
     *
     * @param  array<string, mixed>  $overrides
     */
    protected function draft(array $overrides = [], ?ClassSubject $offering = null): Examination
    {
        $offering ??= $this->alphaOffering;
        $owner = $offering->is($this->alphaOffering) ? $this->alpha : $this->bravo;
        $data = $this->examPayload(['class_subject_id' => $offering->id, ...$overrides]);

        return $this->app->make(ExaminationService::class)->create($owner, $data);
    }

    protected function mcq(?Subject $subject = null, string $prompt = 'Synthetic MCQ prompt'): Question
    {
        return Question::factory()->multipleChoice(4, 2)->create([
            'subject_id' => ($subject ?? $this->subject1)->id,
            'prompt' => $prompt.' '.fake()->unique()->numberBetween(1, 999999),
            'points' => '2.00',
        ]);
    }

    /**
     * Adds the questions to the draft through the service (1 point each).
     *
     * @param  list<Question>  $questions
     */
    protected function addQuestions(Examination $exam, array $questions): void
    {
        $this->app->make(ExaminationService::class)->syncQuestions(
            $this->alpha,
            $exam,
            array_map(fn (Question $question): array => ['question_id' => $question->id, 'points' => '1'], $questions),
        );
    }

    /**
     * A draft of Alpha's offering with questions, ready to publish.
     *
     * @param  array<string, mixed>  $overrides
     */
    protected function readyDraft(array $overrides = [], int $questionCount = 2): Examination
    {
        $exam = $this->draft($overrides);
        $this->addQuestions($exam, array_map(fn (): Question => $this->mcq(), range(1, $questionCount)));

        return $exam->fresh();
    }

    protected function publishedExam(array $overrides = []): Examination
    {
        $exam = $this->readyDraft($overrides);
        $this->app->make(ExaminationService::class)->publish($this->alpha, $exam);

        return $exam->fresh();
    }

    protected function inProgressAttempt(Examination $exam): ExaminationAttempt
    {
        $attempt = new ExaminationAttempt;
        $attempt->candidate_id = $this->candidateInA->id;
        $attempt->examination_id = $exam->id;
        $attempt->attempt_number = 1;
        $attempt->status = 'in_progress';
        $attempt->started_at = now();
        $attempt->expires_at = now()->addMinutes(30);
        $attempt->delivery = [];
        $attempt->answers = [];
        $attempt->scoring_key = [];
        $attempt->save();

        return $attempt;
    }

    /**
     * Alpha keeps examinations.manage and his assignments, but loses classes.teach.
     */
    protected function alphaWithoutTeaching(): void
    {
        $this->alpha = $this->withCustomRole($this->alpha, 'exam_only', [
            PermissionCode::AccessStaffArea,
            PermissionCode::ManageExaminations,
            PermissionCode::ManageQuestionBank,
        ]);
    }

    /**
     * Every audit row for the examination, serialized.
     */
    protected function auditTextFor(Examination $exam): string
    {
        return (string) json_encode(
            AuditLog::query()->where('auditable_id', $exam->id)->where('action', 'like', 'examination.%')->get()->toArray(),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        );
    }
}
