<?php

namespace App\Services\Examinations;

use App\Enums\AuditAction;
use App\Models\ExaminationAttempt;
use App\Models\ExaminationEssayGrade;
use App\Models\ExaminationEssayRevision;
use App\Models\ExaminationQuestion;
use App\Models\User;
use App\Services\AuditLogger;
use App\Support\DecimalValue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

final class ManualEssayGradingService
{
    public function grade(User $grader, ExaminationAttempt $attempt, int $itemId, string $score, ?string $comment, int $version, ?string $reason): ExaminationAttempt
    {
        Gate::forUser($grader)->authorize('grade', $attempt);

        return DB::transaction(function () use ($grader, $attempt, $itemId, $score, $comment, $version, $reason) {
            $attempt = ExaminationAttempt::whereKey($attempt->id)->lockForUpdate()->firstOrFail();
            Gate::forUser($grader)->authorize('grade', $attempt);
            if ($attempt->status !== 'submitted') {
                throw ValidationException::withMessages(['attempt' => 'Only submitted attempts can be graded.']);
            }
            app(ExaminationScoringService::class)->score($attempt);
            $key = $attempt->scoring_key[(string) $itemId] ?? null;
            if ($key === null || $key['type'] !== 'essay') {
                throw ValidationException::withMessages(['item' => 'This item is not an essay response.']);
            }
            $maximum = (float) $key['points'];
            if (! is_numeric($score) || ! is_finite((float) $score) || (float) $score < 0 || (float) $score > $maximum || round((float) $score, 2) !== (float) $score) {
                throw ValidationException::withMessages(['score' => "Score must be between 0 and {$maximum}."]);
            }
            ExaminationQuestion::whereKey($itemId)->where('examination_id', $attempt->examination_id)->firstOrFail();
            $grade = ExaminationEssayGrade::where('examination_attempt_id', $attempt->id)->where('examination_question_id', $itemId)->first();
            $score = DecimalValue::normalize($score);
            // A retry of an acknowledged save does not generate another history entry.
            if ($grade && $grade->score === $score && $grade->comment === $comment) {
                return $attempt;
            }
            if (($grade?->version ?? 0) !== $version) {
                throw ValidationException::withMessages(['version' => 'Another instructor changed this score. Reload the response before saving.']);
            }
            if ($grade && trim($reason ?? '') === '') {
                throw ValidationException::withMessages(['reason' => 'Explain why you are changing the recorded grade or comment.']);
            }
            $previousScore = $grade?->score;
            $previousComment = $grade?->comment;
            $grade ??= new ExaminationEssayGrade;
            $grade->examination_attempt_id = $attempt->id;
            $grade->examination_question_id = $itemId;
            $grade->max_points = $key['points'];
            $grade->version = $version + 1;
            $grade->score = $score;
            $grade->comment = $comment;
            $grade->graded_by = $grader->id;
            $grade->graded_at = now();
            $grade->save();
            $revision = new ExaminationEssayRevision;
            $revision->examination_essay_grade_id = $grade->id;
            $revision->actor_id = $grader->id;
            $revision->version = $grade->version;
            $revision->previous_score = $previousScore;
            $revision->new_score = $score;
            $revision->previous_comment = $previousComment;
            $revision->new_comment = $comment;
            $revision->reason = $reason;
            $revision->save();
            app(ExaminationScoringService::class)->recalculate($attempt);
            // Detailed feedback is retained in authorized grading history, not general audit data.
            app(AuditLogger::class)->record(AuditAction::ExaminationEssayGraded, $attempt,
                ['score' => $previousScore],
                ['score' => $score, 'item_id' => $itemId, 'grade_id' => $grade->id, 'version' => $grade->version],
                actor: $grader);

            return $attempt->fresh();
        });
    }
}
