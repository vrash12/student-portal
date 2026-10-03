<?php

namespace App\Services\Examinations;

use App\Enums\AuditAction;
use App\Enums\ExaminationStatus;
use App\Enums\Permission;
use App\Enums\QuestionType;
use App\Models\ClassSubject;
use App\Models\Examination;
use App\Models\ExaminationQuestion;
use App\Models\Question;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\QuestionBank\QuestionBankService;
use App\Services\QuestionBank\QuestionData;
use App\Services\QuestionBank\QuestionLocking;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ExaminationService
{
    /** The most questions one examination may hold (as validated when choosing questions). */
    public const MAX_QUESTIONS = 500;

    public function __construct(
        private readonly AuditLogger $audit,
        private readonly QuestionLocking $locking,
        private readonly QuestionBankService $questions,
    ) {}

    public function create(User $user, array $data): Examination
    {
        return DB::transaction(function () use ($user, $data) {
            ClassSubject::whereKey((int) $data['class_subject_id'])->lockForUpdate()->firstOrFail();
            $this->authorize($user, (int) $data['class_subject_id']);
            $exam = new Examination;
            $exam->class_subject_id = (int) $data['class_subject_id'];
            unset($data['class_subject_id']);
            $exam->fill($data);
            $exam->created_by = $user->id;
            $exam->status = ExaminationStatus::Draft;
            $exam->save();
            $exam->refresh();
            $this->audit->record(AuditAction::ExaminationCreated, $exam, newValues: $this->snapshot($exam), actor: $user);

            return $exam;
        });
    }

    public function update(User $user, Examination $exam, array $data): void
    {
        DB::transaction(function () use ($user, $exam, $data) {
            $exam = Examination::whereKey($exam->id)->lockForUpdate()->firstOrFail();
            $this->authorize($user, $exam->class_subject_id);
            $this->draft($exam);
            $before = $this->snapshot($exam);
            $previousInstructions = $exam->description;
            $previousCode = $exam->access_code;
            // The offering is fixed after creation; questions must always match it.
            unset($data['class_subject_id']);
            $exam->fill($data)->save();
            $after = $this->snapshot($exam);
            if ($previousInstructions !== $exam->description) {
                $after['instructions_change'] = $exam->description === null ? 'removed' : 'changed';
            }
            if ($previousCode !== $exam->access_code) {
                $after['access_code_change'] = $exam->access_code === null ? 'removed' : 'changed';
            }
            $this->audit->recordChanges(AuditAction::ExaminationSettingsUpdated, $exam, $before, $after);
        });
    }

    public function releaseResults(User $user, Examination $exam, bool $release, string $reason): void
    {
        DB::transaction(function () use ($user, $exam, $release, $reason) {
            $exam = Examination::whereKey($exam->id)->lockForUpdate()->firstOrFail();
            $this->authorize($user, $exam->class_subject_id);
            $before = ['release_results' => $exam->release_results];
            $exam->release_results = $release;
            $exam->save();
            $this->audit->recordChanges(AuditAction::ExaminationSettingsUpdated, $exam, $before, ['release_results' => $release], $reason);
        });
    }

    public function syncQuestions(User $user, Examination $exam, array $items): void
    {
        DB::transaction(function () use ($user, $exam, $items) {
            $exam = Examination::whereKey($exam->id)->with('classSubject')->lockForUpdate()->firstOrFail();
            $this->authorize($user, $exam->class_subject_id);
            $this->draft($exam);
            $ids = array_map('intval', array_column($items, 'question_id'));
            $questions = Question::whereIn('id', $ids)->where('subject_id', $exam->classSubject->subject_id)->where('is_active', true)->lockForUpdate()->get()->keyBy('id');
            if (count($ids) !== $questions->count() || count($ids) !== count(array_unique($ids))) {
                throw ValidationException::withMessages(['questions' => 'Questions must be active, unique, and match the subject.']);
            }
            $before = $exam->examinationQuestions()->get(['question_id', 'position', 'points'])->toArray();
            $exam->examinationQuestions()->delete();
            foreach (array_values($items) as $index => $item) {
                $row = new ExaminationQuestion;
                $row->examination_id = $exam->id;
                $row->question_id = (int) $item['question_id'];
                $row->position = $index + 1;
                $row->points = $item['points'] ?? $questions[$item['question_id']]->points;
                $row->save();
            }
            $this->audit->record(AuditAction::ExaminationQuestionsUpdated, $exam, ['items' => $before], ['items' => $exam->examinationQuestions()->get(['question_id', 'position', 'points'])->toArray()], actor: $user);
        });
    }

    /**
     * Writes a new question while building a draft (owner request,
     * 2026-10-03): the question is saved in the question bank of the
     * examination's subject, so it can be reused later, and added as the
     * draft's last question with its points. One transaction.
     */
    public function addNewQuestion(User $user, Examination $exam, QuestionData $data): Question
    {
        return DB::transaction(function () use ($user, $exam, $data): Question {
            $exam = Examination::whereKey($exam->id)->with('classSubject.subject')->lockForUpdate()->firstOrFail();
            $this->authorize($user, $exam->class_subject_id);
            $this->draft($exam);
            $before = $exam->examinationQuestions()->get(['question_id', 'position', 'points'])->toArray();
            if (count($before) >= self::MAX_QUESTIONS) {
                throw ValidationException::withMessages(['examination' => 'An examination can hold at most '.self::MAX_QUESTIONS.' questions.']);
            }

            $question = $this->questions->create($exam->classSubject->subject, $data, $user);

            $row = new ExaminationQuestion;
            $row->examination_id = $exam->id;
            $row->question_id = $question->id;
            $row->position = (int) $exam->examinationQuestions()->max('position') + 1;
            $row->points = $question->points;
            $row->save();

            $this->audit->record(AuditAction::ExaminationQuestionsUpdated, $exam, ['items' => $before], ['items' => $exam->examinationQuestions()->get(['question_id', 'position', 'points'])->toArray()], actor: $user);

            return $question;
        });
    }

    public function publish(User $user, Examination $exam): void
    {
        DB::transaction(function () use ($user, $exam) {
            $exam = Examination::whereKey($exam->id)->with('examinationQuestions', 'classSubject')->lockForUpdate()->firstOrFail();
            $this->authorize($user, $exam->class_subject_id);
            $this->draft($exam);
            if (! $exam->duration_minutes || $exam->examinationQuestions->isEmpty()) {
                throw ValidationException::withMessages(['examination' => 'A time limit and at least one question are required.']);
            }
            if ($exam->closes_at && ($exam->closes_at->lte(now()) || ($exam->opens_at && $exam->closes_at->lte($exam->opens_at)))) {
                throw ValidationException::withMessages(['examination' => 'The closing time must be in the future and after the opening time.']);
            }
            $ids = $exam->examinationQuestions->pluck('question_id')->all();
            $questions = $this->locking->lockRowsForPublication($ids);
            foreach ($questions as $question) {
                $validChoices = $question->type === QuestionType::Essay || ($question->choices->count() >= 2 && $question->choices->count() <= 6 && $question->choices->where('is_correct', true)->count() === 1);
                if (! $question->is_active || $question->subject_id !== $exam->classSubject->subject_id || ! $validChoices) {
                    throw ValidationException::withMessages(['questions' => 'The question bank changed. Review the selected questions before publishing.']);
                }
            }
            if ($questions->count() !== count($ids)) {
                throw ValidationException::withMessages(['questions' => 'One or more selected questions are unavailable.']);
            }
            $this->checkSubset($exam);
            $this->locking->lock($ids);
            $exam->status = ExaminationStatus::Published;
            $exam->save();
            $this->audit->record(AuditAction::ExaminationPublished, $exam, ['status' => 'draft'], ['status' => 'published', 'question_count' => count($ids), 'questions_per_attempt' => $exam->questionsPerAttempt(count($ids)), 'duration_minutes' => $exam->duration_minutes], actor: $user);
        });
    }

    public function archive(User $user, Examination $exam, string $reason): void
    {
        DB::transaction(function () use ($user, $exam, $reason) {
            $exam = Examination::whereKey($exam->id)->lockForUpdate()->firstOrFail();
            $this->authorize($user, $exam->class_subject_id);
            if ($exam->attempts()->where('status', 'in_progress')->exists()) {
                throw ValidationException::withMessages(['examination' => 'Wait for all attempts to finish before archiving.']);
            }
            $before = $exam->status->value;
            if ($exam->status === ExaminationStatus::Archived) {
                return;
            }
            $exam->status = ExaminationStatus::Archived;
            $exam->save();
            $this->audit->record(AuditAction::ExaminationArchived, $exam, ['status' => $before], ['status' => 'archived'], $reason, $user);
        });
    }

    /**
     * A random subset must be smaller than the question list, and every
     * question must carry the same points so all candidates have the same
     * maximum score whichever questions they draw.
     *
     * @throws ValidationException
     */
    private function checkSubset(Examination $exam): void
    {
        if ($exam->question_draw_count === null) {
            return;
        }

        $count = $exam->examinationQuestions->count();
        if ($exam->question_draw_count > $count) {
            throw ValidationException::withMessages(['question_draw_count' => sprintf('Questions per attempt (%d) is more than the %d selected questions. Add questions or lower the number.', $exam->question_draw_count, $count)]);
        }
        if ($exam->drawsSubset($count) && $exam->examinationQuestions->map(fn (ExaminationQuestion $item): string => (string) $item->points)->unique()->count() > 1) {
            throw ValidationException::withMessages(['question_draw_count' => 'When each attempt draws only some of the questions, every question must be worth the same points, so all candidates have the same maximum score. Set equal points or deliver all questions.']);
        }
    }

    private function authorize(User $user, int $offeringId): void
    {
        abort_unless($user->hasPermission(Permission::ManageExaminations) && $user->teachesOffering($offeringId), 403);
    }

    private function draft(Examination $exam): void
    {
        if ($exam->status !== ExaminationStatus::Draft) {
            throw ValidationException::withMessages(['examination' => 'Only draft examinations may be edited.']);
        }
    }

    private function snapshot(Examination $exam): array
    {
        return $exam->only(['class_subject_id', 'kind', 'title', 'status', 'duration_minutes', 'attempt_limit', 'passing_score', 'opens_at', 'closes_at', 'randomize_questions', 'randomize_choices', 'question_draw_count', 'one_question_at_a_time', 'allow_back_navigation', 'auto_submit', 'release_results']) + ['has_access_code' => $exam->access_code !== null];
    }
}
