<?php

namespace Database\Seeders;

use App\Enums\CandidateStatus;
use App\Enums\SystemRole;
use App\Models\AcademicPeriod;
use App\Models\Candidate;
use App\Models\ClassBatch;
use App\Models\FitnessEvent;
use App\Models\FitnessTest;
use App\Models\User;
use App\Services\Fitness\FitnessStandardService;
use App\Services\Fitness\FitnessTestService;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * Demonstration military fitness data: three SAMPLE events (the standards
 * are placeholders, not institutional requirements; replace them with the
 * official standards in Military Fitness → Events and Points) and one
 * diagnostic test with synthetic results for every class of the active
 * period. Safe to run again: existing events and tests are kept.
 */
class DemoFitnessSeeder extends Seeder
{
    private const TEST_TITLE = 'Diagnostic Fitness Test';

    public function run(FitnessStandardService $standards, FitnessTestService $tests): void
    {
        if (app()->isProduction()) {
            throw new RuntimeException('Demo fitness data must not be seeded in production.');
        }

        $events = collect([
            ['name' => 'Push-ups (2 minutes)', 'unit' => 'repetitions', 'higher_is_better' => true, 'passing_value' => 40.0, 'maximum_value' => 70.0, 'sort_order' => 1],
            ['name' => 'Sit-ups (2 minutes)', 'unit' => 'repetitions', 'higher_is_better' => true, 'passing_value' => 45.0, 'maximum_value' => 75.0, 'sort_order' => 2],
            ['name' => '3.2 km Run', 'unit' => 'time', 'higher_is_better' => false, 'passing_value' => 960.0, 'maximum_value' => 720.0, 'sort_order' => 3],
        ])->map(fn (array $event): FitnessEvent => FitnessEvent::query()->where('name', $event['name'])->first()
            ?? $standards->create([...$event, 'description' => 'Sample standard for demonstration; replace with the official standard.']));

        $actor = User::query()->whereHas('role', fn ($role) => $role->where('code', SystemRole::SuperAdministrator->value))->orderBy('id')->first();
        $period = AcademicPeriod::query()->active()->first();
        if ($actor === null || $period === null) {
            return;
        }

        foreach (ClassBatch::query()->where('academic_period_id', $period->id)->get() as $class) {
            if (FitnessTest::query()->where('class_batch_id', $class->id)->where('title', self::TEST_TITLE)->exists()) {
                continue;
            }

            $test = $tests->create($class, ['title' => self::TEST_TITLE, 'tested_on' => $period->starts_on->copy()->addWeeks(2)->toDateString(), 'notes' => 'Synthetic demonstration results.'], $events->pluck('id')->all(), $actor);
            $testEvents = $test->events()->get();

            $entries = [];
            $candidates = Candidate::query()->where('class_batch_id', $class->id)->where('status', '!=', CandidateStatus::Withdrawn->value)->orderBy('candidate_number')->get();
            foreach ($candidates->values() as $index => $candidate) {
                // Deterministic spread: most pass, some fall short in one event, one is still incomplete.
                $pushUps = 34 + ($index * 7) % 38;
                $sitUps = 40 + ($index * 5) % 36;
                $run = 690 + ($index * 37) % 330;
                $entries[$candidate->id] = [
                    $testEvents[0]->id => (float) $pushUps,
                    $testEvents[1]->id => (float) $sitUps,
                    $testEvents[2]->id => $index === 3 ? null : (float) $run,
                ];
            }

            if ($entries !== []) {
                $tests->recordResults($test, $entries, $actor);
            }
        }
    }
}
