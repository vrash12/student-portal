<?php

namespace App\Services;

use App\Enums\AcademicStanding;
use App\Models\AcademicPeriod;
use App\Services\Grading\GradingThresholds;
use App\Support\ScoreBands;

/**
 * The charts shown above a report (UI_UX_DESIGN.md §61), built from every
 * row of the report before pagination, so a chart always matches the whole
 * filtered report and never only the visible page. Charts summarize the
 * rows; they never calculate grades of their own.
 */
final class ReportCharts
{
    /** Bars beyond this are left to the table, so a chart stays readable. */
    public const MAX_GROUPS = 15;

    /** Slices of a pie: the largest groups, then one slice for the rest. */
    public const MAX_SLICES = 6;

    /** Examinations on a results line: the latest ones. */
    public const MAX_POINTS = 20;

    /**
     * @param  list<array<string, mixed>>  $rows  every row of the report
     * @return list<array<string, mixed>>
     */
    public function for(string $type, array $rows, ?int $periodId): array
    {
        if ($rows === []) {
            return [];
        }

        return match ($type) {
            'candidate' => [$this->candidateStandings($rows)],
            'class' => [$this->classTotals($rows), $this->classStandings($rows)],
            'subject' => [$this->subjectAverages($rows, $periodId), $this->subjectStandings($rows)],
            'distribution' => [$this->gradeDistribution($rows)],
            // Each chart only once it has something to show: a final score, a decided outcome, two examinations.
            'examination', 'quiz' => array_values(array_filter([
                $this->scoreDistribution($rows),
                $this->outcomes($rows),
                $this->resultsByExamination($rows, $type),
            ])),
            // Needs Improvement and Failing lists hold one standing: the chart shows where those candidates are.
            'at_risk', 'failing' => array_values(array_filter([$this->listByClass($rows, $type)])),
            default => [],
        };
    }

    /** @param list<array<string, mixed>> $rows */
    private function candidateStandings(array $rows): array
    {
        $labels = [
            AcademicStanding::Passing->label() => 'passing',
            AcademicStanding::AtRisk->label() => 'atRisk',
            AcademicStanding::Failing->label() => 'failing',
            AcademicStanding::Incomplete->label() => 'incomplete',
        ];
        $counts = self::emptyTally();
        foreach ($rows as $row) {
            $counts[$labels[$row['standing']] ?? 'noStanding']++;
        }

        return [
            'kind' => 'standingTotal',
            'title' => 'Overall Standing',
            'description' => 'Candidates by their most serious subject standing.',
            'counts' => $counts,
        ];
    }

    /**
     * Every candidate of the listed classes by overall standing.
     *
     * @param  list<array<string, mixed>>  $rows
     */
    private function classTotals(array $rows): array
    {
        $counts = self::emptyTally();
        foreach ($rows as $row) {
            foreach (array_keys($counts) as $standing) {
                $counts[$standing] += (int) $row[$standing];
            }
        }

        return [
            'kind' => 'standingTotal',
            'title' => 'Overall Standing',
            'description' => 'Candidates of every class listed, by their most serious subject standing.',
            'counts' => $counts,
        ];
    }

    /**
     * Where the candidates of a Needs Improvement or Failing list are: candidates per
     * class, the largest classes first and the rest as one slice. Null when
     * they are all in one class (a full ring would add nothing).
     *
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, mixed>|null
     */
    private function listByClass(array $rows, string $type): ?array
    {
        $counts = array_count_values(array_map(fn (array $row): string => (string) $row['classBatch'], $rows));
        if (count($counts) < 2) {
            return null;
        }
        arsort($counts);
        $slices = array_map(fn (string|int $class, int $count): array => ['label' => (string) $class, 'value' => $count, 'tone' => null], array_keys($counts), $counts);
        if (count($slices) > self::MAX_SLICES) {
            $rest = array_slice($slices, self::MAX_SLICES - 1);
            $slices = [...array_slice($slices, 0, self::MAX_SLICES - 1), [
                'label' => count($rest).' other classes', 'value' => array_sum(array_column($rest, 'value')), 'tone' => 'none',
            ]];
        }

        return [
            'kind' => 'pie',
            'title' => $type === 'failing' ? 'Failing Candidates by Class' : 'Candidates Needing Improvement by Class',
            'description' => 'The class of each candidate on this list.',
            'slices' => $slices,
            'noun' => ['one' => 'candidate', 'other' => 'candidates'],
        ];
    }

