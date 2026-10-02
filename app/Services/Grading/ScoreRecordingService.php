<?php

namespace App\Services\Grading;

use App\Enums\AuditAction;
use App\Enums\ScoreRevisionKind;
use App\Models\Assessment;
use App\Models\AssessmentScore;
use App\Models\AssessmentScoreRevision;
use App\Models\Candidate;
use App\Models\GradeCorrectionRequest;
use App\Models\User;
use App\Services\AuditLogger;
use App\Support\DecimalValue;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Records candidate scores and keeps their full change history.
 *
 * - Draft assessments: scores are saved in batches from the score sheet.
 * - Finalized assessments: one score at a time, through a correction that
 *   requires a reason and an approved grade correction request.
 *
 * Both paths lock the assessment row, re-check that every candidate belongs
 * to the class, re-check the maximum score, and detect edits made by another
 * user since the sheet was opened (the client sends the value it last saw).
 * Nothing is written unless the whole request is valid.
 */
final class ScoreRecordingService
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @param  array<int, array{score: ?string, comment: ?string, expected_score: ?string, expected_comment: ?string}>  $entries  keyed by candidate id
     * @return int number of candidates whose score or comment changed
     *
     * @throws ValidationException
     */
    public function recordDraftScores(Assessment $assessment, array $entries, User $actor): int
    {
        return DB::transaction(function () use ($assessment, $entries, $actor): int {
            $locked = $this->lock($assessment);

            if (! $locked->isDraft()) {
                throw ValidationException::withMessages([
                    'entries' => 'This assessment was finalized while you were editing. Your changes were not saved. Use Correct Score to change a finalized score.',
                ]);
            }

            $gradable = $this->gradableCandidateIds($locked, array_keys($entries));
            $current = $this->currentScores($locked, array_keys($entries));

            $errors = [];
            $changes = [];
            foreach ($entries as $candidateId => $entry) {
                $score = DecimalValue::normalize($entry['score']);
                $comment = $this->normalizeComment($entry['comment']);

                if (! in_array($candidateId, $gradable, true)) {
                    $errors["entries.{$candidateId}.score"] = 'This candidate is not in this class and cannot be graded here.';

                    continue;
                }

                $error = $this->scoreError($locked, $score);
                if ($error !== null) {
                    $errors["entries.{$candidateId}.score"] = $error;

                    continue;
                }

                $existing = $current->get($candidateId);
                if (! $this->differs($existing, $score, $comment)) {
                    continue;
                }

                if ($this->differs($existing, DecimalValue::normalize($entry['expected_score']), $this->normalizeComment($entry['expected_comment']))) {
                    $errors["entries.{$candidateId}.score"] = $this->conflictMessage($existing);

                    continue;
                }

                $changes[$candidateId] = [$existing, $score, $comment];
            }

            if ($errors !== []) {
                throw ValidationException::withMessages($errors);
            }

            foreach ($changes as $candidateId => [$existing, $score, $comment]) {
                $this->write(
                    $locked,
                    $candidateId,
                    $existing,
                    $score,
                    $comment,
                    $existing === null ? ScoreRevisionKind::Recorded : ScoreRevisionKind::Updated,
                    reason: null,
                    actor: $actor,
                );
            }

            if ($changes !== []) {
                $this->audit->record(AuditAction::AssessmentScoresRecorded, $locked, newValues: [
                    'title' => $locked->title,
                    'scores_changed' => count($changes),
                ], actor: $actor);
            }

            return count($changes);
        });
    }

    /**
     * Changes one score of a finalized assessment. The reason is stored with
     * the revision and in the audit log. Staff never call this directly: it
     * runs when an administrator approves a grade correction request
     * (GradeCorrectionService), which is linked to the revision.
     *
     * @throws ValidationException
     */
    public function correctFinalizedScore(
        Assessment $assessment,
        int $candidateId,
        ?string $score,
        ?string $comment,
        string $reason,
        ?string $expectedScore,
        ?string $expectedComment,
        User $actor,
        ?GradeCorrectionRequest $approvedRequest = null,
    ): void {
        DB::transaction(function () use ($assessment, $candidateId, $score, $comment, $reason, $expectedScore, $expectedComment, $actor, $approvedRequest): void {
            $locked = $this->lock($assessment);

            if (! $locked->isFinalized()) {
                throw ValidationException::withMessages([
                    'score' => 'This assessment is still a draft. Change its scores in the score sheet.',
                ]);
            }

            $reason = trim($reason);
            if ($reason === '') {
                throw ValidationException::withMessages(['reason' => 'Explain why this finalized score is being corrected.']);
            }

            if ($this->gradableCandidateIds($locked, [$candidateId]) === []) {
                throw ValidationException::withMessages(['candidate_id' => 'This candidate is not in this class and cannot be graded here.']);
            }

            $score = DecimalValue::normalize($score);
            $comment = $this->normalizeComment($comment);

            $error = $this->scoreError($locked, $score);
            if ($error !== null) {
                throw ValidationException::withMessages(['score' => $error]);
            }

            $existing = $this->currentScores($locked, [$candidateId])->get($candidateId);

            if ($this->differs($existing, DecimalValue::normalize($expectedScore), $this->normalizeComment($expectedComment))) {
                throw ValidationException::withMessages(['score' => $this->conflictMessage($existing)]);
            }

            if (! $this->differs($existing, $score, $comment)) {
                throw ValidationException::withMessages(['score' => 'Enter a different score or comment to record a correction.']);
            }

            // Captured before write(), which updates the same model instance.
            $previous = ['score' => DecimalValue::normalize($existing?->score), 'comment' => $existing?->comment];

            $record = $this->write($locked, $candidateId, $existing, $score, $comment, ScoreRevisionKind::Corrected, $reason, $actor, $approvedRequest);
            $record->loadMissing('candidate:id,candidate_number');

            $this->audit->record(
                AuditAction::AssessmentScoreCorrected,
                $record,
                oldValues: $previous,
                newValues: [
                    'assessment' => $locked->title,
                    'candidate' => $record->candidate->candidate_number,
                    'score' => $score,
                    'comment' => $comment,
                ],
                reason: $reason,
                actor: $actor,
            );
        });
    }

    private function lock(Assessment $assessment): Assessment
    {
        return Assessment::query()->with('classSubject')->lockForUpdate()->findOrFail($assessment->getKey());
    }

    /**
     * @param  list<int>  $candidateIds
     * @return list<int>
     */
    private function gradableCandidateIds(Assessment $assessment, array $candidateIds): array
    {
        return Candidate::query()
            ->gradableIn($assessment->classSubject->class_batch_id)
            ->whereIn('id', $candidateIds)
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();
    }

    /**
     * Current score rows, locked until the transaction ends.
     *
     * @param  list<int>  $candidateIds
     * @return Collection<int, AssessmentScore>
     */
    private function currentScores(Assessment $assessment, array $candidateIds): Collection
    {
        return $assessment->scores()
            ->whereIn('candidate_id', $candidateIds)
            ->lockForUpdate()
            ->get()
            ->keyBy(fn (AssessmentScore $score): int => (int) $score->candidate_id);
    }

    private function scoreError(Assessment $assessment, ?string $score): ?string
    {
        if ($score === null) {
            return null;
        }

        $value = DecimalValue::toHundredths($score);
        if ($value < 0 || $value > DecimalValue::toHundredths($assessment->max_score)) {
            return sprintf('Enter a score from 0 to %s.', DecimalValue::display($assessment->max_score));
        }

        return null;
    }

    private function differs(?AssessmentScore $existing, ?string $score, ?string $comment): bool
    {
        return DecimalValue::normalize($existing?->score) !== $score || ($existing?->comment) !== $comment;
    }

    private function conflictMessage(?AssessmentScore $existing): string
    {
        $now = $existing?->score === null ? 'no score' : DecimalValue::display($existing->score);

        return "Another user changed this score while you were editing (it is now {$now}). Review it and save again to replace it.";
    }

    private function normalizeComment(?string $comment): ?string
    {
        $comment = $comment === null ? null : trim($comment);

        return $comment === '' ? null : $comment;
    }

    private function write(
        Assessment $assessment,
        int $candidateId,
        ?AssessmentScore $existing,
        ?string $score,
        ?string $comment,
        ScoreRevisionKind $kind,
        ?string $reason,
        User $actor,
        ?GradeCorrectionRequest $approvedRequest = null,
    ): AssessmentScore {
        $previousScore = DecimalValue::normalize($existing?->score);

        $record = $existing ?? new AssessmentScore;
        if ($existing === null) {
            $record->assessment()->associate($assessment);
            $record->candidate_id = $candidateId;
        }
        $record->score = $score;
        $record->comment = $comment;
        $record->recorder()->associate($actor);
        $record->save();

        $revision = new AssessmentScoreRevision;
        $revision->assessmentScore()->associate($record);
        $revision->kind = $kind;
        $revision->previous_score = $previousScore;
        $revision->new_score = $score;
        $revision->comment = $comment;
        $revision->reason = $reason;
        $revision->grade_correction_request_id = $approvedRequest?->getKey();
        $revision->changer()->associate($actor);
        $revision->save();

        return $record;
    }
}
