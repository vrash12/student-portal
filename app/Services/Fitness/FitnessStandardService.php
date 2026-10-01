<?php

namespace App\Services\Fitness;

use App\Enums\AuditAction;
use App\Models\FitnessEvent;
use App\Services\AuditLogger;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

/**
 * Configures the fitness events and their standards. Changing a standard
 * affects tests created afterwards only: every test keeps the standards it
 * was created with.
 */
final class FitnessStandardService
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @param  array{name: string, description: ?string, unit: string, higher_is_better: bool, passing_value: float, maximum_value: float, sort_order: int}  $data
     */
    public function create(array $data): FitnessEvent
    {
        return DB::transaction(function () use ($data): FitnessEvent {
            $event = FitnessEvent::query()->create($data);

            $this->audit->record(AuditAction::FitnessEventCreated, $event, newValues: $this->snapshot($event));

            return $event;
        });
    }

    /**
     * @param  array{name: string, description: ?string, unit: string, higher_is_better: bool, passing_value: float, maximum_value: float, sort_order: int, is_active: bool}  $data
     */
    public function update(FitnessEvent $event, array $data): FitnessEvent
    {
        return DB::transaction(function () use ($event, $data): FitnessEvent {
            $before = $this->snapshot($event);

            $event->fill(Arr::except($data, 'is_active'));
            $event->is_active = $data['is_active'];
            $event->save();

            $this->audit->recordChanges(AuditAction::FitnessEventUpdated, $event, $before, $this->snapshot($event->refresh()));

            return $event;
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(FitnessEvent $event): array
    {
        return [
            'name' => $event->name,
            'description' => $event->description,
            'unit' => $event->unit->value,
            'higher_is_better' => $event->higher_is_better,
            'passing_value' => (string) $event->passing_value,
            'maximum_value' => (string) $event->maximum_value,
            'sort_order' => $event->sort_order,
            'is_active' => $event->is_active,
        ];
    }
}
