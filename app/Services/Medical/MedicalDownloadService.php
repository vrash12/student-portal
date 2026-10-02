<?php

namespace App\Services\Medical;

use App\Enums\AuditAction;
use App\Enums\MedicalDownloadStatus;
use App\Enums\Permission;
use App\Models\CandidateMedicalDocument;
use App\Models\MedicalDownloadRequest;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Instructors' downloads of uploaded medical documents (owner request,
 * 2026-10-02): "it can be viewed but not downloadable … it must be requested
 * to the admin for it to be downloaded".
 *
 * An instructor who can view a document (they teach the candidate's class)
 * asks, with a reason, for a copy. Medical staff approve it, which lets that
 * instructor download that one document for DAYS days while they still teach
 * the class, or reject it with a reason; they may withdraw an approval early.
 * The requester may cancel a pending request. Nobody decides their own request. Every step and every
 * download is audited without document titles or contents.
 *
 * Delivery is in the app for now: the approved instructor downloads the file
 * from the document list. When email is set up, approve() is where the file
 * will also be sent to the instructor's email.
 */
class MedicalDownloadService
{
    /** Days an approved download stays available. */
    public const DAYS = 3;

    public const REASON_MIN = 20;

    public const REASON_MAX = 1000;

    public function __construct(
        private readonly AuditLogger $audit,
        private readonly MedicalDocumentService $documents,
    ) {}

    /**
     * The approved, unexpired download of this viewer for this document, if any.
     */
    public static function activeApproval(User $viewer, CandidateMedicalDocument $document): ?MedicalDownloadRequest
    {
        return MedicalDownloadRequest::query()
            ->active()
            ->where('candidate_medical_document_id', $document->id)
            ->where('requested_by', $viewer->id)
            ->latest('expires_at')
            ->first();
    }

    /**
     * @throws ValidationException
     */
    public function request(CandidateMedicalDocument $document, string $reason, User $actor): MedicalDownloadRequest
    {
        return DB::transaction(function () use ($document, $reason, $actor): MedicalDownloadRequest {
            $reason = trim($reason);
            if (mb_strlen($reason) < self::REASON_MIN) {
                throw ValidationException::withMessages(['reason' => 'Explain why you need a copy in at least '.self::REASON_MIN.' characters.']);
            }

            $existing = MedicalDownloadRequest::query()
                ->where('candidate_medical_document_id', $document->id)
                ->where('requested_by', $actor->id)
                ->where(fn ($open) => $open->where('status', MedicalDownloadStatus::Pending->value)->orWhere(fn ($active) => $active->active()))
                ->lockForUpdate()
                ->first();
            if ($existing !== null) {
                throw ValidationException::withMessages(['reason' => $existing->isPending()
                    ? 'Your download request for this document is already waiting for approval.'
                    : 'You can already download this document until '.$existing->expires_at?->format('M j, Y g:i A').'.']);
            }

            $request = new MedicalDownloadRequest;
            $request->document()->associate($document);
            $request->candidate_id = $document->candidate_id;
            $request->requester()->associate($actor);
            $request->reason = $reason;
            $request->status = MedicalDownloadStatus::Pending;
            $request->save();

            $this->audit->record(AuditAction::MedicalDownloadRequested, $request, newValues: $this->summary($request), reason: $reason, actor: $actor);

            return $request;
        });
    }

    /**
     * @throws ValidationException
     */
    public function approve(MedicalDownloadRequest $request, ?string $note, User $approver): void
    {
        DB::transaction(function () use ($request, $note, $approver): void {
            $locked = $this->lockPending($request, $approver, 'approve');
            $locked->status = MedicalDownloadStatus::Approved;
            $locked->expires_at = now()->addDays(self::DAYS);
            $this->decide($locked, $note, $approver);

            $this->audit->record(AuditAction::MedicalDownloadApproved, $locked, newValues: [
                ...$this->summary($locked),
                'instructor' => $locked->requester->name,
                'expires_at' => $locked->expires_at->toIso8601String(),
            ], reason: $this->clean($note), actor: $approver);

            // Email delivery (to be added when email is set up) belongs here.
        });
    }

    /**
     * @throws ValidationException
     */
    public function reject(MedicalDownloadRequest $request, string $note, User $approver): void
    {
        DB::transaction(function () use ($request, $note, $approver): void {
            $note = $this->clean($note);
            if ($note === null) {
                throw ValidationException::withMessages(['note' => 'Explain why the request is rejected.']);
            }

            $locked = $this->lockPending($request, $approver, 'reject');
            $locked->status = MedicalDownloadStatus::Rejected;
            $this->decide($locked, $note, $approver);

            $this->audit->record(AuditAction::MedicalDownloadRejected, $locked, newValues: [...$this->summary($locked), 'instructor' => $locked->requester->name], reason: $note, actor: $approver);
        });
    }

    /**
     * @throws ValidationException
     */
    public function cancel(MedicalDownloadRequest $request, User $actor): void
    {
        DB::transaction(function () use ($request, $actor): void {
            $locked = $this->lock($request);
            if (! $locked->isPending()) {
                throw ValidationException::withMessages(['download' => 'This request is no longer waiting for approval.']);
            }

            $locked->status = MedicalDownloadStatus::Cancelled;
            $this->decide($locked, null, $actor);

            $this->audit->record(AuditAction::MedicalDownloadCancelled, $locked, newValues: $this->summary($locked), actor: $actor);
        });
    }

    /**
     * Ends an approved download before its end date.
     *
     * @throws ValidationException
     */
    public function revoke(MedicalDownloadRequest $request, User $actor): void
    {
        DB::transaction(function () use ($request, $actor): void {
            $locked = $this->lock($request);
            if (! $locked->isActive()) {
                throw ValidationException::withMessages(['download' => 'This download has already ended.']);
            }

            $this->end($locked, $actor);
        });
    }

    /**
     * Counts and audits one download, then returns the stored file's path.
     *
     * @throws ValidationException
     */
    public function download(MedicalDownloadRequest $request, User $actor): string
    {
        $path = DB::transaction(function () use ($request, $actor): ?string {
            $locked = $this->lock($request);
            if (! $locked->isActive() || (int) $locked->requested_by !== (int) $actor->getKey()) {
                throw ValidationException::withMessages(['download' => 'This download is no longer available. Request it again if you still need a copy.']);
            }

            $locked->download_count++;
            $locked->last_downloaded_at = now();
            $locked->save();

            $this->audit->record(AuditAction::MedicalDocumentDownloaded, $locked->document, newValues: [
                ...$this->summary($locked),
                'download_request' => $locked->id,
                'download_count' => $locked->download_count,
            ], actor: $actor);

            return $this->documents->absolutePath($locked->document);
        });

        abort_if($path === null, 404);

        return $path;
    }

    private function end(MedicalDownloadRequest $download, User $actor): void
    {
        $download->status = MedicalDownloadStatus::Revoked;
        $download->revoker()->associate($actor);
        $download->revoked_at = now();
        $download->save();

        $this->audit->record(AuditAction::MedicalDownloadRevoked, $download, newValues: [...$this->summary($download), 'instructor' => $download->requester->name], actor: $actor);
    }

    private function lock(MedicalDownloadRequest $request): MedicalDownloadRequest
    {
        return MedicalDownloadRequest::query()
            ->with(['document', 'candidate:id,candidate_number', 'requester:id,name'])
            ->lockForUpdate()
            ->findOrFail($request->getKey());
    }

    /**
     * @throws ValidationException
     */
    private function lockPending(MedicalDownloadRequest $request, User $approver, string $verb): MedicalDownloadRequest
    {
        $locked = $this->lock($request);
        if (! $locked->isPending()) {
            throw ValidationException::withMessages(['download' => 'This request was already decided.']);
        }
        if ((int) $locked->requested_by === (int) $approver->getKey()) {
            throw ValidationException::withMessages(['download' => "You cannot {$verb} your own request."]);
        }
        if (! $approver->hasPermission(Permission::ManageMedical) || ! $approver->hasPermission(Permission::ViewMedical)) {
            throw ValidationException::withMessages(['download' => 'Only medical staff decide these requests.']);
        }

        return $locked;
    }

    private function decide(MedicalDownloadRequest $request, ?string $note, User $actor): void
    {
        $request->decision_note = $this->clean($note);
        $request->decider()->associate($actor);
        $request->decided_at = now();
        $request->save();
    }

    /**
     * What the audit log may hold: no document title, file name or notes.
     *
     * @return array<string, mixed>
     */
    private function summary(MedicalDownloadRequest $request): array
    {
        $request->loadMissing(['document:id,category', 'candidate:id,candidate_number']);

        return [
            'candidate' => $request->candidate?->candidate_number,
            'document' => $request->candidate_medical_document_id,
            'category' => $request->document?->category->value,
        ];
    }

    private function clean(?string $text): ?string
    {
        $text = $text === null ? null : trim($text);

        return $text === '' ? null : mb_substr($text, 0, self::REASON_MAX);
    }
}
