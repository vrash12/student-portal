<?php

namespace Tests\Feature\Reporting;

use App\Enums\SystemRole;
use App\Models\Candidate;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Candidate portal pages added after Milestone 16: the portal home
 * (CandidateHomeService), the own profile, the own photo and the PDF record
 * downloads. The identity always comes from the signed-in account.
 * Complements tests/Feature/Candidates/CandidateProfileTest.php and
 * CandidatePdfTest.php.
 */
class CandidatePortalPagesTest extends TestCase
{
    use BuildsReportingFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-09-10 08:00:00'));
    }

    // ------------------------------------------------------------------
    // Portal home
    // ------------------------------------------------------------------

    public function test_home_for_a_candidate_without_a_class_is_empty_but_works(): void
    {
        $this->buildReportingFixtures();
        $this->makeExamination($this->offeringA1, 'Batch A Exam');
        $candidate = $this->makeCandidate(null, '900');

        $props = $this->homeProps($candidate->user);

        $this->assertSame($candidate->full_name, $props['summary']['name']);
        $this->assertNull($props['summary']['className']);
        $this->assertNull($props['summary']['period']);
        $this->assertFalse($props['summary']['eligible']);
        $this->assertSame(0, $props['summary']['subjectCount']);
        $this->assertSame(0, $props['available']['total']);
        $this->assertSame(0, $props['upcoming']['total']);
        $this->assertSame(0, $props['sections']['grades']['subjectCount']);
        $this->assertSame(0, $props['sections']['grades']['outstandingCount']);
        $this->assertNull($props['sections']['fitness']);
        $this->assertSame(0, $this->pageProps($candidate->user, '/portal/grades', 'portal/grades')['outstanding']['total']);
        $this->assertSame([], $this->pageProps($candidate->user, '/portal/examinations', 'portal/examinations/index')['scoreTrend']);
    }

    public function test_home_lists_only_open_published_examinations_of_the_own_class(): void
    {
        $this->buildReportingFixtures();
        $open = $this->makeExamination($this->offeringA1, 'Open Exam', ['opens_at' => now()->subHour(), 'closes_at' => now()->addHour(), 'access_code' => 'PORTAL-SECRET-CODE']);
        $unscheduled = $this->makeExamination($this->offeringA2, 'Unscheduled Exam');
        $upcoming = $this->makeExamination($this->offeringA1, 'Upcoming Exam', ['opens_at' => now()->addDay()]);
        $this->makeExamination($this->offeringA1, 'Draft Exam', ['status' => 'draft']);
        $this->makeExamination($this->offeringA1, 'Archived Exam', ['status' => 'archived']);
        $this->makeExamination($this->offeringA1, 'Closed Exam', ['closes_at' => now()->subMinute()]);
        $this->makeExamination($this->offeringB1, 'Batch B Exam');

        $props = $this->homeProps($this->candidateInA->user);

        // Closing soonest first; no closing time last.
        $this->assertSame([$open->id, $unscheduled->id], array_column($props['available']['data'], 'id'));
        $this->assertSame([$upcoming->id], array_column($props['upcoming']['data'], 'id'));
        $this->assertSame(['id', 'title', 'kind', 'subject', 'durationMinutes', 'opensAt', 'closesAt', 'attemptsUsed', 'resumeId'], array_keys($props['available']['data'][0]));
        $this->assertStringNotContainsString('PORTAL-SECRET-CODE', json_encode($props));
        $this->assertTrue($props['summary']['eligible']);
        $this->assertSame('Sample Batch A', $props['summary']['className']);
        $this->assertSame(2, $props['summary']['subjectCount']);
    }

    public function test_home_counts_own_attempts_and_offers_resume_only_for_unexpired_attempts(): void
    {
        $this->buildReportingFixtures();
        $resumable = $this->makeExamination($this->offeringA1, 'Resumable Exam', []);
        $stale = $this->makeExamination($this->offeringA2, 'Stale Exam');
        $attempt = $this->makeAttempt($resumable, $this->candidateInA, ['status' => 'in_progress', 'submitted_at' => null, 'result_status' => null]);
        $this->makeAttempt($stale, $this->candidateInA, ['status' => 'in_progress', 'submitted_at' => null, 'result_status' => null, 'expires_at' => now()->subMinute()]);
        // Another candidate's attempt does not count.
        $this->makeAttempt($resumable, $this->secondInA);

        $exams = collect($this->homeProps($this->candidateInA->user)['available']['data'])->keyBy('id');

        $this->assertSame(1, $exams[$resumable->id]['attemptsUsed']);
        $this->assertSame($attempt->id, $exams[$resumable->id]['resumeId']);
        $this->assertNull($exams[$stale->id]['resumeId']);

        $other = collect($this->homeProps($this->secondInA->user)['available']['data'])->keyBy('id');
        $this->assertNull($other[$resumable->id]['resumeId']);
    }

    public function test_withdrawn_candidates_see_no_examinations_or_outstanding_work(): void
    {
        $this->buildReportingFixtures();
        $this->makeExamination($this->offeringA1, 'Open Exam');

        $props = $this->homeProps($this->withdrawnInA->user);

        $this->assertFalse($props['summary']['eligible']);
        $this->assertSame(0, $props['available']['total']);
        $this->assertSame(0, $props['sections']['grades']['outstandingCount']);
        $this->assertSame(0, $this->pageProps($this->withdrawnInA->user, '/portal/grades', 'portal/grades')['outstanding']['total']);
    }

    public function test_score_chart_shows_only_released_graded_results_of_the_candidate(): void
    {
        $this->buildReportingFixtures();
        $released = $this->makeExamination($this->offeringA1, 'Released Exam', ['release_results' => true]);
        $unreleased = $this->makeExamination($this->offeringA2, 'Unreleased Exam');
        $this->makeAttempt($released, $this->candidateInA, ['submitted_at' => now()->subHour()]);
        $this->makeAttempt($released, $this->candidateInA, ['attempt_number' => 2, 'result_status' => 'pending_review', 'earned_points' => null, 'percentage' => null, 'passed' => null]);
        $this->makeAttempt($unreleased, $this->candidateInA);
        $this->makeAttempt($released, $this->secondInA, ['percentage' => 40, 'earned_points' => 4, 'passed' => false]);

        $props = $this->pageProps($this->candidateInA->user, '/portal/examinations', 'portal/examinations/index');

        $this->assertCount(1, $props['scoreTrend']);
        $this->assertSame('Released Exam', $props['scoreTrend'][0]['title']);
        $this->assertSame(1, $props['scoreTrend'][0]['attemptNumber']);
        $this->assertEquals(80, $props['scoreTrend'][0]['percentage']);
        $this->assertTrue($props['scoreTrend'][0]['passed']);
        $this->assertStringNotContainsString('Unreleased Exam', json_encode($props['scoreTrend']));
        $this->assertStringNotContainsString('synthetic-', json_encode($props));
        $this->assertSame(1, $this->homeProps($this->candidateInA->user)['sections']['examinations']['releasedCount']);
    }

    public function test_score_chart_shows_the_latest_ten_results_oldest_first(): void
    {
        $this->buildReportingFixtures();
        $exam = $this->makeExamination($this->offeringA1, 'Practice Exam', ['release_results' => true]);
        for ($number = 1; $number <= 12; $number++) {
            $this->makeAttempt($exam, $this->candidateInA, ['attempt_number' => $number, 'submitted_at' => now()->subMinutes(100 - $number)]);
        }

        $trend = $this->pageProps($this->candidateInA->user, '/portal/examinations', 'portal/examinations/index')['scoreTrend'];

        $this->assertSame([3, 4, 5, 6, 7, 8, 9, 10, 11, 12], array_column($trend, 'attemptNumber'));
    }

    public function test_outstanding_work_lists_finalized_assessments_without_the_candidates_score(): void
    {
        $this->buildReportingFixtures();
        // A2 has no score on Subject 2 Examination; a draft assessment is not outstanding.
        $this->createAssessment($this->quizzes, 'Draft Quiz', '100');

        $a2 = $this->pageProps($this->secondInA->user, '/portal/grades', 'portal/grades')['outstanding'];
        $a1 = $this->pageProps($this->candidateInA->user, '/portal/grades', 'portal/grades')['outstanding'];
        $this->assertSame(1, $this->homeProps($this->secondInA->user)['sections']['grades']['outstandingCount']);

        $this->assertSame(['Subject 2 Examination'], array_column($a2['data'], 'title'));
        $this->assertSame(['id', 'title', 'subject', 'category', 'date'], array_keys($a2['data'][0]));
        $this->assertSame(0, $a1['total']);
    }

    public function test_home_shows_the_first_few_and_the_examinations_page_pages_every_list(): void
    {
        $this->buildReportingFixtures();
        for ($index = 1; $index <= 12; $index++) {
            $this->makeExamination($this->offeringA1, "Exam {$index}", ['closes_at' => now()->addHours($index)]);
        }
        for ($index = 1; $index <= 12; $index++) {
            $this->makeExamination($this->offeringA1, "Later Exam {$index}", ['opens_at' => now()->addDays($index)]);
        }

        // Home: the first four open (closing soonest first) and the next three scheduled.
        $home = $this->homeProps($this->candidateInA->user);
        $this->assertSame(['Exam 1', 'Exam 2', 'Exam 3', 'Exam 4'], array_column($home['available']['data'], 'title'));
        $this->assertSame(12, $home['available']['total']);
        $this->assertCount(3, $home['upcoming']['data']);
        $this->assertSame(12, $home['sections']['examinations']['openCount']);

        $url = '/portal/examinations';
        $first = $this->pageProps($this->candidateInA->user, $url, 'portal/examinations/index');
        $this->assertCount(10, $first['available']['data']);
        $this->assertCount(10, $first['upcoming']['data']);

        $second = $this->pageProps($this->candidateInA->user, $url.'?available_page=2', 'portal/examinations/index');
        $this->assertSame(['Exam 11', 'Exam 12'], array_column($second['available']['data'], 'title'));
        // Only the requested list moves.
        $this->assertSame(1, $second['upcoming']['current_page']);
        $this->assertStringContainsString('available_page=2', $second['upcoming']['next_page_url']);

        foreach (['abc', '-3', '0', '999'] as $page) {
            $props = $this->pageProps($this->candidateInA->user, $url.'?'.http_build_query(['available_page' => $page, 'upcoming_page' => $page, 'exams_page' => $page]), 'portal/examinations/index');
            $this->assertSame(12, $props['available']['total'], "page {$page}");
            $this->actingAs($this->candidateInA->user)->get('/portal/grades?'.http_build_query(['outstanding_page' => $page, 'assessments_page' => $page]))->assertOk();
        }
    }

    public function test_home_identity_comes_from_the_signed_in_account(): void
    {
        $this->buildReportingFixtures();
        $this->makeExamination($this->offeringB1, 'Batch B Exam');

        $props = $this->homeProps($this->candidateInA->user, ['candidate_id' => $this->candidateInB->id, 'candidate' => $this->candidateInB->id]);

        $this->assertSame($this->candidateInA->candidate_number, $props['summary']['number']);
        $this->assertSame(0, collect($props['available']['data'])->where('title', 'Batch B Exam')->count());
    }

    // ------------------------------------------------------------------
    // Portal access
    // ------------------------------------------------------------------

    public function test_staff_guests_and_deactivated_candidates_cannot_open_portal_pages(): void
    {
        $candidate = Candidate::factory()->create();
        $candidate->user->forceFill(['is_active' => false])->save();
        $instructor = $this->userWithRole(SystemRole::Instructor);
        $admin = $this->userWithRole(SystemRole::AcademicAdministrator);
        $pages = ['/portal', '/portal/examinations', '/portal/grades', '/portal/performance', '/portal/fitness', '/portal/profile', '/portal/profile/photo', '/portal/profile/documents/registration', '/portal/profile/documents/academic'];

        foreach ($pages as $page) {
            $this->actingAs($instructor)->get($page)->assertForbidden();
            $this->actingAs($admin)->get($page)->assertForbidden();
            $this->actingAs($candidate->user->fresh())->get($page)->assertRedirect('/login');
            auth()->logout();
            $this->get($page)->assertRedirect('/login');
        }
    }

    public function test_a_candidate_account_without_a_candidate_record_gets_not_found(): void
    {
        $orphan = $this->userWithRole(SystemRole::Candidate);

        foreach (['/portal', '/portal/examinations', '/portal/grades', '/portal/performance', '/portal/fitness', '/portal/profile', '/portal/profile/photo', '/portal/profile/documents/registration'] as $page) {
            $this->actingAs($orphan)->get($page)->assertNotFound();
        }
    }

    // ------------------------------------------------------------------
    // Own profile
    // ------------------------------------------------------------------

    public function test_profile_of_a_candidate_without_a_class(): void
    {
        $candidate = $this->makeCandidate(null, '901');

        $this->actingAs($candidate->user)->get('/portal/profile')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('portal/profile')
                ->where('candidate.id', $candidate->id)
                ->where('candidate.classBatch', null)
                ->where('candidate.photoUrl', null)
                // Personal details only: grades and results have their own pages.
                ->missing('academics')
                ->missing('examinationResults'));
        $this->actingAs($candidate->user)->get('/portal/grades')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('portal/grades')
                ->where('academics.subjects', [])
                ->where('thresholds', null)
                ->where('assessmentHistory.total', 0));
        $this->actingAs($candidate->user)->get('/portal/examinations')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('portal/examinations/index')->where('results.total', 0));
    }

    public function test_results_over_time_are_the_own_results_of_the_current_class_by_subject(): void
    {
        $this->buildReportingFixtures();

        $first = $this->pageProps($this->candidateInA->user, '/portal/grades', 'portal/grades')['resultsBySubject'];
        $this->assertSame(['Subject 1', 'Subject 2'], array_column(array_column($first, 'subject'), 'name'));
        $this->assertSame(['2026-08-20'], array_column($first[0]['assessments'], 'date'));
        $this->assertEquals([90], array_column($first[0]['assessments'], 'percentage'));
        $this->assertEquals(70, $first[1]['assessments'][0]['percentage']);
        $this->assertStringNotContainsString('Batch B Quiz', json_encode($first));

        // A missing score stays missing (never 0); another candidate's scores are not included.
        $second = $this->pageProps($this->secondInA->user, '/portal/grades', 'portal/grades')['resultsBySubject'];
        $this->assertEquals(60, $second[0]['assessments'][0]['percentage']);
        $this->assertNull($second[1]['assessments'][0]['percentage']);
        $this->assertNull($second[1]['assessments'][0]['score']);

        $this->assertSame([], $this->pageProps($this->makeCandidate(null, '902')->user, '/portal/grades', 'portal/grades')['resultsBySubject']);
    }

    public function test_grades_and_results_show_own_scores_only_and_pending_results_as_pending(): void
    {
        $this->buildReportingFixtures();
        $exam = $this->makeExamination($this->offeringA1, 'Essay Exam', ['release_results' => true]);
        $this->makeAttempt($exam, $this->candidateInA, ['result_status' => 'pending_review', 'earned_points' => 6, 'percentage' => 60, 'passed' => false]);

        $profile = $this->actingAs($this->candidateInA->user)->get('/portal/profile?candidate_id='.$this->secondInA->id)->assertOk()->inertiaProps();
        $this->assertSame($this->candidateInA->id, $profile['candidate']['id']);
        $exams = $this->pageProps($this->candidateInA->user, '/portal/examinations?candidate_id='.$this->secondInA->id, 'portal/examinations/index');
        $props = $this->pageProps($this->candidateInA->user, '/portal/grades?candidate_id='.$this->secondInA->id, 'portal/grades');

        $result = $exams['results']['data'][0];
        $this->assertSame('Awaiting review', $result['resultLabel']);
        $this->assertNull($result['score']);
        $this->assertNull($result['percentage']);
        $this->assertNull($result['passed']);
        $history = collect($props['assessmentHistory']['data'])->keyBy('title');
        $this->assertEquals(90, $history['Quiz 1']['score']);
        $this->assertEquals(70, $history['Subject 2 Examination']['score']);
        $this->assertStringNotContainsString('synthetic-', json_encode($props));
        $this->assertStringNotContainsString('Batch B Quiz', json_encode($props));
    }

    public function test_profile_pagination_parameters_page_each_list_and_tolerate_bad_values(): void
    {
        $this->buildReportingFixtures();
        for ($index = 1; $index <= 16; $index++) {
            $this->travel(1)->minutes();
            $assessment = $this->createAssessment($this->quizzes, "Extra Quiz {$index}", '100');
            $this->recordScores($assessment, [$this->candidateInA->id => '85']);
            $this->finalize($assessment);
        }

        $user = $this->candidateInA->user;
        $first = $this->pageProps($user, '/portal/grades', 'portal/grades');
        $second = $this->pageProps($user, '/portal/grades?assessments_page=2', 'portal/grades');

        // 16 extra quizzes plus Quiz 1 and Subject 2 Examination of Batch A.
        $this->assertSame(18, $first['assessmentHistory']['total']);
        $this->assertCount(10, $first['assessmentHistory']['data']);
        $this->assertCount(8, $second['assessmentHistory']['data']);
        $this->assertSame('Extra Quiz 16', $first['assessmentHistory']['data'][0]['title']);

        foreach (['abc', '-1', '0', '500'] as $page) {
            $this->actingAs($user)->get("/portal/grades?assessments_page={$page}")->assertOk();
            $this->actingAs($user)->get("/portal/examinations?exams_page={$page}")->assertOk();
        }
    }

    // ------------------------------------------------------------------
    // Photo and documents
    // ------------------------------------------------------------------

    public function test_own_photo_is_not_found_without_a_photo_or_a_stored_file(): void
    {
        Storage::fake('local');
        $candidate = Candidate::factory()->create();

        $this->actingAs($candidate->user)->get('/portal/profile/photo')->assertNotFound();

        $candidate->forceFill(['profile_photo_path' => 'candidate-photos/missing.png'])->save();
        $this->actingAs($candidate->user)->get('/portal/profile/photo')->assertNotFound();
    }

    public function test_own_photo_is_always_the_signed_in_candidates(): void
    {
        Storage::fake('local');
        $own = Candidate::factory()->create();
        $other = Candidate::factory()->create();
        Storage::disk('local')->put('candidate-photos/own.png', UploadedFile::fake()->image('own.png', 10, 10)->getContent());
        Storage::disk('local')->put('candidate-photos/other.png', UploadedFile::fake()->image('other.png', 20, 20)->getContent());
        $own->forceFill(['profile_photo_path' => 'candidate-photos/own.png'])->save();
        $other->forceFill(['profile_photo_path' => 'candidate-photos/other.png'])->save();

        $response = $this->actingAs($own->user)->get('/portal/profile/photo?candidate_id='.$other->id)->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private');

        $this->assertSame(Storage::disk('local')->path('candidate-photos/own.png'), $response->baseResponse->getFile()->getPathname());
        $this->actingAs($own->user)->get("/candidates/{$other->id}/photo")->assertForbidden();
        $this->actingAs($own->user)->get("/candidates/{$own->id}/photo")->assertForbidden();
    }

    public function test_invalid_document_types_are_not_found_on_both_routes(): void
    {
        $candidate = Candidate::factory()->create();
        $admin = $this->userWithRole(SystemRole::AcademicAdministrator);

        foreach (['unknown', 'Registration', 'ACADEMIC', 'academic.pdf', 'registration%00', '..%2Facademic'] as $type) {
            $this->actingAs($candidate->user)->get("/portal/profile/documents/{$type}")->assertNotFound();
            $this->actingAs($admin)->get("/candidates/{$candidate->id}/documents/{$type}")->assertNotFound();
        }
        $this->actingAs($admin)->get('/candidates/999999/documents/registration')->assertNotFound();
    }

    public function test_candidates_cannot_use_the_staff_document_route_even_for_themselves(): void
    {
        $candidate = Candidate::factory()->create();

        $this->actingAs($candidate->user)->get("/candidates/{$candidate->id}/documents/registration")->assertForbidden();
    }

    public function test_a_candidate_without_a_class_can_download_both_records(): void
    {
        $candidate = $this->makeCandidate(null, '902');

        foreach (['registration', 'academic'] as $type) {
            $response = $this->actingAs($candidate->user)->get("/portal/profile/documents/{$type}")->assertOk()
                ->assertHeader('Content-Type', 'application/pdf');
            $this->assertStringStartsWith('%PDF-', $response->getContent());
        }
    }

    public function test_document_downloads_are_rate_limited_per_account(): void
    {
        $candidate = Candidate::factory()->create();
        $other = Candidate::factory()->create();

        for ($request = 1; $request <= 10; $request++) {
            $this->actingAs($candidate->user)->get('/portal/profile/documents/registration')->assertOk();
        }
        $this->actingAs($candidate->user)->get('/portal/profile/documents/registration')->assertTooManyRequests();

        // Another account has its own limit; the limit resets after a minute.
        $this->actingAs($other->user)->get('/portal/profile/documents/registration')->assertOk();
        $this->travel(61)->seconds();
        $this->actingAs($candidate->user)->get('/portal/profile/documents/registration')->assertOk();
    }

    public function test_staff_document_downloads_are_rate_limited(): void
    {
        $candidate = Candidate::factory()->create();
        $admin = $this->userWithRole(SystemRole::AcademicAdministrator);

        for ($request = 1; $request <= 10; $request++) {
            $this->actingAs($admin)->get("/candidates/{$candidate->id}/documents/registration")->assertOk();
        }
        $this->actingAs($admin)->get("/candidates/{$candidate->id}/documents/academic")->assertTooManyRequests();
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    private function homeProps(User $user, array $query = []): array
    {
        return $this->pageProps($user, '/portal'.($query === [] ? '' : '?'.http_build_query($query)), 'portal/home');
    }

    /**
     * @return array<string, mixed>
     */
    private function pageProps(User $user, string $url, string $component): array
    {
        return $this->actingAs($user)->get($url)->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component($component))
            ->inertiaProps();
    }
}
