<?php

namespace App\Services\Medical;

use App\Enums\AuditAction;
use App\Enums\MedicalAccessStatus;
use App\Enums\Permission;
use App\Models\Candidate;
use App\Models\MedicalAccessRequest;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Instructors' access to full medical records (owner request, 2026-10-02).
 *
 * An instructor who teaches the candidate's class asks, with a reason, to see
 * the candidate's full medical record. Medical staff approve it for 1, 7 or
 * 30 days (read-only; the instructor must still teach the class), reject it
 * with a reason, or withdraw approved access early. The requester may cancel
 * a pending request. Nobody decides their own request. Every step, and every
 * view of a record seen through such access, is audited (without values).
 */
class MedicalAccessService
{
    /** Days an approval may last; the default is 7. */
    public const DURATIONS = [1, 7, 30];

    public const DEFAULT_DURATION = 7;

    public const REASON_MIN = 20;

    public const REASON_MAX = 1000;

    public function __construct(
        private readonly AuditLogger $audit,
        private readonly MedicalDownloadService $downloads,
    ) {}

    /**
     * The approved, unexpired access of this viewer to this candidate, if any.
     */
    public static function activeGrant(User $viewer, Candidate $candidate): ?MedicalAccessRequest
    {
        return MedicalAccessRequest::query()
            ->active()
            ->where('candidate_id', $candidate->id)
            ->where('requested_by', $viewer->id)
            ->with('decider:id,name')
            ->latest('expires_at')
            ->first();
    }

    /**
     * @throws ValidationException
     */
    public function request(Candidate $candidate, string $reason, User $actor): MedicalAccessRequest
    {
        return DB::transaction(function () use ($candidate, $reason, $actor): MedicalAccessRequest {
            $reason = trim($reason);
            if (mb_strlen($reason) < self::REASON_MIN) {
                throw ValidationException::withMessages(['reason' => 'Explain why you need the full record in at least '.self::REASON_MIN.' characters.']);
            }

            $existing = MedicalAccessRequest::query()
                ->where('candidate_id', $candidate->id)
                ->where('requested_by', $actor->id)
                ->where(fn ($open) => $open->where('status', MedicalAccessStatus::Pending->value)->orWhere(fn ($active) => $active->active()))
                ->lockForUpdate()
                ->first();
            if ($existing !== null) {
                throw ValidationException::withMessages(['reason' => $existing->isPending()
                    ? 'Your request for this candidate is already waiting for approval.'
                    : 'You already have access to this record until '.$existing->expires_at?->format('M j, Y g:i A').'.']);
            }

            $request = new MedicalAccessRequest;
            $request->candidate()->associate($candidate);
            $request->requester()->associate($actor);
            $request->reason = $reason;
            $request->status = MedicalAccessStatus::Pending;
            $request->save();

            $this->audit->record(AuditAction::MedicalAccessRequested, $request, newValues: ['candidate' => $candidate->candidate_number], reason: $reason, actor: $actor);

            return $request;
        });
    }

    /**
     * @throws ValidationException
     */
    public function approve(MedicalAccessRequest $request, int $days, ?string $note, User $approver): void
    {
        if (! in_array($days, self::DURATIONS, true)) {
            throw ValidationException::withMessages(['days' => 'Choose 1, 7 or 30 days.']);
        }

        DB::transaction(function () use ($request, $days, $note, $approver): void {
            $locked = $this->lockPending($request, $approver, 'approve');
            $locked->status = MedicalAccessStatus::Approved;
            $locked->expires_at = now()->addDays($days);
            $this->decide($locked, $note, $approver);

            $this->audit->record(AuditAction::MedicalAccessApproved, $locked, newValues: [
                'candidate' => $locked->candidate->candidate_number,
                'instructor' => $locked->requester->name,
                'days' => $days,
                'expires_at' => $locked->expires_at->toIso8601String(),
            ], reason: $this->clean($note), actor: $approver);
        });
    }

