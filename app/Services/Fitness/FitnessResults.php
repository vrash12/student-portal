<?php

namespace App\Services\Fitness;

use App\Enums\CandidateStatus;
use App\Enums\FitnessStatus;
use App\Models\Candidate;
use App\Models\FitnessResult;
use App\Models\FitnessTest;
use App\Models\FitnessTestEvent;
use App\Support\ScoreBands;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Presents fitness results: the sheet of one test, summaries for the test
 * list, and a candidate's history. Points and outcomes always come from
 * FitnessStandard and FitnessOutcome.
 */
final class FitnessResults
{
    /**
     * Events, one row per candidate (the class's current candidates who are
     * not withdrawn, plus anyone with recorded results), and a summary.
     *
     * @return array{events: list<array<string, mixed>>, rows: list<array<string, mixed>>, summary: array<string, mixed>}
     */
    public function sheet(FitnessTest $test): array
    {
        $events = $test->events()->get();
        $results = FitnessResult::query()->whereIn('fitness_test_event_id', $events->modelKeys())->get()->groupBy('candidate_id');

        $candidates = Candidate::query()
            ->where(fn (Builder $query) => $query
                ->where(fn (Builder $roster) => $roster->where('class_batch_id', $test->class_batch_id)->where('status', '!=', CandidateStatus::Withdrawn->value))
                ->orWhereIn('id', $results->keys()))
            ->orderBy('candidate_number')
            ->get();

        $rows = $candidates->map(function (Candidate $candidate) use ($events, $results, $test): array {
            $evaluated = $this->evaluate($events, $results->get($candidate->id, new Collection));

            return [
                'candidate' => [
                    'id' => $candidate->id,
                    'candidateNumber' => $candidate->candidate_number,
                    'name' => $candidate->full_name,
                    'status' => ['value' => $candidate->status->value, 'label' => $candidate->status->label(), 'tone' => $candidate->status->tone()],
                ],
                'recordable' => $candidate->isGradableIn($test->class_batch_id),
                'results' => $evaluated['results'],
                'outcome' => $evaluated['outcome'],
            ];
        })->values()->all();

        return [
            'events' => $events->map(fn (FitnessTestEvent $event): array => $this->presentEvent($event))->all(),
            'rows' => $rows,
            'summary' => $this->summarize($events, $rows),
        ];
    }

    /**
     * Outcome counts of each test, for the test list.
     *
     * @param  Collection<int, FitnessTest>  $tests
     * @return array<int, array{passed: int, failed: int, incomplete: int, recorded: int}>
     */
    public function summaries(Collection $tests): array
    {
        $events = FitnessTestEvent::query()->whereIn('fitness_test_id', $tests->modelKeys())->orderBy('position')->get();
        $testOfEvent = $events->pluck('fitness_test_id', 'id');
        $eventsByTest = $events->groupBy('fitness_test_id');
        $resultsByTest = FitnessResult::query()
            ->whereIn('fitness_test_event_id', $events->modelKeys())
            ->get()
            ->groupBy(fn (FitnessResult $result): int => (int) $testOfEvent[$result->fitness_test_event_id]);

        $summaries = [];
        foreach ($tests as $test) {
            $testEvents = $eventsByTest->get($test->id, new Collection);
            $counts = ['passed' => 0, 'failed' => 0, 'incomplete' => 0, 'recorded' => 0];
            foreach ($resultsByTest->get($test->id, new Collection)->groupBy('candidate_id') as $candidateResults) {
                $status = $this->evaluate($testEvents, $candidateResults)['outcome']['status']['value'];
                $counts['recorded']++;
                $counts[$status === FitnessStatus::Passed->value ? 'passed' : ($status === FitnessStatus::Failed->value ? 'failed' : 'incomplete')]++;
            }
            $summaries[$test->id] = $counts;
        }

        return $summaries;
    }

