<?php

namespace App\Services\Monitoring;

use App\Enums\AcademicStanding;
use App\Enums\CandidateStatus;
use App\Models\AcademicPeriod;
use App\Models\Candidate;
use App\Models\ClassSubject;
use App\Services\Grading\GradeCalculationService;
use App\Services\Grading\GradingThresholds;
use Illuminate\Database\Eloquent\Collection;

/**
 * Read model for academic monitoring (AGENTS.md §16): which candidates need
 * attention, from the standings GradeCalculationService calculates.
 *
 * The monitored population is every candidate who can be graded (assigned
 * and not withdrawn) in a class that has at least one of the given class
 * subjects, and standings are calculated over those class subjects only.
 * With one subject selected, each candidate has one subject, so the overall
 * standing, counts, filter, and sort are that subject's.
 *
 * Everything is calculated on read for the whole population, then filtered,
 * sorted, and paginated in PHP. That is fast for hundreds of candidates; if
 * deployments grow much larger, cache aggregates here (AGENTS.md §39).
 */
final class AcademicMonitoring
{
    public const SORTS = ['lowest', 'highest', 'name'];

    public const STANDING_FILTERS = ['failing', 'at_risk', 'incomplete', 'passing', 'none'];

    public function __construct(
        private readonly GradeCalculationService $calculator,
        private readonly SubjectPerformance $performance = new SubjectPerformance,
    ) {}

    /**
     * @param  Collection<int, ClassSubject>  $offerings  the monitoring scope, already narrowed by filters
     * @return list<MonitoredCandidate> in name order (last name, first name, id)
     */
    public function evaluate(Collection $offerings): array
    {
        if ($offerings->isEmpty()) {
            return [];
        }

        $offerings->loadMissing(['subject:id,code,name', 'classBatch.academicPeriod']);

        $candidates = Candidate::query()
            ->with('classBatch:id,name')
            ->whereIn('class_batch_id', $offerings->pluck('class_batch_id')->unique()->values()->all())
            ->where('status', '!=', CandidateStatus::Withdrawn->value)
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->orderBy('id')
            ->get();

        $grades = $this->calculator->forClasses($offerings, $candidates);
        $byClass = $offerings
            ->sortBy(fn (ClassSubject $offering): string => $offering->subject->name)
            ->groupBy('class_batch_id');

        return $candidates
            ->map(function (Candidate $candidate) use ($grades, $byClass): MonitoredCandidate {
                $subjects = [];
                foreach ($byClass->get($candidate->class_batch_id, collect()) as $offering) {
                    $subjects[] = ['offering' => $offering, 'grade' => $grades[$candidate->id][$offering->id]];
                }

                return new MonitoredCandidate(
                    $candidate,
                    $subjects,
                    $this->calculator->overallStanding(array_column($subjects, 'grade')),
                );
            })
            ->values()
            ->all();
    }

    /**
     * Candidates per overall standing. The buckets always add up to the total.
     *
     * @param  list<MonitoredCandidate>  $monitored
     * @return array{monitored: int, failing: int, atRisk: int, incomplete: int, passing: int, noStanding: int}
     */
    public function counts(array $monitored): array
    {
        $counts = ['monitored' => count($monitored), 'failing' => 0, 'atRisk' => 0, 'incomplete' => 0, 'passing' => 0, 'noStanding' => 0];

        foreach ($monitored as $entry) {
            $counts[self::countKey($entry->overall->standing)]++;
        }

        return $counts;
    }

    /**
     * @param  list<MonitoredCandidate>  $monitored
     * @param  list<int>|null  $matchingIds  candidates matching the search (Candidate::matching), or null for no search
     * @return list<MonitoredCandidate>
     */
    public function filter(array $monitored, string $standing, ?array $matchingIds): array
    {
        $matching = $matchingIds === null ? null : array_flip($matchingIds);

        return array_values(array_filter(
            $monitored,
            fn (MonitoredCandidate $entry): bool => ($standing === '' || $entry->standingKey() === $standing)
                && ($matching === null || isset($matching[$entry->candidate->id])),
        ));
    }

