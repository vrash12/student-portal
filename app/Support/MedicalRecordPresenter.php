<?php

namespace App\Support;

use App\Enums\Permission;
use App\Models\Candidate;
use App\Models\CandidateMedicalValue;
use App\Models\MedicalField;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

/**
 * Who sees what of a candidate's medical record:
 *
 * - staff with medical.view: every active field ("full");
 * - instructors who teach the candidate's class: only the fields shared with
 *   instructors ("instructor");
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
            'type' => $field->field_type->toArray(),
            'options' => $field->choiceOptions(),
            'helpText' => $field->help_text,
            'sortOrder' => $field->sort_order,
            'visibleToInstructors' => $field->visible_to_instructors,
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
        if ($viewer->hasPermission(Permission::ViewMedical)) {
            $scope = 'full';
            $fields = MedicalField::query()->active()->ordered()->get();
        } elseif ($candidate->class_batch_id !== null && $viewer->canTeach() && $viewer->teachesClass($candidate->class_batch_id)) {
            $scope = 'instructor';
            $fields = MedicalField::query()->active()->where('visible_to_instructors', true)->ordered()->get();
        } else {
            return null;
        }

        if ($scope === 'instructor' && $fields->isEmpty()) {
            return null;
        }

        $values = self::values($candidate, $fields);
        $latest = $values->sortByDesc('updated_at')->first();

        return [
            'scope' => $scope,
            'entries' => self::entries($fields, $values),
            'canEdit' => $viewer->hasPermission(Permission::ManageMedical),
            'updatedAt' => $latest?->updated_at?->toIso8601String(),
            'updatedBy' => $scope === 'full' ? $latest?->updater?->name : null,
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
                'type' => $field->field_type->value,
                'helpText' => $field->help_text,
                'visibleToInstructors' => $field->visible_to_instructors,
                'visibleToCandidate' => $field->visible_to_candidate,
                'value' => $values->get($field->id)?->value,
            ])
            ->values()
            ->all();
    }
}
