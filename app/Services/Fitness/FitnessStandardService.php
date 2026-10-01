<?php

namespace App\Services\Fitness;

use App\Enums\AuditAction;
use App\Enums\FitnessScoringMethod;
use App\Models\FitnessEvent;
use App\Services\AuditLogger;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

/**
 * Configures the fitness events and their standards (a points table, or
 * passing and maximum values) and passing points. Changing a standard
 * affects tests created afterwards only: every test keeps the standards it
 * was created with.
 */
final class FitnessStandardService
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @param  array{name: string, description: ?string, unit: string, higher_is_better: bool, scoring_method?: string, passing_points?: float, passing_value: ?float, maximum_value: ?float, points_table?: list<array{value: float, points: float}>|null, sort_order: int}  $data
     */
    public function create(array $data): FitnessEvent
    {
        return DB::transaction(function () use ($data): FitnessEvent {
            $event = FitnessEvent::query()->create([
                'scoring_method' => FitnessScoringMethod::Scaled->value,
                'passing_points' => FitnessStandard::DEFAULT_PASSING_POINTS,
                'points_table' => null,
                ...$data,
            ]);

            $this->audit->record(AuditAction::FitnessEventCreated, $event, newValues: $this->snapshot($event));

            return $event;
        });
    }

    /**
     * @param  array{name: string, description: ?string, unit: string, higher_is_better: bool, scoring_method: string, passing_points: float, passing_value: ?float, maximum_value: ?float, points_table: list<array{value: float, points: float}>|null, sort_order: int, is_active: bool}  $data
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
        $standard = $event->standard();

        return [
            'name' => $event->name,
            'description' => $event->description,
            'unit' => $event->unit->value,
            'higher_is_better' => $event->higher_is_better,
            'scoring_method' => $standard->method->value,
            'passing_points' => (string) $event->passing_points,
            'passing_value' => $event->passing_value === null ? null : (string) $event->passing_value,
            'maximum_value' => $event->maximum_value === null ? null : (string) $event->maximum_value,
            // "25 = 60, 30 = 70": each result and the points it earns.
            'points_table' => $standard->table === [] ? null : implode(', ', array_map(
                fn (array $row): string => FitnessValue::format($row['value'], $event->unit).' = '.rtrim(rtrim(number_format($row['points'], 2, '.', ''), '0'), '.'),
                $standard->table,
            )),
            'sort_order' => $event->sort_order,
            'is_active' => $event->is_active,
        ];
    }
}
