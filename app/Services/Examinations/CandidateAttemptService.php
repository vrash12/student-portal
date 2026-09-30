<?php

namespace App\Services\Examinations;

use App\Enums\ExaminationStatus;
use App\Enums\QuestionType;
use App\Models\Candidate;
use App\Models\Examination;
use App\Models\ExaminationAttempt;
use App\Models\User;
use App\Services\QuestionBank\QuestionPresenter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

final class CandidateAttemptService
{
    public function __construct(private readonly ExaminationScoringService $scoring) {}

    public function eligible(User $user, Examination $exam): bool
    {
        return $user->candidate !== null && $user->candidate->isGradableIn((int) $exam->classSubject->class_batch_id) && $exam->status === ExaminationStatus::Published;
    }

    public function start(User $user, Examination $exam, ?string $code): ExaminationAttempt
    {
        return DB::transaction(function () use ($user, $exam, $code) {
            abort_unless($user->candidate, 403);
            Candidate::whereKey($user->candidate->id)->lockForUpdate()->firstOrFail();
            $exam = Examination::whereKey($exam->id)->lockForUpdate()->firstOrFail();
            abort_unless($this->eligible($user, $exam), 403);
            $existing = ExaminationAttempt::where('candidate_id', $user->candidate->id)->where('examination_id', $exam->id)->where('status', 'in_progress')->lockForUpdate()->first();
            if ($existing) {
                return $this->expire($existing);
            }
            if (! $exam->isActive() || ! $exam->duration_minutes) {
                throw ValidationException::withMessages(['examination' => 'This examination is not available to start.']);
            }
            if ($exam->access_code !== null && ! hash_equals($exam->access_code, $code ?? '')) {
                throw ValidationException::withMessages(['access_code' => 'The access code is incorrect.']);
            }
            $number = ExaminationAttempt::where('candidate_id', $user->candidate->id)->where('examination_id', $exam->id)->count() + 1;
            if ($number > $exam->attempt_limit) {
                throw ValidationException::withMessages(['examination' => 'You have used all permitted attempts.']);
            }
            $items = $exam->examinationQuestions()->with('question.choices')->get();
            if ($items->isEmpty()) {
                throw ValidationException::withMessages(['examination' => 'This examination has no questions.']);
            }
            if ($exam->randomize_questions) {
                $items = $items->shuffle();
            }
            $delivery = $items->map(function ($item) use ($exam) {
                $question = app(QuestionPresenter::class)->forCandidate($item->question);
                if ($exam->randomize_choices && $item->question->type === QuestionType::MultipleChoice) {
                    $question['choices'] = collect($question['choices'])->shuffle()->values()->all();
                }

                return ['id' => $item->id, 'points' => $item->points, 'question' => $question];
            })->values()->all();
            $attempt = new ExaminationAttempt;
            $attempt->examination_id = $exam->id;
            $attempt->candidate_id = $user->candidate->id;
            $attempt->attempt_number = $number;
            $attempt->status = 'in_progress';
            $attempt->started_at = now();
            $attempt->last_activity_at = now();
            $attempt->expires_at = now()->addMinutes($exam->duration_minutes);
            if ($exam->closes_at && $exam->closes_at->lt($attempt->expires_at)) {
                $attempt->expires_at = $exam->closes_at;
            }
            $attempt->delivery = $delivery;
            $attempt->scoring_key = $this->scoring->snapshot($delivery);
            $attempt->passing_score = $exam->passing_score;
            $attempt->answers = [];
            $attempt->current_position = 0;
            $attempt->revision = 0;
            $attempt->save();

            return $attempt;
        });
    }

    public function expire(ExaminationAttempt $attempt): ExaminationAttempt
    {
        return DB::transaction(function () use ($attempt) {
            $attempt = ExaminationAttempt::whereKey($attempt->id)->lockForUpdate()->firstOrFail();
            if ($attempt->status === 'in_progress' && $attempt->expires_at?->lte(now())) {
                $attempt->status = $attempt->examination->auto_submit ? 'submitted' : 'expired';
                $attempt->submitted_at = $attempt->status === 'submitted' ? $attempt->expires_at : null;
                $attempt->submission_kind = $attempt->status === 'submitted' ? 'automatic' : null;
                $attempt->revision++;
                $attempt->save();
                $this->scoring->score($attempt);
            }

            return $attempt;
        });
    }

