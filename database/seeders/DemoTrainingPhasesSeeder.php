<?php

namespace Database\Seeders;

use App\Models\AcademicPeriod;
use App\Models\ClassSubject;
use App\Models\TrainingPhase;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * Places the demo subjects of every class in training phases of the class's
 * academic year (owner request, 2026-10-03), so the demo shows phase
 * averages and a CGPA: Subject 1 in Phase 1 (3 units), Subject 2 in Phase 2
 * (2 units), Subjects 3 and 4 in Phase 3 (1 unit each). A year without
 * phases gets Phase 1–3, sharing its days evenly.
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

    private const DEMO_PHASES = 3;

    public function run(): void
    {
        if (app()->environment('production')) {
            throw new RuntimeException('Demo training phases must not be seeded in production.');
        }

        ClassSubject::query()
            ->whereNull('training_phase_id')
            ->with(['subject:id,name', 'classBatch.academicPeriod'])
            ->get()
            ->groupBy(fn (ClassSubject $offering): int => (int) $offering->classBatch->academic_period_id)
            ->each(function ($offerings): void {
                $phases = $this->phasesOf($offerings->first()->classBatch->academicPeriod);

                foreach ($offerings as $offering) {
                    [$number, $units] = self::PLACEMENT[$offering->subject->name] ?? [null, null];
                    $phase = $number === null ? null : $phases->get($number);
                    if ($phase !== null) {
                        $offering->forceFill(['training_phase_id' => $phase->id, 'units' => $units])->save();
                    }
                }
            });
    }

    /**
     * The year's phases by number; Phase 1–3 over the year when it has none.
     *
     * @return Collection<int, TrainingPhase>
     */
    private function phasesOf(AcademicPeriod $period): Collection
    {
        if (! $period->trainingPhases()->exists()) {
            $days = (int) $period->starts_on->diffInDays($period->ends_on) + 1;
            foreach (range(0, self::DEMO_PHASES - 1) as $index) {
                $phase = new TrainingPhase([
                    'number' => $index + 1,
                    'name' => 'Phase '.($index + 1),
                    'starts_on' => $period->starts_on->addDays(intdiv($days * $index, self::DEMO_PHASES))->toDateString(),
                    'ends_on' => $period->starts_on->addDays(intdiv($days * ($index + 1), self::DEMO_PHASES) - 1)->toDateString(),
                ]);
                $phase->academicPeriod()->associate($period);
                $phase->save();
            }
        }

        return $period->trainingPhases()->get()->keyBy('number');
    }
}