    /** @param list<array<string, mixed>> $rows */
    private function classStandings(array $rows): array
    {
        return [
            'kind' => 'standing',
            'title' => 'Standing by Class',
            'description' => $this->limitNote(count($rows), 'Candidates of each class by overall standing.'),
            'groups' => array_map(fn (array $row): array => [
                'label' => $row['classBatch'],
                'counts' => [
                    'passing' => $row['passing'], 'atRisk' => $row['atRisk'], 'failing' => $row['failing'],
                    'incomplete' => $row['incomplete'], 'noStanding' => $row['noStanding'],
                ],
            ], array_slice($rows, 0, self::MAX_GROUPS)),
        ];
    }

    /** @param list<array<string, mixed>> $rows */
    private function subjectAverages(array $rows, ?int $periodId): array
    {
        $period = $periodId === null ? null : AcademicPeriod::query()->find($periodId);
        $thresholds = $period === null ? null : GradingThresholds::forPeriod($period);

        return [
            'kind' => 'bars',
            'title' => 'Mean Current Grade by Subject',
            'description' => $this->limitNote(count($rows), 'Mean of the candidates’ current weighted grades, including provisional grades.'),
            'bars' => array_map(fn (array $row): array => [
                'label' => $row['subject'].' · '.$row['classBatch'],
                'value' => $row['average'],
            ], array_slice($rows, 0, self::MAX_GROUPS)),
            'references' => $thresholds === null ? [] : [
                ['label' => 'Passing grade', 'value' => $thresholds->passingGrade()],
                ['label' => 'Warning grade', 'value' => $thresholds->warningGrade()],
            ],
        ];
    }

    /** @param list<array<string, mixed>> $rows */
    private function subjectStandings(array $rows): array
    {
        return [
            'kind' => 'standing',
            'title' => 'Standing by Subject',
            'description' => $this->limitNote(count($rows), 'Candidates of each subject by their standing in that subject.'),
            'groups' => array_map(fn (array $row): array => [
                'label' => $row['subject'].' · '.$row['classBatch'],
                'counts' => [
                    'passing' => $row['passing'], 'atRisk' => $row['at_risk'], 'failing' => $row['failing'],
                    'incomplete' => $row['incomplete'], 'noStanding' => $row['none'],
                ],
            ], array_slice($rows, 0, self::MAX_GROUPS)),
        ];
    }

