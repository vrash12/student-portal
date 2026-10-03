<?php

namespace App\Services;

use App\Enums\AuditAction;
use App\Models\TrainingPhase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Training phases of the course (owner request, 2026-10-03). Every change is
 * audited. A phase that subjects of a class belong to cannot be deleted:
 * their phase averages are part of the academic record.
 */
final class TrainingPhaseService
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @param  array{number: int, name: string}  $data
     */
    public function create(array $data): TrainingPhase
    {
        return DB::transaction(function () use ($data): TrainingPhase {
            $phase = TrainingPhase::query()->create($data);
            $this->audit->record(AuditAction::TrainingPhaseCreated, $phase, newValues: self::snapshot($phase));

            return $phase;
        });
    }

    /**
     * @param  array{number: int, name: string}  $data
     */
    public function update(TrainingPhase $phase, array $data): TrainingPhase
    {
        return DB::transaction(function () use ($phase, $data): TrainingPhase {
            $locked = TrainingPhase::query()->lockForUpdate()->findOrFail($phase->id);
            $before = self::snapshot($locked);
            $locked->fill($data)->save();
            $this->audit->recordChanges(AuditAction::TrainingPhaseUpdated, $locked, $before, self::snapshot($locked));

            return $locked;
        });
    }

    /**
     * @throws ValidationException when subjects of a class belong to the phase
     */
    public function delete(TrainingPhase $phase): void
    {
        DB::transaction(function () use ($phase): void {
            $locked = TrainingPhase::query()->lockForUpdate()->findOrFail($phase->id);
            $inUse = $locked->classSubjects()->count();
            if ($inUse > 0) {
                throw ValidationException::withMessages([
                    'phase' => "{$locked->name} is used by {$inUse} ".($inUse === 1 ? 'subject' : 'subjects').' of a class and cannot be deleted. Move them to another phase first.',
                ]);
            }

            $this->audit->record(AuditAction::TrainingPhaseDeleted, $locked, oldValues: self::snapshot($locked));
            $locked->delete();
        });
    }

    /**
     * @return array{number: int, name: string}
     */
    private static function snapshot(TrainingPhase $phase): array
    {
        return ['number' => $phase->number, 'name' => $phase->name];
    }
}
