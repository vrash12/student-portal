<?php

namespace Tests\Feature\Grading;

use App\Enums\GradeCorrectionStatus;
use App\Enums\Permission as PermissionCode;
use App\Enums\ScoreRevisionKind;
use App\Models\Assessment;
use App\Models\AssessmentScore;
use App\Models\AuditLog;
use App\Models\Candidate;
use App\Models\GradeCorrectionRequest;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\Grading\ScoreRecordingService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Authorized grade corrections (owner request, 2026-10-02): an instructor
 * files a request with an incident report; the finalized score changes only
 * when an administrator approves it. Nobody decides their own request.
 */
class GradeCorrectionRequestTest extends TestCase
{
    use BuildsGradingFixtures;

    private const REPORT = 'Item 3 was checked against the wrong answer key.';

    /** Finalized quiz of Batch A, Subject 1 (max 50): candidateInA has 40. */
    private Assessment $quiz;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildGradingFixtures();
        $this->quiz = $this->createAssessment($this->quizzes, 'Quiz 1', '50');
        $this->recordScores($this->quiz, [$this->candidateInA->id => '40']);
        $this->finalize($this->quiz);
    }

    public function test_filing_a_request_keeps_the_score_until_it_is_approved(): void
    {
        $this->file()->assertSessionHasNoErrors()->assertRedirect(route('assessments.show', $this->quiz))->assertInertiaFlash('toast.type', 'success');

        $request = GradeCorrectionRequest::query()->sole();
        $this->assertSame(GradeCorrectionStatus::Pending, $request->status);
        $this->assertSame('40.00', $request->current_score);
        $this->assertSame('44.00', $request->proposed_score);
        $this->assertSame(self::REPORT, $request->incident_details);
        $this->assertSame($this->alpha->id, (int) $request->requested_by);
        $this->assertScore('40.00');

        $audit = AuditLog::query()->where('action', 'grade_correction.requested')->sole();
        $this->assertSame('grade_correction_request', $audit->auditable_type);
        $this->assertSame($this->alpha->id, (int) $audit->actor_id);
        $this->assertSame(self::REPORT, $audit->reason);

        // The assessment page shows the request waiting, instead of the request button.
        $this->actingAs($this->alpha)->get(route('assessments.show', $this->quiz))->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where("corrections.pending.{$this->candidateInA->id}", $request->id)
                ->where('corrections.recent.0.status.value', 'pending'));
    }

    public function test_an_approved_request_changes_the_score_and_links_the_history(): void
    {
        $this->file();
        $request = GradeCorrectionRequest::query()->sole();

        $this->actingAs($this->academicAdmin)->post(route('grade-corrections.approve', $request), ['note' => 'Checked against the key.'])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('grade-corrections.show', $request));

        $this->assertScore('44.00');
        $request->refresh();
        $this->assertSame(GradeCorrectionStatus::Approved, $request->status);
        $this->assertSame($this->academicAdmin->id, (int) $request->decided_by);
        $this->assertNotNull($request->decided_at);
        $this->assertSame('Checked against the key.', $request->decision_note);

        $revision = AssessmentScore::query()->where('candidate_id', $this->candidateInA->id)->sole()->revisions()->latest('id')->firstOrFail();
        $this->assertSame(ScoreRevisionKind::Corrected, $revision->kind);
        $this->assertSame($request->id, (int) $revision->grade_correction_request_id);
        $this->assertSame($this->academicAdmin->id, (int) $revision->changed_by);
        $this->assertStringContainsString('requested by Instructor Alpha and approved by Academic Admin', $revision->reason);
        $this->assertStringContainsString(self::REPORT, $revision->reason);

        $this->assertSame(1, AuditLog::query()->where('action', 'grade_correction.approved')->count());
        $this->assertSame(1, AuditLog::query()->where('action', 'assessment_score.corrected')->count());
    }

    public function test_a_rejection_needs_a_reason_and_keeps_the_score(): void
    {
        $this->file();
        $request = GradeCorrectionRequest::query()->sole();

        $this->actingAs($this->academicAdmin)->post(route('grade-corrections.reject', $request), ['note' => ''])->assertSessionHasErrors('note');
        $this->assertTrue($request->fresh()->isPending());

        $this->actingAs($this->academicAdmin)->post(route('grade-corrections.reject', $request), ['note' => 'Attach the rechecked paper first.'])
            ->assertSessionHasNoErrors();

        $request->refresh();
        $this->assertSame(GradeCorrectionStatus::Rejected, $request->status);
        $this->assertSame('Attach the rechecked paper first.', $request->decision_note);
        $this->assertScore('40.00');
        $this->assertSame('Attach the rechecked paper first.', AuditLog::query()->where('action', 'grade_correction.rejected')->sole()->reason);

        // A decided request cannot be decided again.
        $this->actingAs($this->academicAdmin)->post(route('grade-corrections.approve', $request))->assertSessionHasErrors('decision');
        $this->assertScore('40.00');
    }

    public function test_an_incident_report_is_required(): void
    {
        foreach ([['incident_type' => ''], ['incident_type' => 'unknown'], ['incident_details' => ''], ['incident_details' => 'Wrong score.'], ['incident_details' => str_repeat('x', 2001)]] as $override) {
            $this->file(overrides: $override)->assertSessionHasErrors(array_keys($override));
        }

        $this->assertSame(0, GradeCorrectionRequest::query()->count());
    }

    public function test_instructors_cannot_overwrite_a_finalized_score_or_approve_corrections(): void
    {
        // The old direct correction endpoint is gone.
        $this->actingAs($this->alpha)->post("/assessments/{$this->quiz->id}/corrections", [
            'candidate_id' => $this->candidateInA->id, 'score' => '44', 'reason' => 'Direct change.', 'expected_score' => '40',
        ])->assertMethodNotAllowed();

        $this->file();
        $request = GradeCorrectionRequest::query()->sole();

        $this->actingAs($this->alpha)->post(route('grade-corrections.approve', $request))->assertForbidden();
        $this->actingAs($this->bravo)->post(route('grade-corrections.approve', $request))->assertForbidden();
        $this->assertScore('40.00');
        $this->assertTrue($request->fresh()->isPending());
    }

    public function test_nobody_approves_their_own_request(): void
    {
        $both = $this->customStaff([PermissionCode::AccessStaffArea, PermissionCode::TeachClasses, PermissionCode::RecordGrades, PermissionCode::ApproveGradeCorrections]);
        $this->teach($both, $this->offeringA1);

        $this->file(actor: $both)->assertSessionHasNoErrors();
        $request = GradeCorrectionRequest::query()->sole();

        $this->actingAs($both)->post(route('grade-corrections.approve', $request))->assertForbidden();
        $this->actingAs($both)->post(route('grade-corrections.reject', $request), ['note' => 'No.'])->assertForbidden();
        $this->actingAs($both)->get(route('grade-corrections.show', $request))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('can.decide', false)->where('can.cancel', true));

        $this->actingAs($this->academicAdmin)->post(route('grade-corrections.approve', $request))->assertSessionHasNoErrors();
        $this->assertScore('44.00');
    }

    public function test_one_pending_request_per_candidate_and_the_requester_may_cancel_it(): void
    {
        $this->file();
        $this->file(score: '45')->assertSessionHasErrors('candidate_id');
        $request = GradeCorrectionRequest::query()->sole();

        $this->actingAs($this->academicAdmin)->post(route('grade-corrections.cancel', $request))->assertForbidden();
        $this->actingAs($this->alpha)->post(route('grade-corrections.cancel', $request))->assertSessionHasNoErrors();
        $this->assertSame(GradeCorrectionStatus::Cancelled, $request->fresh()->status);
        $this->assertScore('40.00');

        // Once cancelled, a new request can be filed.
        $this->file(score: '45')->assertSessionHasNoErrors();
        $this->assertSame(2, GradeCorrectionRequest::query()->count());
    }

    public function test_a_request_whose_score_changed_since_filing_cannot_be_approved(): void
    {
        $this->file();
        $request = GradeCorrectionRequest::query()->sole();

        // Another approved correction changed the score meanwhile.
        $this->app->make(ScoreRecordingService::class)->correctFinalizedScore($this->quiz, $this->candidateInA->id, '42', null, 'Earlier approved correction.', '40', null, $this->academicAdmin);

        $this->actingAs($this->academicAdmin)->get(route('grade-corrections.show', $request))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('correction.scoreNow', '42')->where('correction.currentScore', '40'));
        $this->actingAs($this->academicAdmin)->post(route('grade-corrections.approve', $request))->assertSessionHasErrors('decision');

        $this->assertScore('42.00');
        $this->assertTrue($request->fresh()->isPending());
    }

    public function test_draft_assessments_and_other_classes_cannot_get_requests(): void
    {
        $draft = $this->createAssessment($this->quizzes, 'Quiz 2', '50');
        $this->recordScores($draft, [$this->candidateInA->id => '30']);

        $this->actingAs($this->alpha)->post("/assessments/{$draft->id}/correction-requests", $this->payload(expected: '30'))->assertSessionHasErrors('score');
        $this->file(candidate: $this->candidateInB)->assertSessionHasErrors('candidate_id');
        $this->file(actor: $this->bravo)->assertForbidden();
        $this->file(actor: $this->academicAdmin)->assertForbidden();

        $this->assertSame(0, GradeCorrectionRequest::query()->count());
    }

    public function test_the_list_shows_every_request_to_approvers_and_only_their_own_to_instructors(): void
    {
        $this->file();
        $request = GradeCorrectionRequest::query()->sole();

        $this->actingAs($this->academicAdmin)->get(route('grade-corrections.index'))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('staff/grade-corrections/index')
                ->where('status', 'pending')->where('scope', 'all')->where('counts.pending', 1)
                ->has('requests.data', 1)->where('requests.data.0.id', $request->id));

        $this->actingAs($this->alpha)->get(route('grade-corrections.index'))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('status', 'all')->where('scope', 'own')->has('requests.data', 1));

        // Bravo teaches neither the subject nor filed the request.
        $this->actingAs($this->bravo)->get(route('grade-corrections.index'))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('requests.data', 0));
        $this->actingAs($this->bravo)->get(route('grade-corrections.show', $request))->assertForbidden();

        $this->actingAs($this->candidateInA->user)->get(route('grade-corrections.index'))->assertForbidden();
        $this->actingAs($this->candidateInA->user)->get(route('grade-corrections.show', $request))->assertForbidden();
    }

    public function test_the_database_allows_one_pending_request_per_candidate_and_valid_states(): void
    {
        $this->file();
        $row = DB::table('grade_correction_requests')->first();
        $copy = (array) $row;
        unset($copy['id'], $copy['pending_candidate_id']);

        $this->expectException(QueryException::class);
        DB::table('grade_correction_requests')->insert($copy);
    }

    public function test_the_database_requires_a_reason_for_rejections(): void
    {
        $this->file();

        $this->expectException(QueryException::class);
        DB::table('grade_correction_requests')->update(['status' => 'rejected', 'decided_by' => $this->academicAdmin->id, 'decided_at' => now(), 'decision_note' => null]);
    }

    // ---------------------------------------------------------------

    private function file(?User $actor = null, ?Candidate $candidate = null, string $score = '44', array $overrides = []): TestResponse
    {
        return $this->actingAs($actor ?? $this->alpha)->post(
            "/assessments/{$this->quiz->id}/correction-requests",
            [...$this->payload($candidate, $score), ...$overrides],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(?Candidate $candidate = null, string $score = '44', ?string $expected = '40'): array
    {
        return [
            'candidate_id' => ($candidate ?? $this->candidateInA)->id,
            'score' => $score,
            'comment' => null,
            'incident_type' => 'computation_error',
            'incident_details' => self::REPORT,
            'expected_score' => $candidate === null || $candidate->is($this->candidateInA) ? $expected : null,
            'expected_comment' => null,
        ];
    }

    private function assertScore(string $expected): void
    {
        $this->assertSame($expected, AssessmentScore::query()->where('assessment_id', $this->quiz->id)->where('candidate_id', $this->candidateInA->id)->value('score'));
    }

    /**
     * @param  list<PermissionCode>  $permissions
     */
    private function customStaff(array $permissions): User
    {
        $role = Role::query()->create(['code' => 'teaching_approver', 'name' => 'Teaching Approver']);
        $role->permissions()->sync(Permission::query()->whereIn('code', array_map(fn (PermissionCode $permission): string => $permission->value, $permissions))->pluck('id')->all());

        return User::factory()->create(['role_id' => $role->id, 'name' => 'Teaching Approver']);
    }
}
