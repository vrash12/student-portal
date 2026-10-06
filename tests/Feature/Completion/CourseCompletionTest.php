<?php

namespace Tests\Feature\Completion;

use App\Enums\AuditAction;
use App\Enums\CampusCode;
use App\Enums\CandidateStatus;
use App\Enums\SystemRole;
use App\Models\AcademicPeriod;
use App\Models\Assessment;
use App\Models\AuditLog;
use App\Models\Candidate;
use App\Models\ClassBatch;
use App\Models\ClassSubject;
use App\Models\Subject;
use App\Models\User;
use App\Services\ClassBatchService;
use App\Services\Grading\AssessmentService;
use App\Services\Grading\GradingSchemeService;
use App\Services\Grading\ScoreRecordingService;
use App\Services\InstructorAssignmentService;
use App\Services\Performance\PerformanceAreaService;
use Database\Factories\CampusFactory;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Course completion documents (owner request, 2026-10-06): the Transcript of
 * Records (provisional until the course is over) and the Certificate of
 * Completion (Completed, every subject final, qualified), from the CGPA,
 * final course grade and class rank; administrators only, audited.
 *
 * Class A: Subject 1 and Subject 2, one finalized examination each (out of
 * 100), one performance area "Academic" (both subjects, weight 100, pass 75,
 * must pass).
 *   Alpha   90 / 86   CGPA 88.00   qualifies
 *   Bravo   60 / 70   CGPA 65.00   does not qualify
 *   Charlie 80 / —    Subject 2 score missing
 */
class CourseCompletionTest extends TestCase
{
    private User $admin;

    private User $instructor;

    private ClassBatch $classA;

    private Candidate $alpha;

    private Candidate $bravo;

    private Candidate $charlie;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2027-07-20 02:00:00');
        $this->admin = $this->userWithRole(SystemRole::AcademicAdministrator);
        $this->instructor = $this->userWithRole(SystemRole::Instructor);

        $period = AcademicPeriod::factory()->active()->create([
            'name' => '2026-2027', 'starts_on' => '2026-08-03', 'ends_on' => '2027-07-30', 'passing_grade' => '75.00', 'warning_grade' => '80.00',
        ]);
        $this->classA = ClassBatch::factory()->for($period)->create(['name' => 'Class A']);
        $this->alpha = $this->candidate('C-001', 'Alpha');
        $this->bravo = $this->candidate('C-002', 'Bravo');
        $this->charlie = $this->candidate('C-003', 'Charlie');

        $subject1 = Subject::factory()->create(['code' => 'SUBJ-1', 'name' => 'Subject 1']);
        $subject2 = Subject::factory()->create(['code' => 'SUBJ-2', 'name' => 'Subject 2']);
        $offering1 = $this->offering($subject1);
        $offering2 = $this->offering($subject2);
        $this->app->make(InstructorAssignmentService::class)->assign($offering1, $this->instructor);
        $this->exam($offering1, [$this->alpha->id => '90', $this->bravo->id => '60', $this->charlie->id => '80']);
        $this->exam($offering2, [$this->alpha->id => '86', $this->bravo->id => '70', $this->charlie->id => null]);

