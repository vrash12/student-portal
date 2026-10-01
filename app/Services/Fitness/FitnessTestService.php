<?php

namespace App\Services\Fitness;

use App\Enums\AuditAction;
use App\Models\Candidate;
use App\Models\ClassBatch;
use App\Models\FitnessEvent;
use App\Models\FitnessResult;
use App\Models\FitnessTest;
use App\Models\FitnessTestEvent;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Creates fitness tests and records their results. Every change is in the
 * audit log with the previous and new values (AGENTS.md §35-§37).
 */
final class FitnessTestService
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * Creates the test with a copy of the chosen events' current standards.
     *
     * @param  array{title: string, tested_on: string, notes: ?string}  $details
     * @param  list<int>  $eventIds
     */
    public function create(ClassBatch $classBatch, array $details, array $eventIds, User $actor): FitnessTest
    {
        return DB::transaction(function () use ($classBatch, $details, $eventIds, $actor): FitnessTest {
            $events = FitnessEvent::query()->active()->whereKey($eventIds)->ordered()->get();
            if ($events->isEmpty()) {
                throw ValidationException::withMessages(['event_ids' => 'Choose at least one active fitness event.']);
            }

            $test = new FitnessTest($details);
            $test->class_batch_id = $classBatch->id;
            $test->created_by = $actor->id;
            $test->save();

            foreach ($events->values() as $index => $event) {
                $testEvent = new FitnessTestEvent;
                $testEvent->forceFill([
                    'fitness_test_id' => $test->id,
                    'fitness_event_id' => $event->id,
                    'name' => $event->name,
                    'unit' => $event->unit,
                    'higher_is_better' => $event->higher_is_better,
                    'passing_value' => $event->passing_value,
                    'maximum_value' => $event->maximum_value,
                    'position' => $index + 1,
                ])->save();
            }

            $this->audit->record(AuditAction::FitnessTestCreated, $test, newValues: [
                ...$this->snapshot($test),
                'class' => $classBatch->name,
                'events' => $events->pluck('name')->all(),
            ]);

            return $test;
        });
    }

    /**
     * @param  array{title: string, tested_on: string, notes: ?string}  $details
     */
    public function update(FitnessTest $test, array $details): FitnessTest
    {
        return DB::transaction(function () use ($test, $details): FitnessTest {
            $before = $this->snapshot($test);
            $test->fill($details)->save();

            $this->audit->recordChanges(AuditAction::FitnessTestUpdated, $test, $before, $this->snapshot($test));

            return $test;
        });
    }

    /** Only a test without results can be deleted; recorded results are history. */
    public function delete(FitnessTest $test): void
    {
        DB::transaction(function () use ($test): void {
            $locked = FitnessTest::query()->whereKey($test->id)->lockForUpdate()->firstOrFail();
            if ($locked->results()->exists()) {
                throw ValidationException::withMessages(['test' => 'This fitness test has recorded results and cannot be deleted.']);
            }

            $this->audit->record(AuditAction::FitnessTestDeleted, $locked, oldValues: $this->snapshot($locked));
            $locked->delete();
        });
    }

    /**
     * Saves raw results; a null value removes a recorded result. Only
     * candidates of the test's class who are not withdrawn can be recorded.
     *
     * @param  array<int, array<int, float|null>>  $entries  candidate id => [test event id => value]
     * @return int the number of results that changed
     */
    public function recordResults(FitnessTest $test, array $entries, User $actor): int
    {
        return DB::transaction(function () use ($test, $entries, $actor): int {
            // One save per test at a time, so concurrent saves cannot interleave.
            FitnessTest::query()->whereKey($test->id)->lockForUpdate()->firstOrFail();

            $events = $test->events()->get()->keyBy('id');
            $candidates = Candidate::query()->whereKey(array_keys($entries))->get()->keyBy('id');
            $existing = FitnessResult::query()
                ->whereIn('fitness_test_event_id', $events->keys())
                ->whereIn('candidate_id', array_keys($entries))
                ->get()
                ->keyBy(fn (FitnessResult $result): string => $result->candidate_id.'-'.$result->fitness_test_event_id);

            $before = [];
            $after = [];
            foreach ($entries as $candidateId => $values) {
                $candidate = $candidates->get($candidateId);
                if ($candidate === null || ! $candidate->isGradableIn($test->class_batch_id)) {
                    throw ValidationException::withMessages(['entries' => 'Results can only be recorded for candidates of this class who are not withdrawn.']);
                }

                foreach ($values as $eventId => $value) {
                    $event = $events->get($eventId) ?? throw ValidationException::withMessages(['entries' => 'One of the events is not part of this test.']);
                    $result = $existing->get($candidateId.'-'.$eventId);
                    $old = $result === null ? null : (float) $result->value;
                    if ($old === $value || ($old !== null && $value !== null && round($old, 2) === round($value, 2))) {
                        continue;
                    }

                    if ($value === null) {
                        $result?->delete();
                    } else {
                        $result ??= new FitnessResult;
                        $result->forceFill([
                            'fitness_test_event_id' => $event->id,
                            'candidate_id' => $candidate->id,
                            'value' => $value,
                            'recorded_by' => $actor->id,
                        ])->save();
                    }

                    $label = $candidate->candidate_number.' · '.$event->name;
                    $before[$label] = $old === null ? null : FitnessValue::format($old, $event->unit);
                    $after[$label] = $value === null ? null : FitnessValue::format($value, $event->unit);
                }
            }

            if ($after !== []) {
                $this->audit->record(AuditAction::FitnessResultsRecorded, $test, oldValues: $before, newValues: $after, actor: $actor);
            }

            return count($after);
        });
    }

    /**
     * @return array{title: string, tested_on: string, notes: ?string}
     */
    private function snapshot(FitnessTest $test): array
    {
        return [
            'title' => $test->title,
            'tested_on' => $test->tested_on->toDateString(),
            'notes' => $test->notes,
        ];
    }
}
