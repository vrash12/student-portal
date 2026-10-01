<?php

namespace App\Services\Performance;

use App\Enums\AuditAction;
use App\Enums\PerformanceSource;
use App\Models\PerformanceArea;
use App\Models\Subject;
use App\Services\AuditLogger;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Configures the performance areas and which subjects count toward each.
 * Areas are never deleted (deactivate instead), and every change is audited
 * with the previous and new values, including the subjects of every area a
 * subject moves out of.
 *
 * Rules enforced here, server-side:
 *
 * - only subject areas hold subjects; changing an area to another source
 *   unmaps its subjects;
 * - the conduct rating values are kept for conduct areas only;
 * - at most one active area each for fitness, conduct and attendance. The
 *   areas are locked while this is checked, so two saves cannot both
 *   activate one; the unique `active_source_marker` column is the final
 *   guarantee.
 */
final class PerformanceAreaService
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @param  array{name: string, description: ?string, source: string, weight: string, passing_grade: string, must_pass: bool, base_rating: ?string, merit_value: ?string, demerit_value: ?string, sort_order: int, is_active: bool}  $data
     * @param  list<int>  $subjectIds  subjects of a subject area (moved here from any other area)
     */
    public function create(array $data, array $subjectIds = []): PerformanceArea
    {
        return DB::transaction(function () use ($data, $subjectIds): PerformanceArea {
            $source = PerformanceSource::from($data['source']);
            $this->ensureSingleActiveArea(null, $source, $data['is_active'], 'source');

            $area = new PerformanceArea;
            $this->fill($area, $source, $data);
            $area->save();

            $moved = $this->mapSubjects($area, $source, $subjectIds);

            $this->audit->record(AuditAction::PerformanceAreaCreated, $area, newValues: $this->snapshot($area->refresh()));
            $this->auditMovedSubjects($moved, $area);

            return $area;
        });
    }

    /**
     * @param  array{name: string, description: ?string, source: string, weight: string, passing_grade: string, must_pass: bool, base_rating: ?string, merit_value: ?string, demerit_value: ?string, sort_order: int, is_active: bool}  $data
     * @param  list<int>  $subjectIds  the complete list of the area's subjects after saving
     */
    public function update(PerformanceArea $area, array $data, array $subjectIds = []): PerformanceArea
    {
        return DB::transaction(function () use ($area, $data, $subjectIds): PerformanceArea {
            $source = PerformanceSource::from($data['source']);
            // Report the conflict on the field the user changed.
            $this->ensureSingleActiveArea($area->id, $source, $data['is_active'], $area->source === $source ? 'is_active' : 'source');

            $locked = PerformanceArea::query()->whereKey($area->id)->lockForUpdate()->firstOrFail();
            $before = $this->snapshot($locked);

            $this->fill($locked, $source, $data);
            $locked->save();

            $moved = $this->mapSubjects($locked, $source, $subjectIds);

            $this->audit->recordChanges(AuditAction::PerformanceAreaUpdated, $locked, $before, $this->snapshot($locked->refresh()));
            $this->auditMovedSubjects($moved, $locked);

            return $locked;
        });
    }

    /**
     * Another active area of a fitness, conduct or attendance source, if
     * any (for the form request's validation and the forms' warnings).
     */
    public function activeAreaUsing(PerformanceSource $source, ?int $exceptAreaId = null): ?PerformanceArea
    {
        if (! $source->allowsSingleActiveArea()) {
            return null;
        }

        return PerformanceArea::query()
            ->active()
            ->where('source', $source->value)
            ->when($exceptAreaId !== null, fn ($query) => $query->whereKeyNot($exceptAreaId))
            ->first();
    }

    private function ensureSingleActiveArea(?int $areaId, PerformanceSource $source, bool $active, string $field): void
    {
        if (! $active || ! $source->allowsSingleActiveArea()) {
            return;
        }

        // Every area is locked: the table is small, and locking all of it
        // also blocks a concurrent insert of a second active area.
        $other = PerformanceArea::query()
            ->lockForUpdate()
            ->get()
            ->first(fn (PerformanceArea $area): bool => $area->id !== $areaId && $area->is_active && $area->source === $source);

        if ($other !== null) {
            throw ValidationException::withMessages([
                $field => "{$other->name} is already the active {$source->label()} area. Only one can be active: deactivate it first, or save this area as inactive.",
            ]);
        }
    }

    /**
     * @param  array{name: string, description: ?string, source: string, weight: string, passing_grade: string, must_pass: bool, base_rating: ?string, merit_value: ?string, demerit_value: ?string, sort_order: int, is_active: bool}  $data
     */
    private function fill(PerformanceArea $area, PerformanceSource $source, array $data): void
    {
        $conduct = $source === PerformanceSource::Conduct;

        $area->fill([
            'name' => $data['name'],
            'description' => $data['description'],
            'source' => $source,
            'weight' => $data['weight'],
            'passing_grade' => $data['passing_grade'],
            'must_pass' => $data['must_pass'],
            'base_rating' => $conduct ? $data['base_rating'] : null,
            'merit_value' => $conduct ? $data['merit_value'] : null,
            'demerit_value' => $conduct ? $data['demerit_value'] : null,
            'sort_order' => $data['sort_order'],
        ]);
        $area->is_active = $data['is_active'];
    }

    /**
     * Makes the given subjects exactly the subjects of the area (none for
     * an area that is not a subject area). Subjects mapped to another area
     * move here.
     *
     * @param  list<int>  $subjectIds
     * @return array<int, list<string>> subjects of each other area before the move, keyed by area id
     */
    private function mapSubjects(PerformanceArea $area, PerformanceSource $source, array $subjectIds): array
    {
        $subjectIds = array_values(array_unique(array_map('intval', $subjectIds)));
        if (! $source->holdsSubjects() && $subjectIds !== []) {
            throw ValidationException::withMessages(['subject_ids' => 'Only areas based on subject grades can hold subjects.']);
        }

        /** @var Collection<int, Subject> $affected */
        $affected = Subject::query()
            ->where('performance_area_id', $area->id)
            ->orWhereIn('id', $subjectIds)
            ->lockForUpdate()
            ->get(['id', 'performance_area_id']);

        if (count(array_diff($subjectIds, $affected->modelKeys())) > 0) {
            throw ValidationException::withMessages(['subject_ids' => 'One of the selected subjects no longer exists. Reload the page and try again.']);
        }

        $selected = array_flip($subjectIds);
        $added = $affected->filter(fn (Subject $subject): bool => isset($selected[$subject->id]) && (int) $subject->performance_area_id !== $area->id);
        $removed = $affected->filter(fn (Subject $subject): bool => ! isset($selected[$subject->id]) && (int) $subject->performance_area_id === $area->id);

        $fromAreaIds = $added->pluck('performance_area_id')->filter()->map(fn (mixed $id): int => (int) $id)->unique()->values()->all();
        $before = [];
        foreach ($fromAreaIds as $fromAreaId) {
            $before[$fromAreaId] = $this->subjectLabels($fromAreaId);
        }

        if ($added->isNotEmpty()) {
            Subject::query()->whereKey($added->modelKeys())->update(['performance_area_id' => $area->id]);
        }
        if ($removed->isNotEmpty()) {
            Subject::query()->whereKey($removed->modelKeys())->update(['performance_area_id' => null]);
        }

        return $before;
    }

    /**
     * Each area a subject moved out of gets its own audit entry.
     *
     * @param  array<int, list<string>>  $moved
     */
    private function auditMovedSubjects(array $moved, PerformanceArea $to): void
    {
        if ($moved === []) {
            return;
        }

        foreach (PerformanceArea::query()->whereKey(array_keys($moved))->get() as $from) {
            $this->audit->record(
                AuditAction::PerformanceAreaUpdated,
                $from,
                oldValues: ['subjects' => $moved[$from->id]],
                newValues: ['subjects' => $this->subjectLabels($from->id)],
                reason: "Subjects moved to {$to->name}.",
            );
        }
    }

    /**
     * @return list<string> "CODE Name" of the area's subjects, by code
     */
    private function subjectLabels(int $areaId): array
    {
        return Subject::query()
            ->where('performance_area_id', $areaId)
            ->orderBy('code')
            ->get(['code', 'name'])
            ->map(fn (Subject $subject): string => "{$subject->code} {$subject->name}")
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(PerformanceArea $area): array
    {
        return [
            'name' => $area->name,
            'description' => $area->description,
            'source' => $area->source->value,
            'weight' => (string) $area->weight,
            'passing_grade' => (string) $area->passing_grade,
            'must_pass' => $area->must_pass,
            'base_rating' => $area->base_rating === null ? null : (string) $area->base_rating,
            'merit_value' => $area->merit_value === null ? null : (string) $area->merit_value,
            'demerit_value' => $area->demerit_value === null ? null : (string) $area->demerit_value,
            'sort_order' => $area->sort_order,
            'is_active' => $area->is_active,
            'subjects' => $this->subjectLabels($area->id),
        ];
    }
}
