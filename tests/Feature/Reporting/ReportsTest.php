<?php

namespace Tests\Feature\Reporting;

use App\Enums\Permission as PermissionCode;
use App\Enums\SystemRole;
use App\Models\User;
use App\Services\Monitoring\AcademicMonitoring;
use App\Services\Monitoring\MonitoringScope;
use App\Services\ReportingService;
use Carbon\CarbonImmutable;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Milestone 15 reports (/reports, ReportingService): eight report types,
 * scoped by MonitoringScope, paginated (10) or saved as PDF (up to 5000 rows).
 */
class ReportsTest extends TestCase
{
    use BuildsReportingFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-09-10 08:00:00'));
        $this->buildReportingFixtures();
    }

    // ------------------------------------------------------------------
    // Access
    // ------------------------------------------------------------------

    public function test_administrators_and_instructors_can_open_reports_with_private_caching(): void
    {
        foreach ([$this->academicAdmin, $this->userWithRole(SystemRole::SuperAdministrator), $this->alpha] as $user) {
            $this->actingAs($user)->get('/reports')->assertOk()
                ->assertHeader('Cache-Control', 'no-store, private')
                ->assertInertia(fn (Assert $page) => $page->component('staff/reports/index')->where('filters.type', 'candidate'));
        }
    }

    public function test_candidates_guests_deactivated_users_and_staff_without_permission_are_denied(): void
    {
        $this->actingAs($this->candidateInA->user)->get('/reports')->assertForbidden();
        $this->actingAs($this->candidateInA->user)->get('/reports/pdf?type=examination')->assertForbidden();

        $noReports = $this->staffWithPermissions('monitoring_only', [PermissionCode::ViewAcademicMonitoring, PermissionCode::ViewAllCandidates]);
        $this->actingAs($noReports)->get('/reports')->assertForbidden();
        $this->actingAs($noReports)->get('/reports/pdf')->assertForbidden();

        $inactive = User::factory()->withRole(SystemRole::AcademicAdministrator)->create(['is_active' => false]);
        $this->actingAs($inactive)->get('/reports')->assertRedirect('/login');

        auth()->logout();
        $this->get('/reports')->assertRedirect('/login');
    }

    public function test_a_reports_viewer_without_candidate_scope_sees_nothing(): void
    {
        $viewer = $this->staffWithPermissions('report_viewer', [PermissionCode::ViewReports]);

        foreach (array_keys(ReportingService::TYPES) as $type) {
            $props = $this->reportProps($viewer, ['type' => $type]);
            $this->assertSame('none', $props['scope']);
            $this->assertSame([], $props['periods']);
            if ($type !== 'distribution') {
                $this->assertSame(0, $props['rows']['total'], $type);
            } else {
                $this->assertSame(0, array_sum(array_column($props['rows']['data'], 'count')));
            }
        }
    }

    public function test_former_instructors_keep_no_report_scope_from_leftover_assignments(): void
    {
        $this->buildAttempts();
        $reportsOnly = $this->staffWithPermissions('report_reader', [PermissionCode::ViewReports]);
        // Alpha keeps the Batch A assignment but no longer holds a teaching role.
        $this->alpha->role()->associate($reportsOnly->role)->save();
        $alpha = $this->alpha->fresh();

        foreach (['candidate', 'examination', 'subject'] as $type) {
            $props = $this->reportProps($alpha, ['type' => $type, 'period' => '']);
            $this->assertSame('none', $props['scope']);
            $this->assertSame(0, $props['rows']['total'], $type);
        }
        $this->actingAs($alpha)->get('/reports?period='.$this->activePeriod->id)->assertForbidden();
    }

    // ------------------------------------------------------------------
    // Report types
    // ------------------------------------------------------------------

    #[DataProvider('reportTypes')]
    public function test_every_report_type_renders_for_administrators_and_instructors(string $type): void
    {
        foreach ([$this->academicAdmin, $this->bravo] as $user) {
            $props = $this->reportProps($user, ['type' => $type]);
            $this->assertSame($type, $props['filters']['type']);
            $this->assertNotEmpty($props['columns']);
            $this->assertSame(ReportingService::TYPES, $props['types']);
            foreach ($props['rows']['data'] as $row) {
                $this->assertSame([], array_diff(array_keys($row), array_keys($props['columns'])), 'Rows only carry displayed columns.');
            }
        }
    }

    /**
     * @return array<string, array{string}>
     */
    public static function reportTypes(): array
    {
        return array_combine(array_keys(ReportingService::TYPES), array_map(fn (string $type): array => [$type], array_keys(ReportingService::TYPES)));
    }

    public function test_candidate_standing_report_matches_the_monitoring_engine(): void
    {
        $rows = $this->reportProps($this->academicAdmin)['rows']['data'];

        $this->assertSame([
            [$this->candidateInA->candidate_number, 'Sample Batch A', 'Failing', 70.0, 0, 'Yes'],
            [$this->secondInA->candidate_number, 'Sample Batch A', 'Failing', 60.0, 1, 'Yes'],
            [$this->candidateInB->candidate_number, 'Sample Batch B', 'At Risk', 77.0, 0, 'No'],
        ], array_map(fn (array $row): array => [$row['number'], $row['classBatch'], $row['standing'], (float) $row['lowest'], $row['missing'], $row['provisional']], $rows));

        $monitoring = $this->app->make(AcademicMonitoring::class);
        $engine = $monitoring->evaluate(MonitoringScope::for($this->academicAdmin)->offerings($this->activePeriod->id)->get());
        $this->assertSame(
            array_map(fn ($entry): string => $entry->overall->standing->label(), $engine),
            array_column($rows, 'standing'),
        );
    }

    public function test_at_risk_and_failing_reports_list_only_those_standings(): void
    {
        $atRisk = $this->reportProps($this->academicAdmin, ['type' => 'at_risk'])['rows']['data'];
        $failing = $this->reportProps($this->academicAdmin, ['type' => 'failing'])['rows']['data'];

        $this->assertSame([$this->candidateInB->candidate_number], array_column($atRisk, 'number'));
        $this->assertSame([$this->candidateInA->candidate_number, $this->secondInA->candidate_number], array_column($failing, 'number'));
    }

    public function test_class_report_counts_standings_per_class(): void
    {
        $rows = $this->reportProps($this->academicAdmin, ['type' => 'class'])['rows']['data'];

        $this->assertEquals([
            ['classBatch' => 'Sample Batch A', 'monitored' => 2, 'failing' => 2, 'atRisk' => 0, 'incomplete' => 0, 'passing' => 0, 'noStanding' => 0],
            ['classBatch' => 'Sample Batch B', 'monitored' => 1, 'failing' => 0, 'atRisk' => 1, 'incomplete' => 0, 'passing' => 0, 'noStanding' => 0],
        ], $rows);
    }

    public function test_subject_report_uses_engine_averages_without_internal_ids(): void
    {
        $rows = $this->reportProps($this->academicAdmin, ['type' => 'subject'])['rows']['data'];

        $this->assertSame([['Sample Batch A', 'Subject 1'], ['Sample Batch A', 'Subject 2'], ['Sample Batch B', 'Subject 1']],
            array_map(fn (array $row): array => [$row['classBatch'], $row['subject']], $rows));
        $this->assertEquals([75.0, 70.0, 77.0], array_column($rows, 'average'));
        $this->assertSame([2, 2, 1], array_column($rows, 'candidateCount'));
        $this->assertSame([1, 0, 0], array_column($rows, 'passing'));
        $this->assertSame([1, 1, 0], array_column($rows, 'failing'));
        $this->assertSame([0, 1, 0], array_column($rows, 'incomplete'));
        foreach ($rows as $row) {
            $this->assertArrayNotHasKey('id', $row);
            $this->assertArrayNotHasKey('classId', $row);
        }
    }

    public function test_distribution_report_counts_every_candidate_subject_record_once(): void
    {
        $rows = $this->reportProps($this->academicAdmin, ['type' => 'distribution'])['rows']['data'];

        $this->assertSame([
            ['band' => '90–100', 'count' => 1], ['band' => '80–89.99', 'count' => 0], ['band' => '70–79.99', 'count' => 2],
            ['band' => '60–69.99', 'count' => 1], ['band' => 'Below 60', 'count' => 0], ['band' => 'No grade', 'count' => 1],
        ], $rows);
    }

    // ------------------------------------------------------------------
    // Examination and quiz reports
    // ------------------------------------------------------------------

    public function test_each_attempt_is_its_own_row_and_pending_scores_stay_pending(): void
    {
        $this->buildAttempts();
        $props = $this->reportProps($this->academicAdmin, ['type' => 'examination']);
        $rows = $props['rows']['data'];

        $this->assertSame(3, $props['rows']['total']);
        // Newest submission first; the in-progress attempt is not reported.
        $this->assertSame([[$this->candidateInA->candidate_number, 2], [$this->candidateInA->candidate_number, 1], [$this->secondInA->candidate_number, 1]],
            array_map(fn (array $row): array => [$row['number'], $row['attempt']], $rows));

        $this->assertSame('Awaiting essay grading', $rows[0]['status']);
        $this->assertNull($rows[0]['score']);
        $this->assertNull($rows[0]['percentage']);
        $this->assertSame('Pending / not configured', $rows[0]['result']);

        $this->assertSame('Graded', $rows[1]['status']);
        $this->assertSame('8.00 / 10.00', $rows[1]['score']);
        $this->assertEquals(80, $rows[1]['percentage']);
        $this->assertSame('Passed', $rows[1]['result']);

        $this->assertSame('Expired', $rows[2]['status']);
        $this->assertNull($rows[2]['score']);

        $this->assertStringNotContainsString('synthetic-', json_encode($props));
        $this->assertStringNotContainsString('EXAM-ACCESS', json_encode($props));
    }

    public function test_quiz_and_examination_reports_are_separate(): void
    {
        $this->buildAttempts();

        $quiz = $this->reportProps($this->academicAdmin, ['type' => 'quiz'])['rows']['data'];

        $this->assertSame([$this->candidateInB->candidate_number], array_column($quiz, 'number'));
        $this->assertSame('Batch B Online Quiz', $quiz[0]['examination']);
    }

    public function test_instructors_only_see_attempts_of_the_subjects_they_teach(): void
    {
        $this->buildAttempts();

        $this->assertSame(3, $this->reportProps($this->alpha, ['type' => 'examination'])['rows']['total']);
        $this->assertSame(0, $this->reportProps($this->alpha, ['type' => 'quiz'])['rows']['total']);
        $this->assertSame(0, $this->reportProps($this->bravo, ['type' => 'examination'])['rows']['total']);
        $this->assertSame(1, $this->reportProps($this->bravo, ['type' => 'quiz'])['rows']['total']);
    }

    public function test_date_filters_use_institution_day_boundaries(): void
    {
        config(['institution.timezone' => 'Asia/Manila']);
        $exam = $this->makeExamination($this->offeringA1, 'Boundary Exam', []);
        // 23:30 on 9 September and 00:30 on 10 September in Manila.
        $this->makeAttempt($exam, $this->candidateInA, ['submitted_at' => '2026-09-09 15:30:00']);
        $this->makeAttempt($exam, $this->secondInA, ['submitted_at' => '2026-09-09 16:30:00']);

        $ninth = $this->reportProps($this->academicAdmin, ['type' => 'examination', 'from' => '2026-09-09', 'to' => '2026-09-09'])['rows']['data'];
        $tenth = $this->reportProps($this->academicAdmin, ['type' => 'examination', 'from' => '2026-09-10'])['rows']['data'];
        $untilTenth = $this->reportProps($this->academicAdmin, ['type' => 'examination', 'to' => '2026-09-10'])['rows']['data'];

        $this->assertSame([$this->candidateInA->candidate_number], array_column($ninth, 'number'));
        $this->assertSame('2026-09-09 23:30', $ninth[0]['submitted']);
        $this->assertSame([$this->secondInA->candidate_number], array_column($tenth, 'number'));
        $this->assertCount(2, $untilTenth);
    }

    // ------------------------------------------------------------------
    // Scope and filters
    // ------------------------------------------------------------------

    public function test_instructor_reports_cover_only_taught_offerings(): void
    {
        $props = $this->reportProps($this->alpha);

        $this->assertSame('taught', $props['scope']);
        $this->assertSame([['id' => $this->batchA->id, 'name' => 'Sample Batch A']], $props['classes']);
        $this->assertSame([['id' => $this->offeringA1->subject_id, 'name' => 'Subject 1']], $props['subjects']);
        // Standings over Subject 1 only: A1 90 Passing, A2 60 Failing. B1 is not visible.
        $this->assertSame([
            [$this->candidateInA->candidate_number, 'Passing'],
            [$this->secondInA->candidate_number, 'Failing'],
        ], array_map(fn (array $row): array => [$row['number'], $row['standing']], $props['rows']['data']));
    }

    public function test_tampered_class_and_subject_filters_can_only_narrow(): void
    {
        // Batch B and Subject 2 exist, but Alpha does not teach them.
        foreach (['candidate', 'class', 'subject', 'failing', 'at_risk'] as $type) {
            $this->assertSame(0, $this->reportProps($this->alpha, ['type' => $type, 'class' => $this->batchB->id])['rows']['total'], $type);
            $this->assertSame(0, $this->reportProps($this->alpha, ['type' => $type, 'subject' => $this->offeringA2->subject_id, 'class' => $this->batchA->id])['rows']['total'], $type);
        }
        $this->assertSame(0, $this->reportProps($this->alpha, ['type' => 'candidate', 'class' => 999999])['rows']['total']);

        // Filters narrow administrator reports too.
        $rows = $this->reportProps($this->academicAdmin, ['class' => $this->batchB->id])['rows']['data'];
        $this->assertSame([$this->candidateInB->candidate_number], array_column($rows, 'number'));
        $subject = $this->reportProps($this->academicAdmin, ['type' => 'subject', 'subject' => $this->offeringA2->subject_id])['rows']['data'];
        $this->assertSame(['Subject 2'], array_column($subject, 'subject'));
    }

    public function test_periods_outside_the_scope_are_refused(): void
    {
        // Bravo teaches nothing in the past period.
        $this->actingAs($this->bravo)->get('/reports?period='.$this->pastPeriod->id)->assertForbidden();
        $this->actingAs($this->academicAdmin)->get('/reports?period=999999')->assertForbidden();

        // Alpha teaches Batch Old in the past period.
        $props = $this->reportProps($this->alpha, ['period' => $this->pastPeriod->id]);
        $this->assertSame([['id' => $this->batchOld->id, 'name' => 'Sample Batch Old']], $props['classes']);
    }

    public function test_search_narrows_rows_case_insensitively_within_the_scope(): void
    {
        $rows = $this->reportProps($this->academicAdmin, ['search' => 'sample batch b'])['rows']['data'];
        $this->assertSame([$this->candidateInB->candidate_number], array_column($rows, 'number'));

        $subject = $this->reportProps($this->academicAdmin, ['type' => 'subject', 'search' => 'SUBJECT 2'])['rows']['data'];
        $this->assertSame(['Subject 2'], array_column($subject, 'subject'));

        // An instructor cannot find candidates outside their subjects by searching.
        $this->assertSame(0, $this->reportProps($this->alpha, ['search' => $this->candidateInB->candidate_number])['rows']['total']);
        $this->assertSame(0, $this->reportProps($this->academicAdmin, ['search' => 'no-such-candidate'])['rows']['total']);
    }

    // ------------------------------------------------------------------
    // Pagination and PDF
    // ------------------------------------------------------------------

    public function test_reports_are_paginated_by_ten(): void
    {
        for ($index = 1; $index <= 15; $index++) {
            $this->makeCandidate($this->batchB, sprintf('%03d', $index));
        }

        $first = $this->reportProps($this->academicAdmin, ['type' => 'candidate'])['rows'];
        $second = $this->reportProps($this->academicAdmin, ['page' => 2])['rows'];
        $beyond = $this->reportProps($this->academicAdmin, ['page' => 99])['rows'];

        $this->assertSame(18, $first['total']);
        $this->assertSame(10, $first['per_page']);
        $this->assertCount(10, $first['data']);
        $this->assertCount(8, $second['data']);
        $this->assertSame([], array_intersect(array_column($first['data'], 'number'), array_column($second['data'], 'number')));
        $this->assertSame([], $beyond['data']);
        // Page links keep the filters.
        $this->assertStringContainsString('type=candidate', $first['next_page_url']);
        $this->assertStringContainsString('page=2', $first['next_page_url']);
    }

    public function test_save_as_pdf_downloads_the_filtered_report_as_a_watermarked_pdf(): void
    {
        for ($index = 1; $index <= 30; $index++) {
            $this->makeCandidate($this->batchB, sprintf('%03d', $index));
        }

        config(['institution.logo_url' => '/branding/logo.jpg']);
        $response = $this->actingAs($this->academicAdmin)->get('/reports/pdf?type=candidate&page=2')->assertOk()
            ->assertHeader('Content-Type', 'application/pdf')
            ->assertHeader('Cache-Control', 'no-store, private');

        $this->assertStringStartsWith('attachment; filename="report-candidate-academic-standing', (string) $response->headers->get('Content-Disposition'));
        $this->assertStringStartsWith('%PDF-', (string) $response->getContent());
        // The school logo is on every page at 20% opacity.
        $this->assertStringContainsString('/CA 0.2', (string) $response->getContent());
    }

    public function test_save_as_pdf_keeps_the_instructors_scope_and_validates_filters(): void
    {
        $this->actingAs($this->alpha)->get('/reports/pdf?type=examination')->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->actingAs($this->alpha)->from('/reports')->get('/reports/pdf?type=grades_export')->assertRedirect('/reports')->assertSessionHasErrors('type');
    }

    // ------------------------------------------------------------------
    // Invalid parameters
    // ------------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $query
     */
    #[DataProvider('invalidParameters')]
    public function test_invalid_parameters_are_validation_errors_not_server_errors(array $query, string $field): void
    {
        $this->actingAs($this->academicAdmin)->from('/reports')->get('/reports?'.http_build_query($query))
            ->assertRedirect('/reports')->assertSessionHasErrors($field);
    }

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function invalidParameters(): array
    {
        return [
            'unknown type' => [['type' => 'grades_export'], 'type'],
            'type array' => [['type' => ['candidate']], 'type'],
            'text period' => [['period' => 'current'], 'period'],
            'negative class' => [['class' => -1], 'class'],
            'zero subject' => [['subject' => 0], 'subject'],
            'period array' => [['period' => [1, 2]], 'period'],
            'search array' => [['search' => ['x']], 'search'],
            'search too long' => [['search' => str_repeat('a', 101)], 'search'],
            'bad from date' => [['from' => '2026/09/01'], 'from'],
            'impossible date' => [['from' => '2026-02-30'], 'from'],
            'to before from' => [['from' => '2026-09-10', 'to' => '2026-09-01'], 'to'],
            'zero page' => [['page' => 0], 'page'],
            'text page' => [['page' => 'last'], 'page'],
        ];
    }

    public function test_unknown_parameters_are_ignored(): void
    {
        $props = $this->reportProps($this->alpha, ['instructor_id' => $this->bravo->id, 'scope' => 'all', 'candidate_id' => $this->candidateInB->id]);

        $this->assertSame('taught', $props['scope']);
        $this->assertNotContains($this->candidateInB->candidate_number, array_column($props['rows']['data'], 'number'));
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /**
     * Examination on Batch A Subject 1 (Alpha): A1 attempt 1 graded, A1
     * attempt 2 awaiting essay grading, A2 expired, withdrawn A3 in progress.
     * Quiz on Batch B Subject 1 (Bravo): B1 graded.
     */
    private function buildAttempts(): void
    {
        $exam = $this->makeExamination($this->offeringA1, 'Subject 1 Online Examination', ['access_code' => 'EXAM-ACCESS-1']);
        $this->makeAttempt($exam, $this->candidateInA, ['submitted_at' => now()->subHours(3)]);
        $this->makeAttempt($exam, $this->candidateInA, ['attempt_number' => 2, 'submitted_at' => now()->subHour(), 'result_status' => 'pending_review', 'earned_points' => null, 'total_points' => 10, 'percentage' => null, 'passed' => null]);
        $this->makeAttempt($exam, $this->secondInA, ['status' => 'expired', 'submitted_at' => now()->subHours(4), 'result_status' => null, 'earned_points' => null, 'total_points' => null, 'percentage' => null, 'passed' => null]);
        $this->makeAttempt($exam, $this->withdrawnInA, ['status' => 'in_progress', 'submitted_at' => null, 'result_status' => null]);

        $quiz = $this->makeExamination($this->offeringB1, 'Batch B Online Quiz', ['kind' => 'quiz']);
        $this->makeAttempt($quiz, $this->candidateInB);
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    private function reportProps(User $user, array $query = []): array
    {
        return $this->reportResponse($user, $query)->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('staff/reports/index'))
            ->inertiaProps();
    }

    /**
     * @param  array<string, mixed>  $query
     */
    private function reportResponse(User $user, array $query): TestResponse
    {
        return $this->actingAs($user)->get('/reports'.($query === [] ? '' : '?'.http_build_query($query)));
    }
}
