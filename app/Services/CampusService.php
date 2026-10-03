<?php

namespace App\Services;

use App\Enums\AuditAction;
use App\Models\Campus;
use Illuminate\Support\Facades\DB;

/**
 * The four fixed campuses (owner decisions 2026-10-03 and 2026-10-04). No
 * campus is added or removed; an administrator may change a campus's
 * address and switch it off. Switching a campus off stops new classes and
 * people from being placed there; existing records stay.
 */
final class CampusService
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @param  array{address: ?string, is_active: bool}  $data
     */
    public function update(Campus $campus, array $data): Campus
    {
        return DB::transaction(function () use ($campus, $data): Campus {
            $locked = Campus::query()->lockForUpdate()->findOrFail($campus->getKey());
            $before = $this->snapshot($locked);

            $locked->address = $data['address'];
            $locked->is_active = $data['is_active'];
            $locked->save();

            $this->audit->recordChanges(AuditAction::CampusUpdated, $locked, $before, $this->snapshot($locked));

            return $locked;
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
