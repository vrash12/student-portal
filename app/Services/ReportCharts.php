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
            'class' => [$this->classStandings($rows)],
            'subject' => [$this->subjectAverages($rows, $periodId), $this->subjectStandings($rows)],
            'distribution' => [$this->gradeDistribution($rows)],
            // No chart until at least one attempt has a final score.
            'examination', 'quiz' => array_values(array_filter([$this->scoreDistribution($rows)])),
            // At-risk and failing lists hold one standing only: a chart would add nothing.
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