    /**
     * The candidate's fitness tests, newest first: every test with a result
     * for the candidate, and the tests of their current class.
     *
     * @return list<array<string, mixed>>
     */
    public function history(Candidate $candidate, int $limit = 10): array
    {
        $tests = FitnessTest::query()
            ->where(fn (Builder $query) => $query
                ->whereHas('results', fn (Builder $results) => $results->where('candidate_id', $candidate->id))
                ->when($candidate->class_batch_id !== null && $candidate->status !== CandidateStatus::Withdrawn, fn (Builder $tests) => $tests->orWhere('class_batch_id', $candidate->class_batch_id)))
            ->with(['classBatch:id,name', 'events'])
            ->orderByDesc('tested_on')
            ->orderByDesc('id')
            ->limit($limit)
            ->get();

        $results = FitnessResult::query()
            ->where('candidate_id', $candidate->id)
            ->whereIn('fitness_test_event_id', $tests->flatMap(fn (FitnessTest $test): array => $test->events->modelKeys())->all())
            ->get();

        return $tests->map(function (FitnessTest $test) use ($results): array {
            $evaluated = $this->evaluate($test->events, $results->whereIn('fitness_test_event_id', $test->events->modelKeys()));

            return [
                'id' => $test->id,
                'title' => $test->title,
                'testedOn' => $test->tested_on->toDateString(),
                'classBatch' => $test->classBatch->name,
                'events' => $test->events->map(fn (FitnessTestEvent $event): array => [
                    ...$this->presentEvent($event),
                    'result' => $evaluated['results'][$event->id] ?? null,
                ])->values()->all(),
                'outcome' => $evaluated['outcome'],
            ];
        })->all();
    }

    /**
     * @param  Collection<int, FitnessTestEvent>  $events
     * @param  Collection<int, FitnessResult>  $results  one candidate's results
     * @return array{results: array<int, array{value: float, display: string, points: float, passed: bool}|null>, outcome: array{status: array{value: string, label: string, tone: string}, points: ?float}}
     */
    private function evaluate(Collection $events, Collection $results): array
    {
        $byEvent = $results->keyBy('fitness_test_event_id');
        $evaluated = [];
        foreach ($events as $event) {
            $result = $byEvent->get($event->id);
            if ($result === null) {
                $evaluated[$event->id] = null;

                continue;
            }

            $value = (float) $result->value;
            $standard = $event->standard();
            $evaluated[$event->id] = [
                'value' => $value,
                'display' => FitnessValue::format($value, $event->unit),
                'points' => $standard->points($value),
                'passed' => $standard->passes($value),
            ];
        }

        $outcome = FitnessOutcome::of(array_values(array_map(
            fn (?array $result): ?array => $result === null ? null : ['points' => $result['points'], 'passed' => $result['passed']],
            $evaluated,
        )));

        return [
            'results' => $evaluated,
            'outcome' => ['status' => $outcome->status->toArray(), 'points' => $outcome->points],
        ];
    }

    /**
     * @param  Collection<int, FitnessTestEvent>  $events
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    private function summarize(Collection $events, array $rows): array
    {
        $counts = array_fill_keys(array_map(fn (FitnessStatus $status): string => $status->value, FitnessStatus::cases()), 0);
        $points = [];
        foreach ($rows as $row) {
            $counts[$row['outcome']['status']['value']]++;
            if ($row['outcome']['points'] !== null) {
                $points[] = $row['outcome']['points'];
            }
        }

        return [
            'counts' => $counts,
            'meanPoints' => $points === [] ? null : round(array_sum($points) / count($points), 2),
            'pointsDistribution' => $points === [] ? [] : ScoreBands::columns(ScoreBands::count($points, withMissing: false)),
            // Share of recorded results that met each event's passing standard.
            'eventPassRates' => $events->map(function (FitnessTestEvent $event) use ($rows): array {
                $recorded = array_values(array_filter(array_map(fn (array $row): ?array => $row['results'][$event->id] ?? null, $rows)));
                $passed = count(array_filter($recorded, fn (array $result): bool => $result['passed']));

                return [
                    'label' => $event->name,
                    'value' => $recorded === [] ? null : round($passed * 100 / count($recorded), 2),
                    'recorded' => count($recorded),
                    'passed' => $passed,
                ];
            })->values()->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function presentEvent(FitnessTestEvent $event): array
    {
        return [
            'id' => $event->id,
            'name' => $event->name,
            ...$event->standard()->toArray(),
        ];
    }
}
