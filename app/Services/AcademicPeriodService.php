<?php

namespace App\Services;

use App\Enums\AuditAction;
use App\Models\AcademicPeriod;
use Illuminate\Support\Facades\DB;

final class AcademicPeriodService
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @param  array{name: string, starts_on: string, ends_on: string}  $data
     */
    public function create(array $data): AcademicPeriod
    {
        return DB::transaction(function () use ($data): AcademicPeriod {
            $period = AcademicPeriod::query()->create($data);

            $this->audit->record(AuditAction::AcademicPeriodCreated, $period, newValues: $this->snapshot($period));

            return $period;
        });
    }

    /**
     * @param  array{name: string, starts_on: string, ends_on: string}  $data
     */
    public function update(AcademicPeriod $period, array $data): AcademicPeriod
    {
        return DB::transaction(function () use ($period, $data): AcademicPeriod {
            $before = $this->snapshot($period);

            $period->fill($data)->save();

            $this->audit->recordChanges(AuditAction::AcademicPeriodUpdated, $period, $before, $this->snapshot($period));

            return $period;
        });
    }

    /**
     * Makes the period the only active one. Rows are locked so concurrent
     * activations cannot interleave; the unique index on active_marker is
     * the final guarantee.
     */
    public function activate(AcademicPeriod $period): void
    {
        DB::transaction(function () use ($period): void {
            $current = AcademicPeriod::query()->active()->lockForUpdate()->first();
            $target = AcademicPeriod::query()->lockForUpdate()->findOrFail($period->getKey());

            if ($current?->is($target)) {
                return;
            }

            // Deactivate first: two active rows would violate the unique index.
            $current?->forceFill(['is_active' => false])->save();
            $target->forceFill(['is_active' => true])->save();

            $this->audit->record(
                AuditAction::AcademicPeriodActivated,
                $target,
                oldValues: ['active_period' => $current?->name],
                newValues: ['active_period' => $target->name],
            );
        });

        $period->refresh();
    }

    /**
     * @return array{name: string, starts_on: string, ends_on: string}
     */
    private function snapshot(AcademicPeriod $period): array
    {
        return [
            'name' => $period->name,
            'starts_on' => $period->starts_on->toDateString(),
            'ends_on' => $period->ends_on->toDateString(),
        ];
    }
}