    public function read(User $user, ExaminationAttempt $attempt): ExaminationAttempt
    {
        Gate::forUser($user)->authorize('view', $attempt);

        return DB::transaction(fn () => $this->expire(ExaminationAttempt::whereKey($attempt->id)->lockForUpdate()->firstOrFail()));
    }

    public function heartbeat(User $user, ExaminationAttempt $attempt): ExaminationAttempt
    {
        Gate::forUser($user)->authorize('view', $attempt);

        return DB::transaction(function () use ($user, $attempt) {
            $attempt = $this->expire($attempt);
            if ($attempt->status === 'in_progress') {
                abort_unless($this->eligible($user, $attempt->examination), 403);
                $attempt->last_activity_at = now();
                $attempt->save();
            }

            return $attempt;
        });
    }

    /** Reconcile deadlines even when the candidate's tablet is disconnected. */
    public function expireDue(?int $examinationId = null): int
    {
        $count = 0;
        ExaminationAttempt::where('status', 'in_progress')->where('expires_at', '<=', now())
            ->when($examinationId !== null, fn ($query) => $query->where('examination_id', $examinationId))
            ->chunkById(100, function ($attempts) use (&$count) {
                foreach ($attempts as $attempt) {
                    $this->expire($attempt);
                    $count++;
                }
            });

        return $count;
    }

    /** Atomic saves prevent stale tabs from silently overwriting answers. */
    public function save(User $user, ExaminationAttempt $attempt, array $data, bool $submit = false): ExaminationAttempt
    {
        Gate::forUser($user)->authorize('view', $attempt);

        return DB::transaction(function () use ($user, $attempt, $data, $submit) {
            $attempt = $this->expire(ExaminationAttempt::whereKey($attempt->id)->lockForUpdate()->firstOrFail());
            if ($attempt->status !== 'in_progress') {
                return $attempt;
            }
            abort_unless($this->eligible($user, $attempt->examination), 403);
            $position = (int) $data['position'];
            $items = $attempt->delivery;
            if (! isset($items[$position]) || (! $attempt->examination->allow_back_navigation && $position !== $attempt->current_position)) {
                throw ValidationException::withMessages(['position' => 'You cannot return to this question.']);
            }
            $item = $items[$position];
            $answer = $data['answer'] ?? null;
            $essay = $item['question']['type']['value'] === 'essay';
            if ($answer !== null && (($essay && ! is_string($answer)) || (! $essay && ! in_array($answer, array_column($item['question']['choices'], 'id'), true)))) {
                throw ValidationException::withMessages(['answer' => 'Choose an answer belonging to this question.']);
            }
            $next = (int) ($data['next_position'] ?? $position);
            $requested = ['value' => $answer, 'flagged' => $attempt->examination->allow_back_navigation && ($data['flagged'] ?? false)];
            if ($attempt->revision !== (int) $data['revision']) {
                // Retrying the same payload after a lost response is safe and idempotent.
                if (($attempt->answers[$item['id']] ?? null) === $requested && $attempt->current_position === $next) {
                    if (! $submit) {
                        return $attempt;
                    }
                } else {
                    throw ValidationException::withMessages(['attempt' => 'This attempt changed in another tab. Reload before continuing.']);
                }
            }
            $answers = $attempt->answers;
            $answers[$item['id']] = $requested;
            if (! isset($items[$next]) || (! $attempt->examination->allow_back_navigation && ($next < $position || $next > $position + 1))) {
                throw ValidationException::withMessages(['position' => 'Invalid question navigation.']);
            }
            $attempt->answers = $answers;
            $attempt->current_position = $next;
            $attempt->last_activity_at = now();
            $attempt->revision++;
            if ($submit) {
                $attempt->status = 'submitted';
                $attempt->submitted_at = now();
                $attempt->submission_kind = 'manual';
            }
            $attempt->save();
            if ($submit) {
                $this->scoring->score($attempt);
            }

            return $attempt;
        });
    }
}
