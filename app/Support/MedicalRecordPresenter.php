<?php

namespace App\Support;

use App\Enums\MedicalAccessStatus;
use App\Enums\MedicalDocumentStatus;
use App\Enums\Permission;
use App\Models\Candidate;
use App\Models\CandidateMedicalDocument;
use App\Models\CandidateMedicalValue;
use App\Models\MedicalAccessRequest;
use App\Models\MedicalDownloadRequest;
use App\Models\MedicalField;
use App\Models\User;
use App\Services\Medical\MedicalAccessService;
use Illuminate\Database\Eloquent\Collection;

/**
 * Who sees what of a candidate's medical record:
 *
 * - staff with medical.view: every active field ("full");
 * - instructors who teach the candidate's class: nothing ("instructor": only
 *   the state of their access requests), or every active field, read-only,
 *   while the medical staff have approved their request ("granted");
 * - the candidate in the portal: only the fields shared with the candidate.
 *
 * Uploaded medical documents follow the same scopes: medical staff see every
 * document (view, download, review); instructors with approved access see the
 * documents that were not returned, in the protected viewer only (no file
 * link that opens on its own, no download); candidates see their own.
 *
 * Fields and documents that are not shown are never sent to the browser.
 */
final class MedicalRecordPresenter
{
    /**
     * @return array<string, mixed>
     */
    public static function field(MedicalField $field): array
    {
        return [
            'id' => $field->id,
            'name' => $field->name,
            'section' => $field->section,
            'type' => $field->field_type->toArray(),
            'options' => $field->choiceOptions(),
            'unit' => $field->unit,
            'helpText' => $field->help_text,
            'sortOrder' => $field->sort_order,
            'visibleToCandidate' => $field->visible_to_candidate,
            'isActive' => $field->is_active,
        ];
    }

