<?php

namespace App\Services;

use App\Enums\AuditAction;
use App\Models\Campus;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Campuses (owner decision 2026-10-03). A campus in use (classes,
 * candidates, staff or audit history refer to it) is deactivated, never
 * deleted; a campus that was never used can be removed. Deactivation stops
 * new classes and people from being placed there; existing records stay.
 */
final class CampusService
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @param  array{name: string, code: string, address: ?string}  $data
     */
    public function create(array $data): Campus
    {
        return DB::transaction(function () use ($data): Campus {
            $campus = Campus::query()->create($data);

            $this->audit->record(AuditAction::CampusCreated, $campus, newValues: $this->snapshot($campus));

            return $campus;
        });
    }

    /**
     * @param  array{name: string, code: string, address: ?string, is_active: bool}  $data
     */
    public function update(Campus $campus, array $data): Campus
    {
        return DB::transaction(function () use ($campus, $data): Campus {
            $locked = Campus::query()->lockForUpdate()->findOrFail($campus->getKey());
            $before = $this->snapshot($locked);

            $locked->fill([
                'name' => $data['name'],
                'code' => $data['code'],
                'address' => $data['address'],
            ]);
            $locked->is_active = $data['is_active'];
            $locked->save();

            $this->audit->recordChanges(AuditAction::CampusUpdated, $locked, $before, $this->snapshot($locked));

            return $locked;
        });
    }

    /**
     * Removes a campus that nothing refers to. The row lock makes a class,
     * candidate or account created at the same moment wait, so its foreign
     * key either finds the campus or fails cleanly.
     */
    public function delete(Campus $campus): void
    {
        DB::transaction(function () use ($campus): void {
            $locked = Campus::query()->lockForUpdate()->findOrFail($campus->getKey());

            if ($locked->isInUse()) {
                throw ValidationException::withMessages([
                    'campus' => "{$locked->name} has classes, candidates, staff or history, so it cannot be removed. Deactivate it instead.",
                ]);
            }

            $this->audit->record(AuditAction::CampusDeleted, $locked, oldValues: $this->snapshot($locked));
            $locked->delete();
        });
    }

    /**
     * @return array{name: string, code: string, address: ?string, is_active: bool}
     */
    private function snapshot(Campus $campus): array
    {
        return [
            'name' => $campus->name,
            'code' => $campus->code,
            'address' => $campus->address,
            'is_active' => $campus->is_active,
        ];
    }
}
