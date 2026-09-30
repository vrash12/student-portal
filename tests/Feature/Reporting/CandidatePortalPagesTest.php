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
        $this->assertSame(0, $props['outstanding']['total']);
        $this->assertSame([], $props['recentResults']);
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
        $this->assertSame(['id', 'title', 'kind', 'subject', 'durationMinutes', 'opensAt', 'closesAt', 'attemptsUsed', 'attemptLimit', 'resumeId'], array_keys($props['available']['data'][0]));
        $this->assertStringNotContainsString('PORTAL-SECRET-CODE', json_encode($props));
        $this->assertTrue($props['summary']['eligible']);
        $this->assertSame('Sample Batch A', $props['summary']['className']);
        $this->assertSame(2, $props['summary']['subjectCount']);
    }

    public function test_home_counts_own_attempts_and_offers_resume_only_for_unexpired_attempts(): void
    {
        $this->buildReportingFixtures();
        $resumable = $this->makeExamination($this->offeringA1, 'Resumable Exam', ['attempt_limit' => 2]);
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
        $this->assertSame(0, $props['outstanding']['total']);
    }

    public function test_home_shows_only_released_graded_results_of_the_candidate(): void
    {
        $this->buildReportingFixtures();
        $released = $this->makeExamination($this->offeringA1, 'Released Exam', ['release_results' => true, 'attempt_limit' => 2]);
        $unreleased = $this->makeExamination($this->offeringA2, 'Unreleased Exam');
        $this->makeAttempt($released, $this->candidateInA, ['submitted_at' => now()->subHour()]);
        $this->makeAttempt($released, $this->candidateInA, ['attempt_number' => 2, 'result_status' => 'pending_review', 'earned_points' => null, 'percentage' => null, 'passed' => null]);
        $this->makeAttempt($unreleased, $this->candidateInA);
        $this->makeAttempt($released, $this->secondInA, ['percentage' => 40, 'earned_points' => 4, 'passed' => false]);

        $props = $this->homeProps($this->candidateInA->user);

        $this->assertCount(1, $props['recentResults']);
        $this->assertSame('Released Exam', $props['recentResults'][0]['title']);
        $this->assertSame(1, $props['recentResults'][0]['attemptNumber']);
        $this->assertEquals(80, $props['recentResults'][0]['percentage']);
        $this->assertTrue($props['recentResults'][0]['passed']);
        $this->assertStringNotContainsString('Unreleased Exam', json_encode($props['recentResults']));
        $this->assertStringNotContainsString('synthetic-', json_encode($props));
    }

    public function test_recent_results_are_limited_to_six(): void
    {
        $this->buildReportingFixtures();
        $exam = $this->makeExamination($this->offeringA1, 'Practice Exam', ['release_results' => true, 'attempt_limit' => 10]);
        for ($number = 1; $number <= 8; $number++) {
            $this->makeAttempt($exam, $this->candidateInA, ['attempt_number' => $number, 'submitted_at' => now()->subMinutes(100 - $number)]);
        }

        $results = $this->homeProps($this->candidateInA->user)['recentResults'];

        $this->assertSame([8, 7, 6, 5, 4, 3], array_column($results, 'attemptNumber'));
    }

    public function test_outstanding_work_lists_finalized_assessments_without_the_candidates_score(): void
    {
        $this->buildReportingFixtures();
        // A2 has no score on Subject 2 Examination; a draft assessment is not outstanding.
        $this->createAssessment($this->quizzes, 'Draft Quiz', '100');

        $a2 = $this->homeProps($this->secondInA->user)['outstanding'];
        $a1 = $this->homeProps($this->candidateInA->user)['outstanding'];

        $this->assertSame(['Subject 2 Examination'], array_column($a2['data'], 'title'));
        $this->assertSame(['id', 'title', 'subject', 'category', 'date'], array_keys($a2['data'][0]));
        $this->assertSame(0, $a1['total']);
    }

    public function test_home_pagination_parameters_are_separate_and_tolerant(): void
    {
        $this->buildReportingFixtures();
        for ($index = 1; $index <= 9; $index++) {
            $this->makeExamination($this->offeringA1, "Exam {$index}", ['closes_at' => now()->addHours($index)]);
        }
        for ($index = 1; $index <= 7; $index++) {
            $this->makeExamination($this->offeringA1, "Later Exam {$index}", ['opens_at' => now()->addDays($index)]);
        }

        $first = $this->homeProps($this->candidateInA->user);
        $this->assertCount(8, $first['available']['data']);
        $this->assertSame(9, $first['available']['total']);
        $this->assertCount(6, $first['upcoming']['data']);

        $second = $this->homeProps($this->candidateInA->user, ['available_page' => 2]);
        $this->assertSame(['Exam 9'], array_column($second['available']['data'], 'title'));
        // Only the requested list moves.
        $this->assertSame(1, $second['upcoming']['current_page']);
        $this->assertStringContainsString('available_page=2', $second['upcoming']['next_page_url']);

        foreach (['abc', '-3', '0', '999'] as $page) {
            $props = $this->homeProps($this->candidateInA->user, ['available_page' => $page, 'upcoming_page' => $page, 'outstanding_page' => $page]);
            $this->assertSame(9, $props['available']['total'], "page {$page}");
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
        $pages = ['/portal', '/portal/profile', '/portal/profile/photo', '/portal/profile/documents/registration', '/portal/profile/documents/academic'];

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

        foreach (['/portal', '/portal/profile', '/portal/profile/photo', '/portal/profile/documents/registration'] as $page) {
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
                ->where('academics.subjects', [])
                ->where('assessmentHistory.total', 0)
                ->where('examinationResults.total', 0));
    }

    public function test_profile_shows_own_scores_only_and_pending_results_as_pending(): void
    {
        $this->buildReportingFixtures();
        $exam = $this->makeExamination($this->offeringA1, 'Essay Exam', ['release_results' => true]);
        $this->makeAttempt($exam, $this->candidateInA, ['result_status' => 'pending_review', 'earned_points' => 6, 'percentage' => 60, 'passed' => false]);

        $props = $this->actingAs($this->candidateInA->user)->get('/portal/profile?candidate_id='.$this->secondInA->id)->assertOk()->inertiaProps();

        $this->assertSame($this->candidateInA->id, $props['candidate']['id']);
        $result = $props['examinationResults']['data'][0];
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
        $first = $this->actingAs($user)->get('/portal/profile')->assertOk()->inertiaProps();
        $second = $this->actingAs($user)->get('/portal/profile?assessments_page=2')->assertOk()->inertiaProps();

        // 16 extra quizzes plus Quiz 1 and Subject 2 Examination of Batch A.
        $this->assertSame(18, $first['assessmentHistory']['total']);
        $this->assertCount(15, $first['assessmentHistory']['data']);
        $this->assertCount(3, $second['assessmentHistory']['data']);
        $this->assertSame('Extra Quiz 16', $first['assessmentHistory']['data'][0]['title']);

        foreach (['abc', '-1', '0', '500'] as $page) {
            $this->actingAs($user)->get("/portal/profile?assessments_page={$page}&exams_page={$page}")->assertOk();
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
        return $this->actingAs($user)->get('/portal'.($query === [] ? '' : '?'.http_build_query($query)))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('portal/home'))
            ->inertiaProps();
    }
}
