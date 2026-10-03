<?php

namespace Database\Seeders;

use App\Models\ClassSubject;
use App\Models\TrainingPhase;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * Places the demo subjects of every class in the placeholder training phases
 * (owner request, 2026-10-03), so the demo shows phase averages and a CGPA:
 * Subject 1 in Phase 1 (3 units), Subject 2 in Phase 2 (2 units), Subjects 3
 * and 4 in Phase 3 (1 unit each). The phases come from their migration.
 *
 * Only subjects not yet in a phase are changed, so administrators' own
 * choices are kept and it is safe to run again. Never in production.
 */
class DemoTrainingPhasesSeeder extends Seeder
{
    /** Subject name => [phase number, units]. */
    private const PLACEMENT = [
        'Subject 1' => [1, '3'],
        'Subject 2' => [2, '2'],
        'Subject 3' => [3, '1'],
        'Subject 4' => [3, '1'],
    ];

    public function run(): void
    {
        if (app()->environment('production')) {
            throw new RuntimeException('Demo training phases must not be seeded in production.');
        }

        $phases = TrainingPhase::query()->get()->keyBy('number');

        ClassSubject::query()
            ->whereNull('training_phase_id')
            ->with('subject:id,name')
            ->get()
            ->each(function (ClassSubject $offering) use ($phases): void {
                [$number, $units] = self::PLACEMENT[$offering->subject->name] ?? [null, null];
                $phase = $number === null ? null : $phases->get($number);
                if ($phase !== null) {
                    $offering->forceFill(['training_phase_id' => $phase->id, 'units' => $units])->save();
                }
            });
    }
}
