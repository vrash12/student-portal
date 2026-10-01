<?php

namespace App\Services\Conduct;

use App\Enums\AuditAction;
use App\Enums\CandidateStatus;
use App\Models\Candidate;
use App\Models\ConductEntry;
use App\Models\ConductType;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Records merits and demerits and manages their types. Entries are never
 * edited or deleted: a mistaken entry is voided with a reason and the
 * correct one recorded. Every change is audited. Who may act on which
 * candidate is decided by ConductPolicy before these methods are called.
 */
final class ConductService
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * Records a merit or demerit. The kind always comes from the type; the
     * points default to the type's points but may be adjusted (1–100).
     *
     * @param  array{conduct_type_id: int, points: int, occurred_on: string, reason: string}  $data
     */
    public function record(Candidate $candidate, array $data, User $actor): ConductEntry
    {
        return DB::transaction(function () use ($candidate, $data, $actor): ConductEntry {
            // The current status decides, not the copy loaded with the request.
            $current = Candidate::query()->whereKey($candidate->id)->lockForUpdate()->firstOrFail();
            if ($current->status === CandidateStatus::Withdrawn) {
                throw ValidationException::withMessages(['candidate' => 'Merits and demerits cannot be recorded for a withdrawn candidate.']);
            }

            $type = ConductType::query()->active()->find($data['conduct_type_id'])
                ?? throw ValidationException::withMessages(['conduct_type_id' => 'Choose an active merit or demerit type.']);

            $entry = new ConductEntry;
            $entry->forceFill([
                'candidate_id' => $current->id,
                'conduct_type_id' => $type->id,
                'kind' => $type->kind,
                'points' => $data['points'],
                'occurred_on' => $data['occurred_on'],
                'reason' => $data['reason'],
                'recorded_by' => $actor->id,
            ])->save();

            $this->audit->record(AuditAction::ConductEntryRecorded, $entry, newValues: [
                'candidate' => $current->candidate_number,
                'type' => $type->name,
                ...$this->snapshot($entry),
            ], actor: $actor);

            return $entry;
        });
    }

    /**
     * Voids an entry with a reason. The row is locked so two simultaneous
     * voids cannot both succeed.
     */
    public function void(ConductEntry $entry, string $reason, User $actor): ConductEntry
    {
        return DB::transaction(function () use ($entry, $reason, $actor): ConductEntry {
            $locked = ConductEntry::query()->whereKey($entry->id)->lockForUpdate()->firstOrFail();
            if ($locked->isVoided()) {
                throw ValidationException::withMessages(['reason' => 'This entry has already been voided.']);
            }

            $locked->forceFill(['voided_at' => now(), 'voided_by' => $actor->id, 'void_reason' => $reason])->save();

            $this->audit->record(AuditAction::ConductEntryVoided, $locked, oldValues: $this->snapshot($locked), reason: $reason, actor: $actor);

            return $locked;
        });
    }

    /**
     * @param  array{name: string, kind: string, default_points: int, description: ?string, sort_order: int}  $data
     */
    public function createType(array $data): ConductType
    {
        return DB::transaction(function () use ($data): ConductType {
            $type = ConductType::query()->create($data);
            $this->audit->record(AuditAction::ConductTypeCreated, $type, newValues: $this->typeSnapshot($type));

            return $type;
        });
    }

    /**
     * Recorded entries keep their kind and points: changing a type only
     * affects entries recorded afterwards.
     *
     * @param  array{name: string, kind: string, default_points: int, description: ?string, sort_order: int, is_active: bool}  $data
     */
    public function updateType(ConductType $type, array $data): ConductType
    {
        return DB::transaction(function () use ($type, $data): ConductType {
            $locked = ConductType::query()->whereKey($type->id)->lockForUpdate()->firstOrFail();
            $before = $this->typeSnapshot($locked);
            $locked->fill(Arr::except($data, 'is_active'));
            $locked->is_active = $data['is_active'];
            $locked->save();

            $this->audit->recordChanges(AuditAction::ConductTypeUpdated, $locked, $before, $this->typeSnapshot($locked->refresh()));

            return $locked;
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(ConductEntry $entry): array
    {
        return [
            'kind' => $entry->kind->value,
            'points' => $entry->points,
            'occurred_on' => $entry->occurred_on->toDateString(),
            'reason' => $entry->reason,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function typeSnapshot(ConductType $type): array
    {
        return [
            'name' => $type->name,
            'kind' => $type->kind->value,
            'default_points' => $type->default_points,
            'description' => $type->description,
            'sort_order' => $type->sort_order,
            'is_active' => $type->is_active,
        ];
    }
}