    /**
     * Sorts a copy of a list in name order. '' (default): most serious
     * standing first, then lowest grade; "lowest" / "highest": by the lowest
     * subject grade; "name": unchanged. Candidates without a grade or
     * standing always come last; ties keep name order (stable sort).
     *
     * @param  list<MonitoredCandidate>  $monitored
     * @return list<MonitoredCandidate>
     */
    public function sort(array $monitored, string $sort): array
    {
        if ($sort === 'name') {
            return $monitored;
        }

        usort($monitored, function (MonitoredCandidate $a, MonitoredCandidate $b) use ($sort): int {
            $gradeA = $a->lowestGrade();
            $gradeB = $b->lowestGrade();
            $byGrade = match (true) {
                $gradeA === null && $gradeB === null => 0,
                $gradeA === null => 1,
                $gradeB === null => -1,
                $sort === 'highest' => $gradeB <=> $gradeA,
                default => $gradeA <=> $gradeB,
            };
            $bySeverity = $b->severity() <=> $a->severity();

            return $sort === '' ? ($bySeverity ?: $byGrade) : ($byGrade ?: $bySeverity);
        });

        return $monitored;
    }

    /**
     * Failing and At Risk candidates, most serious first.
     *
     * @param  list<MonitoredCandidate>  $monitored
     * @return list<MonitoredCandidate>
     */
    public function requiringAttention(array $monitored, int $limit): array
    {
        $attention = array_values(array_filter(
            $monitored,
            fn (MonitoredCandidate $entry): bool => in_array($entry->overall->standing, [AcademicStanding::Failing, AcademicStanding::AtRisk], true),
        ));

        return array_slice($this->sort($attention, ''), 0, $limit);
    }

    /**
     * Class subjects with at least one Failing or At Risk candidate, with
     * counts per standing, most Failing first (UI_UX_DESIGN.md §35).
     *
     * @param  list<MonitoredCandidate>  $monitored
     * @return list<array{classSubjectId: int, classBatch: array{id: int, name: string}, subject: array{id: int, name: string}, failing: int, atRisk: int, incomplete: int}>
     */
    public function subjectsRequiringAttention(array $monitored): array
    {
        $rows = [];
        foreach ($monitored as $entry) {
            foreach ($entry->subjects as $subject) {
                $offering = $subject['offering'];
                $rows[$offering->id] ??= [
                    'classSubjectId' => $offering->id,
                    'classBatch' => ['id' => $entry->candidate->classBatch->id, 'name' => $entry->candidate->classBatch->name],
                    'subject' => ['id' => $offering->subject->id, 'name' => $offering->subject->name],
                    'failing' => 0,
                    'atRisk' => 0,
                    'incomplete' => 0,
                ];
                $key = match ($subject['grade']->standing) {
                    AcademicStanding::Failing => 'failing',
                    AcademicStanding::AtRisk => 'atRisk',
                    AcademicStanding::Incomplete => 'incomplete',
                    default => null,
                };
                if ($key !== null) {
                    $rows[$offering->id][$key]++;
                }
            }
        }

        $rows = array_values(array_filter($rows, fn (array $row): bool => $row['failing'] + $row['atRisk'] > 0));
        usort($rows, fn (array $a, array $b): int => [$b['failing'], $b['atRisk'], $a['subject']['name'], $a['classBatch']['name']]
            <=> [$a['failing'], $a['atRisk'], $b['subject']['name'], $b['classBatch']['name']]);

        return $rows;
    }

    /**
     * Dashboard panel data for the active period within a scope, or null
     * when there is no active period or nothing is in scope. With
     * `$withSubjects`, it also summarizes each class subject (counts per
     * standing and mean grade) from the same evaluation.
     *
     * @return array{period: array{id: int, name: string}, scope: string, hasThresholds: bool, counts: array<string, int>, requiringAttention: list<MonitoredCandidate>, subjects?: list<array<string, mixed>>}|null
     */
    public function activePeriodSummary(MonitoringScope $scope, int $limit, bool $withSubjects = false): ?array
    {
        $period = AcademicPeriod::query()->active()->first();
        if ($period === null || $scope->isEmpty()) {
            return null;
        }

        $monitored = $this->evaluate($scope->offerings($period->id)->get());

        $summary = [
            'period' => ['id' => $period->id, 'name' => $period->name],
            'scope' => $scope->kind(),
            'hasThresholds' => GradingThresholds::forPeriod($period) !== null,
            'counts' => $this->counts($monitored),
            'requiringAttention' => $this->requiringAttention($monitored, $limit),
        ];

        return $withSubjects ? $summary + ['subjects' => $this->performance->summarize($monitored)] : $summary;
    }

    private static function countKey(?AcademicStanding $standing): string
    {
        return match ($standing) {
            AcademicStanding::Failing => 'failing',
            AcademicStanding::AtRisk => 'atRisk',
            AcademicStanding::Incomplete => 'incomplete',
            AcademicStanding::Passing => 'passing',
            null => 'noStanding',
        };
    }
}
