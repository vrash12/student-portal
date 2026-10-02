<?php

namespace App\Services\Grading;

use App\Enums\AuditAction;
use App\Enums\CorrectionIncidentType;
use App\Enums\GradeCorrectionStatus;
use App\Models\Assessment;
use App\Models\Candidate;
use App\Models\GradeCorrectionRequest;
use App\Models\User;
use App\Services\AuditLogger;
use App\Support\DecimalValue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Authorized correction of finalized scores (owner request, 2026-10-02).
 *
 * An instructor cannot overwrite a finalized score. They file a request with
 * the new score and an incident report (what happened and why). An
 * administrator with the approval permission approves it, and only then is
 * the score corrected (ScoreRecordingService, with the request linked to the
 * revision), or rejects it with a reason. The requester may cancel a request
 * while it is pending. Nobody approves or rejects their own request.
 *
 * The score at filing time is kept on the request; approval refuses a
 * request whose score has changed since, so an administrator never applies a
 * correction to a value the instructor did not see. Every step is audited.
 */
class GradeCorrectionService
{
    public const DETAILS_MIN = 20;

    public const DETAILS_MAX = 2000;

    public const NOTE_MAX = 1000;

    public function __construct(
        private readonly ScoreRecordingService $scores,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Files a correction request for one candidate's finalized score.
     *
     * @throws ValidationException
     */
    public function request(
        Assessment $assessment,
        int $candidateId,
        ?string $score,
        ?string $comment,
        CorrectionIncidentType $incidentType,
        string $incidentDetails,
        ?string $expectedScore,
        ?string $expectedComment,
        User $actor,
    ): GradeCorrectionRequest {
        return DB::transaction(function () use ($assessment, $candidateId, $score, $comment, $incidentType, $incidentDetails, $expectedScore, $expectedComment, $actor): GradeCorrectionRequest {
            $locked = Assessment::query()->with('classSubject')->lockForUpdate()->findOrFail($assessment->getKey());

            if (! $locked->isFinalized()) {
                throw ValidationException::withMessages(['score' => 'This assessment is still a draft. Change its scores in the score sheet.']);
            }

            $details = trim($incidentDetails);
            if (mb_strlen($details) < self::DETAILS_MIN) {
                throw ValidationException::withMessages(['incident_details' => 'Describe what happened in at least '.self::DETAILS_MIN.' characters.']);
            }

            $candidate = Candidate::query()->gradableIn($locked->classSubject->class_batch_id)->find($candidateId);
            if ($candidate === null) {
                throw ValidationException::withMessages(['candidate_id' => 'This candidate is not in this class and cannot be graded here.']);
            }

            $score = DecimalValue::normalize($score);
            $comment = $this->normalizeComment($comment);
            if ($score !== null && DecimalValue::toHundredths($score) > DecimalValue::toHundredths($locked->max_score)) {
                throw ValidationException::withMessages(['score' => sprintf('Enter a score from 0 to %s.', DecimalValue::display($locked->max_score))]);
            }

            $existing = $locked->scores()->where('candidate_id', $candidate->id)->lockForUpdate()->first();
            $currentScore = DecimalValue::normalize($existing?->score);
            $currentComment = $existing?->comment;

            if (DecimalValue::normalize($expectedScore) !== $currentScore || $this->normalizeComment($expectedComment) !== $currentComment) {
                $now = $currentScore === null ? 'no score' : DecimalValue::display($currentScore);

                throw ValidationException::withMessages(['score' => "This score changed while you were editing (it is now {$now}). Review it and send the request again."]);
            }

            if ($score === $currentScore && $comment === $currentComment) {
                throw ValidationException::withMessages(['score' => 'Enter a different score or comment to request a correction.']);
            }

            $alreadyPending = GradeCorrectionRequest::query()
                ->where('assessment_id', $locked->id)
                ->where('candidate_id', $candidate->id)
                ->where('status', GradeCorrectionStatus::Pending->value)
                ->lockForUpdate()
                ->exists();
            if ($alreadyPending) {
                throw ValidationException::withMessages(['candidate_id' => 'A correction for this candidate is already waiting for approval. Cancel it first to file a new one.']);
            }

            $request = new GradeCorrectionRequest;
            $request->assessment()->associate($locked);
            $request->candidate()->associate($candidate);
            $request->requester()->associate($actor);
            $request->current_score = $currentScore;
            $request->current_comment = $currentComment;
            $request->proposed_score = $score;
            $request->proposed_comment = $comment;
            $request->incident_type = $incidentType;
            $request->incident_details = $details;
            $request->status = GradeCorrectionStatus::Pending;
            $request->save();

            $this->audit->record(
                AuditAction::GradeCorrectionRequested,
                $request,
                oldValues: ['score' => $currentScore, 'comment' => $currentComment],
                newValues: [
                    'assessment' => $locked->title,
                    'candidate' => $candidate->candidate_number,
                    'score' => $score,
                    'comment' => $comment,
                    'incident_type' => $incidentType->label(),
                ],
                reason: Str::limit($details, 490),
                actor: $actor,
            );

            return $request;
        });
    }

    /**
     * Approves a pending request: the finalized score is corrected now.
     *
     * @throws ValidationException
     */
    public function approve(GradeCorrectionRequest $request, ?string $note, User $approver): void
    {
        DB::transaction(function () use ($request, $note, $approver): void {
            // The assessment first, then the request: the same order as filing, so the two never wait on each other.
            Assessment::query()->lockForUpdate()->findOrFail($request->assessment_id);
            $locked = $this->lock($request);
            $this->ensureDecidable($locked, $approver, 'approve');

            try {
                $this->scores->correctFinalizedScore(
                    $locked->assessment,
                    (int) $locked->candidate_id,
                    DecimalValue::normalize($locked->proposed_score),
                    $locked->proposed_comment,
                    $this->revisionReason($locked, $approver),
                    DecimalValue::normalize($locked->current_score),
                    $locked->current_comment,
                    $approver,
                    $locked,
                );
            } catch (ValidationException $problem) {
                $message = collect($problem->errors())->flatten()->first() ?? 'The score could not be changed.';

                throw ValidationException::withMessages([
                    'decision' => "This request can no longer be applied: {$message} Reject it, and the instructor can file a new request.",
                ]);
            }

            $note = $this->normalizeNote($note);
            $this->decide($locked, GradeCorrectionStatus::Approved, $note, $approver);
            $this->audit->record(
                AuditAction::GradeCorrectionApproved,
                $locked,
                oldValues: ['score' => DecimalValue::normalize($locked->current_score)],
                newValues: $this->summary($locked),
                reason: $note,
                actor: $approver,
            );
        });
    }

    /**
     * Rejects a pending request; the score stays as it is. A reason is required.
     *
     * @throws ValidationException
     */
    public function reject(GradeCorrectionRequest $request, string $note, User $approver): void
    {
        DB::transaction(function () use ($request, $note, $approver): void {
            $locked = $this->lock($request);
            $this->ensureDecidable($locked, $approver, 'reject');

            $note = $this->normalizeNote($note);
            if ($note === null) {
                throw ValidationException::withMessages(['note' => 'Explain why the request is rejected.']);
            }

            $this->decide($locked, GradeCorrectionStatus::Rejected, $note, $approver);
            $this->audit->record(AuditAction::GradeCorrectionRejected, $locked, newValues: $this->summary($locked), reason: $note, actor: $approver);
        });
    }

    /**
     * Withdraws a pending request (its requester only, checked by the policy).
     *
     * @throws ValidationException
     */
    public function cancel(GradeCorrectionRequest $request, User $actor): void
    {
        DB::transaction(function () use ($request, $actor): void {
            $locked = $this->lock($request);
            if (! $locked->isPending()) {
                throw ValidationException::withMessages(['decision' => 'This request was already '.strtolower($locked->status->label()).'.']);
            }

            $this->decide($locked, GradeCorrectionStatus::Cancelled, null, $actor);
            $this->audit->record(AuditAction::GradeCorrectionCancelled, $locked, newValues: $this->summary($locked), actor: $actor);
        });
    }

    private function lock(GradeCorrectionRequest $request): GradeCorrectionRequest
    {
        return GradeCorrectionRequest::query()
            ->with(['assessment', 'candidate:id,candidate_number', 'requester:id,name'])
            ->lockForUpdate()
            ->findOrFail($request->getKey());
    }

    /**
     * @throws ValidationException
     */
    private function ensureDecidable(GradeCorrectionRequest $request, User $approver, string $verb): void
    {
        if (! $request->isPending()) {
            throw ValidationException::withMessages(['decision' => 'This request was already '.strtolower($request->status->label()).'.']);
        }

        if ((int) $request->requested_by === (int) $approver->getKey()) {
            throw ValidationException::withMessages(['decision' => "You cannot {$verb} your own correction request."]);
        }
    }

    private function decide(GradeCorrectionRequest $request, GradeCorrectionStatus $status, ?string $note, User $actor): void
    {
        $request->status = $status;
        $request->decision_note = $note;
        $request->decider()->associate($actor);
        $request->decided_at = now();
        $request->save();
    }

    /**
     * Kept on the score revision, so the change history explains itself.
     */
    private function revisionReason(GradeCorrectionRequest $request, User $approver): string
    {
        return Str::limit(sprintf(
            'Correction request #%d (%s), requested by %s and approved by %s: %s',
            $request->id,
            $request->incident_type->label(),
            $request->requester->name,
            $approver->name,
            $request->incident_details,
        ), 500);
    }

    /**
     * @return array<string, string|null>
     */
    private function summary(GradeCorrectionRequest $request): array
    {
        return [
            'status' => $request->status->value,
            'assessment' => $request->assessment->title,
            'candidate' => $request->candidate->candidate_number,
            'score' => DecimalValue::normalize($request->proposed_score),
        ];
    }

    private function normalizeComment(?string $comment): ?string
    {
        $comment = $comment === null ? null : trim($comment);

        return $comment === '' ? null : $comment;
    }

    private function normalizeNote(?string $note): ?string
    {
        $note = $note === null ? null : trim($note);

        return $note === '' ? null : $note;
    }
}