    /** @param list<array{band: string, count: int}> $rows */
    private function gradeDistribution(array $rows): array
    {
        return [
            'kind' => 'columns',
            'title' => 'Grade Distribution',
            'description' => 'Current subject grades by range. Ranges are descriptive; standing follows the period’s passing and warning grades.',
            'columns' => ScoreBands::columns($rows),
            'noun' => ['one' => 'record', 'other' => 'records'],
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, mixed>|null null when no attempt has a final score
     */
    private function scoreDistribution(array $rows): ?array
    {
        $scored = array_values(array_filter(array_column($rows, 'percentage'), fn (mixed $percentage): bool => $percentage !== null));
        if ($scored === []) {
            return null;
        }
        $pending = count($rows) - count($scored);

        return [
            'kind' => 'columns',
            'title' => 'Score Distribution',
            'description' => count($scored).' '.(count($scored) === 1 ? 'attempt has' : 'attempts have').' a final score'
                .($pending > 0 ? '; '.$pending.' awaiting essay grading or without a score '.($pending === 1 ? 'is' : 'are').' not included.' : '.'),
            'columns' => ScoreBands::columns(ScoreBands::count($scored, withMissing: false)),
            'noun' => ['one' => 'attempt', 'other' => 'attempts'],
        ];
    }

    /**
     * Outcomes of the attempts: passed, failed, awaiting essay grading, and
     * scored without a passing score. Null until one attempt passed or failed.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, mixed>|null
     */
    private function outcomes(array $rows): ?array
    {
        $counts = ['passed' => 0, 'failed' => 0, 'awaiting' => 0, 'noPassingScore' => 0, 'notScored' => 0];
        foreach ($rows as $row) {
            $chart = $row[ReportingService::CHART_KEY];
            $outcome = match (true) {
                $chart['passed'] === true => 'passed',
                $chart['passed'] === false => 'failed',
                $chart['resultStatus'] === 'pending_review' => 'awaiting',
                $chart['resultStatus'] === 'graded' => 'noPassingScore',
                default => 'notScored',
            };
            $counts[$outcome]++;
        }
        if ($counts['passed'] + $counts['failed'] === 0) {
            return null;
        }

        return [
            'kind' => 'pie',
            'title' => 'Outcomes',
            'description' => 'Every attempt listed, by its result against the passing score.',
            'slices' => [
                ['label' => 'Passed', 'value' => $counts['passed'], 'tone' => 'passing'],
                ['label' => 'Failed', 'value' => $counts['failed'], 'tone' => 'failing'],
                ['label' => 'Awaiting essay grading', 'value' => $counts['awaiting'], 'tone' => 'incomplete'],
                ['label' => 'Scored, no passing score set', 'value' => $counts['noPassingScore'], 'tone' => 'c3'],
                ['label' => 'Not scored', 'value' => $counts['notScored'], 'tone' => 'none'],
            ],
            'noun' => ['one' => 'attempt', 'other' => 'attempts'],
        ];
    }

    /**
     * The mean final score and the pass rate of each examination, in the
     * order they were taken (first submission), as a line. Means are of the
     * scored attempts listed, rounded to two decimals like item analysis; the
     * pass rate is of the attempts with a decided outcome. Null until two
     * examinations have a final score.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, mixed>|null
     */
    private function resultsByExamination(array $rows, string $type): ?array
    {
        $examinations = [];
        foreach ($rows as $row) {
            $chart = $row[ReportingService::CHART_KEY];
            $id = (int) $chart['examination'];
            $examination = $examinations[$id] ?? [
                'id' => $id, 'title' => (string) $row['examination'], 'subject' => (string) $row['subject'],
                'classBatch' => (string) $row['classBatch'], 'first' => null, 'scores' => [], 'passed' => 0, 'decided' => 0,
            ];
            // Submission times are "Y-m-d H:i" in one timezone, so they compare as text.
            if ($row['submitted'] !== null && ($examination['first'] === null || $row['submitted'] < $examination['first'])) {
                $examination['first'] = $row['submitted'];
            }
            if ($row['percentage'] !== null) {
                $examination['scores'][] = (float) $row['percentage'];
            }
            if ($chart['passed'] !== null) {
                $examination['decided']++;
                $examination['passed'] += $chart['passed'] ? 1 : 0;
            }
            $examinations[$id] = $examination;
        }

        $scored = array_values(array_filter($examinations, fn (array $examination): bool => $examination['scores'] !== []));
        if (count($scored) < 2) {
            return null;
        }
        usort($scored, fn (array $a, array $b): int => [$a['first'] ?? '', $a['id']] <=> [$b['first'] ?? '', $b['id']]);
        $total = count($scored);
        $shown = array_slice($scored, -self::MAX_POINTS);
        $passRates = array_map(fn (array $examination): ?float => $examination['decided'] === 0 ? null : round($examination['passed'] * 100 / $examination['decided'], 2), $shown);
        [$one, $many] = $type === 'quiz' ? ['quiz', 'quizzes'] : ['examination', 'examinations'];

        $series = [[
            'label' => 'Mean score',
            'tone' => 'c1',
            'values' => array_map(fn (array $examination): float => round(array_sum($examination['scores']) / count($examination['scores']), 2), $shown),
        ]];
        if (array_filter($passRates, fn (?float $rate): bool => $rate !== null) !== []) {
            $series[] = ['label' => 'Pass rate', 'tone' => 'c2', 'values' => $passRates];
        }

        return [
            'kind' => 'line',
            'wide' => true,
            'title' => 'Results by '.ucfirst($one),
            'description' => 'Mean final score of each '.$one.'’s scored attempts and the share of decided attempts that passed, in the order they were taken.'
                .($total > self::MAX_POINTS ? ' Showing the latest '.self::MAX_POINTS.' of '.$total.' '.$many.'.' : ''),
            'xLabel' => ucfirst($one),
            'format' => 'percent',
            'references' => [],
            'data' => [
                'xType' => 'category',
                'categories' => array_map(fn (array $examination): array => [
                    'label' => $examination['title'],
                    'detail' => $examination['subject'].' · '.$examination['classBatch'].' · '.count($examination['scores']).' scored',
                ], $shown),
                'series' => $series,
            ],
        ];
    }

    private function limitNote(int $total, string $description): string
    {
        return $total > self::MAX_GROUPS
            ? $description.' Showing the first '.self::MAX_GROUPS.' of '.$total.'; the table lists all.'
            : $description;
    }

    /** @return array{passing: int, atRisk: int, failing: int, incomplete: int, noStanding: int} */
    private static function emptyTally(): array
    {
        return ['passing' => 0, 'atRisk' => 0, 'failing' => 0, 'incomplete' => 0, 'noStanding' => 0];
    }
}