    /**
     * The medical panel of the staff candidate profile; null when the viewer
     * may see no field of it.
     *
     * @return array<string, mixed>|null
     */
    public static function forStaff(Candidate $candidate, User $viewer): ?array
    {
        $grant = null;
        if ($viewer->hasPermission(Permission::ViewMedical)) {
            $scope = 'full';
            $fields = MedicalField::query()->active()->ordered()->get();
        } elseif ($candidate->class_batch_id !== null && $viewer->canTeach() && $viewer->teachesClass($candidate->class_batch_id)) {
            // Owner decision (2026-10-02): no medical information without approved access.
            $grant = MedicalAccessService::activeGrant($viewer, $candidate);
            $scope = $grant === null ? 'instructor' : 'granted';
            $fields = $grant === null ? new Collection : MedicalField::query()->active()->ordered()->get();
        } else {
            return null;
        }

        $values = self::values($candidate, $fields);
        $latest = $values->sortByDesc('updated_at')->first();

        return [
            'scope' => $scope,
            'entries' => self::entries($fields, $values),
            'canEdit' => $scope === 'full' && $viewer->hasPermission(Permission::ManageMedical),
            'updatedAt' => $latest?->updated_at?->toIso8601String(),
            'updatedBy' => $scope === 'full' ? $latest?->updater?->name : null,
            // Instructors only: their access to the full record and their requests for it.
            'access' => $scope === 'full' ? null : self::access($candidate, $viewer, $grant),
            'documents' => match ($scope) {
                'full' => self::documents($candidate, 'staff', $viewer),
                'granted' => self::documents($candidate, 'granted', $viewer),
                default => [],
            },
            // Documents waiting for review (medical staff only).
            'waitingDocuments' => $scope === 'full' ? CandidateMedicalDocument::query()->where('candidate_id', $candidate->id)->waiting()->count() : 0,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function access(Candidate $candidate, User $viewer, ?MedicalAccessRequest $grant): array
    {
        $latest = MedicalAccessRequest::query()
            ->where('candidate_id', $candidate->id)
            ->where('requested_by', $viewer->id)
            ->latest('id')
            ->first();
        $pending = $latest !== null && $latest->isPending() ? $latest : null;
        // The last answer the instructor should know about: a rejection, a withdrawal or ended access.
        $lastDecision = $grant === null && $pending === null && $latest !== null && in_array($latest->status, [MedicalAccessStatus::Rejected, MedicalAccessStatus::Revoked, MedicalAccessStatus::Approved], true)
            ? $latest
            : null;

        return [
            'grant' => $grant === null ? null : [
                'requestId' => $grant->id,
                'expiresAt' => $grant->expires_at?->toIso8601String(),
                'grantedBy' => $grant->decider?->name,
            ],
            'pending' => $pending === null ? null : [
                'requestId' => $pending->id,
                'requestedAt' => $pending->created_at?->toIso8601String(),
            ],
            'lastDecision' => $lastDecision === null ? null : [
                'status' => $lastDecision->displayStatus(),
                'note' => $lastDecision->decision_note,
                'at' => ($lastDecision->revoked_at ?? ($lastDecision->status === MedicalAccessStatus::Approved ? $lastDecision->expires_at : $lastDecision->decided_at))?->toIso8601String(),
            ],
            'canRequest' => $grant === null && $pending === null && $viewer->can('requestMedicalAccess', $candidate),
        ];
    }

    /**
     * The candidate's own record in the portal: the fields shared with candidates.
     *
     * @return list<array<string, mixed>>
     */
    public static function forCandidate(Candidate $candidate): array
    {
        $fields = MedicalField::query()->active()->where('visible_to_candidate', true)->ordered()->get();

        return self::entries($fields, self::values($candidate, $fields));
    }

    /**
     * A candidate's uploaded medical documents, newest first, for one audience:
     * "staff" (every document, download and review), "granted" (instructors
     * with approved access: not returned, protected viewer only) or
     * "candidate" (their own, with withdraw while waiting).
     *
     * @return list<array<string, mixed>>
     */
    public static function documents(Candidate $candidate, string $audience, User $viewer): array
    {
        return CandidateMedicalDocument::query()
            ->where('candidate_id', $candidate->id)
            ->when($audience === 'granted', fn ($query) => $query->notReturned())
            ->with(['reviewer:id,name', 'candidate:id,class_batch_id'])
            // Instructors see the state of their own download requests (newest first).
            ->when($audience === 'granted', fn ($query) => $query->with(['downloadRequests' => fn ($requests) => $requests->where('requested_by', $viewer->id)->with('decider:id,name')->latest('id')]))
            ->latest('id')
            ->get()
            ->map(fn (CandidateMedicalDocument $document): array => self::document($document, $audience, $viewer))
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    public static function document(CandidateMedicalDocument $document, string $audience, User $viewer): array
    {
        $protected = $audience === 'granted';
        $fileUrl = match ($audience) {
            'staff' => route('medical.documents.file', $document, false),
            'candidate' => route('portal.medical.documents.file', $document, false),
            default => null,
        };

        return [
            'id' => $document->id,
            'title' => $document->title,
            'category' => ['value' => $document->category->value, 'label' => $document->category->label()],
            'documentDate' => $document->document_date?->toDateString(),
            'notes' => $document->notes,
            'fileType' => $document->isPdf() ? 'pdf' : 'image',
            'sizeBytes' => $document->size_bytes,
            'status' => $document->status->toArray(),
            'uploadedAt' => $document->created_at?->toIso8601String(),
            'reviewedAt' => $protected ? null : $document->reviewed_at?->toIso8601String(),
            'reviewedBy' => $protected ? null : $document->reviewer?->name,
            'reviewNote' => $protected ? null : $document->review_note,
            // Opens in a new tab (medical staff and the candidate); null for instructors.
            'fileUrl' => $fileUrl,
            'downloadUrl' => $fileUrl === null ? null : $fileUrl.'?download=1',
            // Instructors: the protected viewer fetches the file from here and reports Print Screen presses there.
            'protectedUrl' => $protected ? route('medical.documents.protected', $document, false) : null,
            'printScreenUrl' => $protected ? route('medical.documents.print-screen', $document, false) : null,
            // Instructors: a copy only through an approved download request.
            'download' => $protected ? self::downloadState($document, $viewer) : null,
            'can' => [
                'review' => $audience === 'staff' && $document->status === MedicalDocumentStatus::Submitted && $viewer->can('review', $document),
                'withdraw' => $audience === 'candidate' && $viewer->can('withdraw', $document),
            ],
        ];
    }

    /**
     * The viewer's latest download request for a document, and what they may do next.
     *
     * @return array<string, mixed>
     */
    private static function downloadState(CandidateMedicalDocument $document, User $viewer): array
    {
        /** @var MedicalDownloadRequest|null $latest */
        $latest = $document->relationLoaded('downloadRequests') ? $document->downloadRequests->first() : null;
        $active = $latest !== null && $latest->isActive();

        return [
            'request' => $latest === null ? null : [
                'id' => $latest->id,
                'status' => $latest->displayStatus(),
                'expiresAt' => $latest->expires_at?->toIso8601String(),
                'decidedBy' => $latest->decider?->name,
                'decisionNote' => $latest->decision_note,
                'downloadCount' => $latest->download_count,
            ],
            'fileUrl' => $active && $viewer->can('download', $latest) ? route('medical.downloads.file', $latest, false) : null,
            'canRequest' => ($latest === null || (! $latest->isPending() && ! $active)) && $viewer->can('requestDownload', $document),
            'canCancel' => $latest !== null && $latest->isPending(),
        ];
    }

    /**
     * @param  Collection<int, MedicalField>  $fields
     * @return Collection<int, CandidateMedicalValue>
     */
    public static function values(Candidate $candidate, Collection $fields): Collection
    {
        return CandidateMedicalValue::query()
            ->where('candidate_id', $candidate->id)
            ->whereIn('medical_field_id', $fields->modelKeys())
            ->with('updater:id,name')
            ->get()
            ->keyBy('medical_field_id');
    }

    /**
     * @param  Collection<int, MedicalField>  $fields
     * @param  Collection<int, CandidateMedicalValue>  $values
     * @return list<array<string, mixed>>
     */
    private static function entries(Collection $fields, Collection $values): array
    {
        return $fields
            ->map(fn (MedicalField $field): array => [
                'fieldId' => $field->id,
                'name' => $field->name,
                'section' => $field->section,
                'type' => $field->field_type->value,
                'unit' => $field->unit,
                'helpText' => $field->help_text,
                'visibleToCandidate' => $field->visible_to_candidate,
                'value' => $values->get($field->id)?->value,
            ])
            ->values()
            ->all();
    }
}
