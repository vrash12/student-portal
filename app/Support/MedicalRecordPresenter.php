<?php

namespace App\Support;

use App\Enums\MedicalAccessStatus;
use App\Enums\Permission;
use App\Models\Candidate;
use App\Models\CandidateMedicalValue;
use App\Models\MedicalAccessRequest;
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
 * Fields that are not shown are never sent to the browser.
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