        $this->app->make(PerformanceAreaService::class)->create([
            'name' => 'Academic', 'description' => null, 'source' => 'subjects', 'weight' => '100', 'passing_grade' => '75',
            'must_pass' => true, 'base_rating' => null, 'merit_value' => null, 'demerit_value' => null, 'sort_order' => 1, 'is_active' => true,
        ], [$subject1->id, $subject2->id]);
    }

    public function test_the_profile_shows_cgpa_final_grade_rank_and_why_a_certificate_waits(): void
    {
        $this->actingAs($this->admin)->get("/candidates/{$this->alpha->id}")->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('completion.cgpa.grade', 88)
            ->where('completion.cgpa.complete', true)
            ->where('completion.finalGrade.score', 88)
            ->where('completion.rank', 1)
            ->where('completion.classSize', 3)
            ->where('completion.qualification.status.value', 'qualified')
            ->where('completion.transcriptFinal', false)
            ->where('completion.certificate.eligible', false)
            ->where('completion.certificate.reasons.0', 'The candidate\'s status is Enrolled. Set it to Completed when the candidate finishes the course.')
            ->where('completion.transcriptUrl', "/candidates/{$this->alpha->id}/transcript/pdf"));

        $this->complete($this->alpha);
        $this->actingAs($this->admin)->get("/candidates/{$this->alpha->id}")->assertInertia(fn (Assert $page) => $page
            ->where('completion.transcriptFinal', true)
            ->where('completion.certificate.eligible', true)
            ->where('completion.certificate.reasons', []));

        // Instructors never see it (the rank is staff only and the documents are issued by administrators).
        $this->actingAs($this->instructor)->get("/candidates/{$this->alpha->id}")->assertOk()->assertInertia(fn (Assert $page) => $page->where('completion', null));
    }

    public function test_the_transcript_is_issued_provisional_then_final_and_audited(): void
    {
        $response = $this->actingAs($this->admin)->get("/candidates/{$this->alpha->id}/transcript/pdf")->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
        $this->assertStringStartsWith('%PDF', $response->getContent());
        $this->assertStringContainsString('transcript-c-001.pdf', (string) $response->headers->get('Content-Disposition'));

        $this->complete($this->alpha);
        $this->actingAs($this->admin)->get("/candidates/{$this->alpha->id}/transcript/pdf")->assertOk();

        $issued = AuditLog::query()->where('action', AuditAction::TranscriptIssued->value)->orderBy('id')->get();
        $this->assertCount(2, $issued);
        $this->assertSame('candidate', $issued[0]->auditable_type);
        $this->assertFalse($issued[0]->new_values['final']);
        $this->assertTrue($issued[1]->new_values['final']);
        $this->assertMatchesRegularExpression('/^TOR-'.$this->alpha->id.'-\d{8}-\d{6}$/', $issued[1]->new_values['reference']);
    }

    public function test_the_certificate_needs_completed_status_final_grades_and_qualification(): void
    {
        // Enrolled: refused, with the reason.
        $this->actingAs($this->admin)->from("/candidates/{$this->alpha->id}")->get("/candidates/{$this->alpha->id}/completion-certificate/pdf")
            ->assertRedirect("/candidates/{$this->alpha->id}")
            ->assertSessionHasErrors(['completion' => 'The certificate cannot be issued yet. The candidate\'s status is Enrolled. Set it to Completed when the candidate finishes the course.']);

        // Completed but not qualified (Academic 65.00 < 75).
        $this->complete($this->bravo);
        $this->actingAs($this->admin)->get("/candidates/{$this->bravo->id}/completion-certificate/pdf")->assertSessionHasErrors('completion');
        $this->assertStringContainsString('has not qualified', session('errors')->first('completion'));

        // Completed with a score missing in Subject 2.
        $this->complete($this->charlie);
        $this->actingAs($this->admin)->get("/candidates/{$this->charlie->id}/completion-certificate/pdf")->assertSessionHasErrors('completion');
        $this->assertStringContainsString('1 of 2 subjects are final', session('errors')->first('completion'));

        $this->assertSame(0, AuditLog::query()->where('action', AuditAction::CompletionCertificateIssued->value)->count());

        $this->complete($this->alpha);
        $response = $this->actingAs($this->admin)->get("/candidates/{$this->alpha->id}/completion-certificate/pdf")->assertOk();
        $this->assertStringStartsWith('%PDF', $response->getContent());
        $audit = AuditLog::query()->where('action', AuditAction::CompletionCertificateIssued->value)->sole();
        $this->assertMatchesRegularExpression('/^COC-'.$this->alpha->id.'-/', $audit->new_values['reference']);
    }

    public function test_the_class_sheet_has_only_the_candidates_who_can_receive_a_certificate(): void
    {
        $this->actingAs($this->admin)->from("/classes/{$this->classA->id}")->get("/classes/{$this->classA->id}/completion-certificates/pdf")
            ->assertRedirect("/classes/{$this->classA->id}")
            ->assertSessionHasErrors('completion');

        $this->complete($this->alpha);
        $this->complete($this->bravo);
        $this->actingAs($this->admin)->get("/classes/{$this->classA->id}/completion-certificates/pdf")->assertOk();

        $audit = AuditLog::query()->where('action', AuditAction::CompletionCertificateIssued->value)->sole();
        $this->assertSame('class_batch', $audit->auditable_type);
        $this->assertSame(1, $audit->new_values['certificates']);

        $this->actingAs($this->admin)->get("/classes/{$this->classA->id}")->assertInertia(fn (Assert $page) => $page->where('can.printCompletionCertificates', true));
    }

    public function test_only_administrators_of_the_campus_issue_the_documents(): void
    {
        $this->complete($this->alpha);

        foreach (["/candidates/{$this->alpha->id}/transcript/pdf", "/candidates/{$this->alpha->id}/completion-certificate/pdf", "/classes/{$this->classA->id}/completion-certificates/pdf"] as $url) {
            $this->actingAs($this->instructor)->get($url)->assertForbidden();
            $this->actingAs($this->alpha->user)->get($url)->assertForbidden();
        }

        $northAdmin = User::factory()->withRole(SystemRole::AcademicAdministrator)->onCampus(CampusFactory::fixed(CampusCode::North))->create();
        $this->actingAs($northAdmin)->get("/candidates/{$this->alpha->id}/transcript/pdf")->assertNotFound();
        $this->actingAs($northAdmin)->get("/classes/{$this->classA->id}/completion-certificates/pdf")->assertNotFound();

        $this->assertSame(0, AuditLog::query()->whereIn('action', [AuditAction::TranscriptIssued->value, AuditAction::CompletionCertificateIssued->value])->count());
    }

    private function candidate(string $number, string $lastName): Candidate
    {
        return Candidate::factory()->create(['class_batch_id' => $this->classA->id, 'candidate_number' => $number, 'first_name' => 'Candidate', 'last_name' => $lastName]);
    }

    private function complete(Candidate $candidate): void
    {
        $candidate->forceFill(['status' => CandidateStatus::Completed->value])->save();
    }

    private function offering(Subject $subject): ClassSubject
    {
        $offering = $this->app->make(ClassBatchService::class)->addSubject($this->classA, $subject);
        $this->app->make(GradingSchemeService::class)->save($offering, [['id' => null, 'name' => 'Examinations', 'weight' => '100']], null);

        return $offering;
    }

    /**
     * @param  array<int, string|null>  $scores  candidate id => score out of 100 (null: not recorded)
     */
    private function exam(ClassSubject $offering, array $scores): Assessment
    {
        $assessments = $this->app->make(AssessmentService::class);
        $assessment = $assessments->create($offering, [
            'title' => 'Examination',
            'assessment_category_id' => $offering->assessmentCategories()->sole()->id,
            'max_score' => '100',
            'assessed_on' => null,
        ], $this->admin);

        $this->app->make(ScoreRecordingService::class)->recordDraftScores($assessment, array_map(
            fn (string $score): array => ['score' => $score, 'comment' => null, 'expected_score' => null, 'expected_comment' => null],
            array_filter($scores, fn (?string $score): bool => $score !== null),
        ), $this->admin);
        $assessments->finalize($assessment, $this->admin);

        return $assessment;
    }
}