    /**
     * @throws ValidationException
     */
    public function reject(MedicalAccessRequest $request, string $note, User $approver): void
    {
        DB::transaction(function () use ($request, $note, $approver): void {
            $note = $this->clean($note);
            if ($note === null) {
                throw ValidationException::withMessages(['note' => 'Explain why the request is rejected.']);
            }

            $locked = $this->lockPending($request, $approver, 'reject');
            $locked->status = MedicalAccessStatus::Rejected;
            $this->decide($locked, $note, $approver);

            $this->audit->record(AuditAction::MedicalAccessRejected, $locked, newValues: ['candidate' => $locked->candidate->candidate_number, 'instructor' => $locked->requester->name], reason: $note, actor: $approver);
        });
    }

    /**
     * @throws ValidationException
     */
    public function cancel(MedicalAccessRequest $request, User $actor): void
    {
        DB::transaction(function () use ($request, $actor): void {
            $locked = $this->lock($request);
            if (! $locked->isPending()) {
                throw ValidationException::withMessages(['access' => 'This request is no longer waiting for approval.']);
            }

            $locked->status = MedicalAccessStatus::Cancelled;
            $this->decide($locked, null, $actor);

            $this->audit->record(AuditAction::MedicalAccessCancelled, $locked, newValues: ['candidate' => $locked->candidate->candidate_number], actor: $actor);
        });
    }

    /**
     * Ends approved access before its end date.
     *
     * @throws ValidationException
     */
    public function revoke(MedicalAccessRequest $request, User $actor): void
    {
        DB::transaction(function () use ($request, $actor): void {
            $locked = $this->lock($request);
            if (! $locked->isActive()) {
                throw ValidationException::withMessages(['access' => 'This access has already ended.']);
            }

            $locked->status = MedicalAccessStatus::Revoked;
            $locked->revoker()->associate($actor);
            $locked->revoked_at = now();
            $locked->save();

            $this->audit->record(AuditAction::MedicalAccessRevoked, $locked, newValues: ['candidate' => $locked->candidate->candidate_number, 'instructor' => $locked->requester->name], actor: $actor);

            // Approved downloads of this candidate's documents end with the access.
            $this->downloads->revokeAllFor((int) $locked->candidate_id, (int) $locked->requested_by, $actor);
        });
    }

    /**
     * Records that an instructor opened a full record through approved access.
     */
    public function recordView(int $accessRequestId, Candidate $candidate, User $viewer): void
    {
        $this->audit->record(AuditAction::MedicalRecordViewed, $candidate, newValues: ['candidate' => $candidate->candidate_number, 'access_request' => $accessRequestId], actor: $viewer);
    }

    private function lock(MedicalAccessRequest $request): MedicalAccessRequest
    {
        return MedicalAccessRequest::query()
            ->with(['candidate:id,candidate_number', 'requester:id,name'])
            ->lockForUpdate()
            ->findOrFail($request->getKey());
    }

    /**
     * @throws ValidationException
     */
    private function lockPending(MedicalAccessRequest $request, User $approver, string $verb): MedicalAccessRequest
    {
        $locked = $this->lock($request);
        if (! $locked->isPending()) {
            throw ValidationException::withMessages(['access' => 'This request was already decided.']);
        }
        if ((int) $locked->requested_by === (int) $approver->getKey()) {
            throw ValidationException::withMessages(['access' => "You cannot {$verb} your own request."]);
        }
        if (! $approver->hasPermission(Permission::ManageMedical)) {
            throw ValidationException::withMessages(['access' => 'Only medical staff decide these requests.']);
        }

        return $locked;
    }

    private function decide(MedicalAccessRequest $request, ?string $note, User $actor): void
    {
        $request->decision_note = $this->clean($note);
        $request->decider()->associate($actor);
        $request->decided_at = now();
        $request->save();
    }

    private function clean(?string $text): ?string
    {
        $text = $text === null ? null : trim($text);

        return $text === '' ? null : $text;
    }
}
