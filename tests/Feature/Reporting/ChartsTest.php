<?php

namespace Tests\Feature\Reporting;

use App\Models\User;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Charts on reports, dashboards and nothing else: every figure comes from
 * the report rows or the grade engine (see BuildsReportingFixtures for the
 * grades: A1 90/70, A2 60/none, B1 77; passing 75, warning 80).
 */
class ChartsTest extends TestCase
{
    use BuildsReportingFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-09-10 08:00:00'));
        $this->buildReportingFixtures();
    }

    public function test_candidate_report_chart_counts_overall_standings(): void
    {
        $charts = $this->reportCharts($this->academicAdmin, ['type' => 'candidate']);

        $this->assertCount(1, $charts);
        $this->assertSame('standingTotal', $charts[0]['kind']);
        $this->assertSame(['passing' => 0, 'atRisk' => 1, 'failing' => 2, 'incomplete' => 0, 'noStanding' => 0], $charts[0]['counts']);
    }

    public function test_subject_report_charts_mean_grades_with_thresholds_and_standings(): void
    {
        [$averages, $standings] = $this->reportCharts($this->academicAdmin, ['type' => 'subject']);

        $this->assertSame('bars', $averages['kind']);
        $this->assertSame(['Subject 1 · Sample Batch A', 'Subject 2 · Sample Batch A', 'Subject 1 · Sample Batch B'], array_column($averages['bars'], 'label'));
        $this->assertEquals([75.0, 70.0, 77.0], array_column($averages['bars'], 'value'));
        $this->assertEquals([['label' => 'Passing grade', 'value' => 75.0], ['label' => 'Warning grade', 'value' => 80.0]], $averages['references']);

        $this->assertSame('standing', $standings['kind']);
        $this->assertSame(['passing' => 1, 'atRisk' => 0, 'failing' => 1, 'incomplete' => 0, 'noStanding' => 0], $standings['groups'][0]['counts']);
        $this->assertSame(['passing' => 0, 'atRisk' => 1, 'failing' => 0, 'incomplete' => 0, 'noStanding' => 0], $standings['groups'][2]['counts']);
    }

    public function test_distribution_chart_matches_the_report_rows_from_the_lowest_range(): void
    {
        [$chart] = $this->reportCharts($this->academicAdmin, ['type' => 'distribution']);

        $this->assertSame('columns', $chart['kind']);
        $this->assertSame(['Below 60', '60–69.99', '70–79.99', '80–89.99', '90–100', 'No grade'], array_column($chart['columns'], 'label'));
        $this->assertSame([0, 1, 2, 0, 1, 1], array_column($chart['columns'], 'value'));
        $this->assertTrue($chart['columns'][5]['muted']);
    }

    public function test_examination_chart_counts_only_final_scores(): void
    {
        $exam = $this->makeExamination($this->offeringA1, 'Subject 1 Online Examination', ['attempt_limit' => 2, 'access_code' => 'EXAM-ACCESS-1']);
        $this->makeAttempt($exam, $this->candidateInA, ['submitted_at' => now()->subHours(3), 'earned_points' => 8, 'total_points' => 10, 'percentage' => 80]);
        $this->makeAttempt($exam, $this->candidateInA, ['attempt_number' => 2, 'result_status' => 'pending_review', 'earned_points' => null, 'percentage' => null, 'passed' => null]);

        [$chart] = $this->reportCharts($this->academicAdmin, ['type' => 'examination']);

        $this->assertSame([0, 0, 0, 1, 0], array_column($chart['columns'], 'value'));
        $this->assertStringContainsString('1 attempt has a final score; 1 awaiting essay grading', $chart['description']);
    }

    public function test_charts_follow_the_search_and_the_viewers_scope(): void
    {
        [$classChart] = $this->reportCharts($this->academicAdmin, ['type' => 'class', 'search' => 'batch b']);
        $this->assertSame(['Sample Batch B'], array_column($classChart['groups'], 'label'));

        // Instructor Alpha teaches only Subject 1 in Batch A.
        [$averages] = $this->reportCharts($this->alpha, ['type' => 'subject']);
        $this->assertSame(['Subject 1 · Sample Batch A'], array_column($averages['bars'], 'label'));
    }

    public function test_single_standing_lists_empty_reports_and_unscored_attempts_have_no_chart(): void
    {
        $exam = $this->makeExamination($this->offeringA1, 'Essay Examination', ['access_code' => 'EXAM-ACCESS-2']);
        $this->makeAttempt($exam, $this->candidateInA, ['result_status' => 'pending_review', 'earned_points' => null, 'percentage' => null, 'passed' => null]);

        $this->assertSame([], $this->reportCharts($this->academicAdmin, ['type' => 'examination']));
        $this->assertSame([], $this->reportCharts($this->academicAdmin, ['type' => 'failing']));
        $this->assertSame([], $this->reportCharts($this->academicAdmin, ['type' => 'candidate', 'search' => 'no such candidate']));
    }

    public function test_administrator_dashboard_has_grade_distribution_and_thresholds(): void
    {
        $overview = $this->dashboardProps($this->academicAdmin)['administratorOverview'];

        $this->assertSame([0, 1, 2, 0, 1, 1], array_column($overview['gradeDistribution'], 'value'));
        $this->assertEquals(['passingGrade' => 75.0, 'warningGrade' => 80.0], $overview['thresholds']);
    }

    public function test_instructor_dashboard_breaks_down_each_taught_subject_by_standing(): void
    {
        $alerts = $this->dashboardProps($this->alpha)['academicAlerts'];

        $this->assertEquals([[
            'classSubjectId' => $this->offeringA1->id,
            'classId' => $this->offeringA1->class_batch_id,
            'subject' => 'Subject 1',
            'classBatch' => 'Sample Batch A',
            'average' => 75.0,
            'counts' => ['passing' => 1, 'atRisk' => 0, 'failing' => 1, 'incomplete' => 0, 'noStanding' => 0],
        ]], $alerts['subjects']);

        // The institution-wide overview does not carry the per-subject breakdown.
        $this->assertArrayNotHasKey('subjects', $this->dashboardProps($this->academicAdmin)['academicOverview']);
    }

    /**
     * @param  array<string, mixed>  $query
     * @return list<array<string, mixed>>
     */
    private function reportCharts(User $user, array $query): array
    {
        return $this->actingAs($user)->get('/reports?'.http_build_query($query))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('staff/reports/index'))
            ->inertiaProps()['charts'];
    }

    /** @return array<string, mixed> */
    private function dashboardProps(User $user): array
    {
        return $this->actingAs($user)->get('/dashboard')->assertOk()->inertiaProps();
    }
}
