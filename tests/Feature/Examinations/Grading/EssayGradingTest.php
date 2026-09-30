<?php

namespace Tests\Feature\Examinations\Grading;

use App\Enums\Permission as PermissionCode;
use App\Enums\SystemRole;
use App\Models\AuditLog;
use App\Models\ExaminationAttempt;
use App\Models\ExaminationEssayGrade;
use App\Models\ExaminationEssayRevision;
use App\Models\Permission;
use App\Models\Question;
use App\Models\Role;
use App\Models\User;
use App\Services\Examinations\CandidateAttemptService;
use App\Services\Examinations\ManualEssayGradingService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use LogicException;
use Tests\TestCase;

class EssayGradingTest extends TestCase
{
    use BuildsEssayGradingFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildEssayGradingFixtures();
    }

    private function grade(ExaminationAttempt $attempt, int|string $itemId, mixed $score, int $version = 0, array $extra = []): TestResponse
    {
        return $this->put($this->gradeUrl($attempt), ['item_id' => $itemId, 'score' => $score, 'version' => $version, ...$extra]);
    }

    /** A staff role with the given permissions, assigned to teach Alpha's offering. */
    private function customInstructor(array $permissions, string $code): User
    {
        $role = Role::query()->create(['code' => $code, 'name' => 'Custom '.$code]);
        $role->permissions()->sync(Permission::query()->whereIn('code', array_map(fn (PermissionCode $permission) => $permission->value, $permissions))->pluck('id')->all());
        $user = User::factory()->create(['role_id' => $role->id, 'name' => 'Instructor '.ucfirst($code)]);
        $this->teach($user, $this->offeringA1);

        return $user;
    }

    // Queue ------------------------------------------------------------------

    public function test_queue_lists_only_submitted_attempts_awaiting_review_by_default(): void
    {
        $pending = $this->submitAttempt($this->candidateInA);
        $inProgress = app(CandidateAttemptService::class)->start($this->secondCandidateInA->user, $this->exam, null);

        $this->actingAs($this->alpha)->get('/examinations/'.$this->exam->id.'/grading')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('staff/examinations/grading')
                ->where('status', 'pending')
                ->has('attempts.data', 1)
                ->where('attempts.data.0.id', $pending->id)
                ->where('attempts.data.0.status', 'pending_review')
                ->where('attempts.data.0.candidateNumber', $this->candidateInA->candidate_number)
                ->missing('attempts.data.0.answers')
                ->missing('attempts.data.0.scoring_key'));

        $this->assertSame('in_progress', $inProgress->fresh()->status);
    }

    public function test_expired_unsubmitted_attempts_are_not_queued(): void
    {
        $this->exam->auto_submit = false;
        $this->exam->save();
        $attempt = app(CandidateAttemptService::class)->start($this->candidateInA->user, $this->exam, null);
        $this->travel(31)->minutes();
        app(CandidateAttemptService::class)->expire($attempt->fresh());
        $this->assertSame('expired', $attempt->fresh()->status);

        $this->actingAs($this->alpha)->get('/examinations/'.$this->exam->id.'/grading?status=all')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('attempts.data', 0));
        $this->get('/examination-attempts/'.$attempt->id.'/grading')->assertStatus(422);
        $this->grade($attempt, $this->shortEssay->id, '1')->assertSessionHasErrors('attempt');
        $this->assertSame(0, ExaminationEssayGrade::count());
    }

    public function test_graded_attempts_move_from_pending_to_graded_filter(): void
    {
        $attempt = $this->submitAttempt($this->candidateInA);
        $other = $this->submitAttempt($this->secondCandidateInA);
        $this->actingAs($this->alpha);
        $this->grade($attempt, $this->shortEssay->id, '2')->assertSessionHasNoErrors();
        $this->grade($attempt, $this->longEssay->id, '4')->assertSessionHasNoErrors();

        $this->get('/examinations/'.$this->exam->id.'/grading')->assertInertia(fn (Assert $page) => $page
            ->has('attempts.data', 1)->where('attempts.data.0.id', $other->id));
        $this->get('/examinations/'.$this->exam->id.'/grading?status=graded')->assertInertia(fn (Assert $page) => $page
            ->has('attempts.data', 1)->where('attempts.data.0.id', $attempt->id)->where('attempts.data.0.status', 'graded'));
        $this->get('/examinations/'.$this->exam->id.'/grading?status=all')->assertInertia(fn (Assert $page) => $page->has('attempts.data', 2));
    }

    public function test_queue_rejects_unknown_status_filter(): void
    {
        $this->actingAs($this->alpha)->get('/examinations/'.$this->exam->id.'/grading?status=everything')->assertSessionHasErrors('status');
    }

    public function test_objective_only_submissions_are_graded_immediately_and_not_queued(): void
    {
        $exam = $this->createExamination($this->offeringA1, 'Objective only');
        $this->addItem($exam, Question::factory()->trueFalse()->create(['subject_id' => $this->offeringA1->subject_id]), 1, '2');
        $attempt = $this->submitAttempt($this->candidateInA, exam: $exam);

        $this->assertSame('graded', $attempt->result_status);
        $this->assertSame('100.00', $attempt->percentage);
        $this->actingAs($this->alpha)->get('/examinations/'.$exam->id.'/grading')->assertInertia(fn (Assert $page) => $page->has('attempts.data', 0));
    }

    // Authorization ------------------------------------------------------------

    public function test_queue_and_attempt_are_forbidden_outside_the_instructors_offering(): void
    {
        $attempt = $this->submitAttempt($this->candidateInA);
        $admin = $this->userWithRole(SystemRole::SuperAdministrator);
        $academicAdmin = $this->userWithRole(SystemRole::AcademicAdministrator);
        foreach ([$this->bravo, $admin, $academicAdmin, $this->candidateInA->user] as $actor) {
            $this->actingAs($actor)->get('/examinations/'.$this->exam->id.'/grading')->assertForbidden();
            $this->get('/examination-attempts/'.$attempt->id.'/grading')->assertForbidden();
            $this->grade($attempt, $this->shortEssay->id, '2')->assertForbidden();
        }
        $this->assertSame(0, ExaminationEssayGrade::count());
    }

    public function test_bravo_teaching_the_same_subject_in_another_batch_is_forbidden(): void
    {
        // Bravo teaches Subject 1 to Batch B: same subject, different offering.
        $this->assertTrue($this->bravo->teachingAssignments()->whereHas('classSubject', fn ($query) => $query->where('subject_id', $this->offeringA1->subject_id))->exists());
        $attempt = $this->submitAttempt($this->candidateInA);
        $this->actingAs($this->bravo)->grade($attempt, $this->shortEssay->id, '2')->assertForbidden();
        $this->expectException(AuthorizationException::class);
        app(ManualEssayGradingService::class)->grade($this->bravo, $attempt, $this->shortEssay->id, '2', null, 0, null);
    }

    public function test_guests_are_redirected_to_sign_in(): void
    {
        $attempt = $this->submitAttempt($this->candidateInA);
        $this->get('/examinations/'.$this->exam->id.'/grading')->assertRedirect(route('login'));
        $this->get('/examination-attempts/'.$attempt->id.'/grading')->assertRedirect(route('login'));
        $this->grade($attempt, $this->shortEssay->id, '2')->assertRedirect(route('login'));
    }

    public function test_deactivated_instructor_cannot_grade(): void
    {
        $attempt = $this->submitAttempt($this->candidateInA);
        $this->alpha->forceFill(['is_active' => false])->save();
        $this->actingAs($this->alpha)->grade($attempt, $this->shortEssay->id, '2')->assertRedirect(route('login'));
        $this->assertSame(0, ExaminationEssayGrade::count());
        $this->expectException(AuthorizationException::class);
        app(ManualEssayGradingService::class)->grade($this->alpha->fresh(), $attempt, $this->shortEssay->id, '2', null, 0, null);
    }

    public function test_assigned_staff_without_grades_record_cannot_open_queue_or_grade(): void
    {
        $attempt = $this->submitAttempt($this->candidateInA);
        $user = $this->customInstructor([PermissionCode::AccessStaffArea, PermissionCode::TeachClasses, PermissionCode::ManageExaminations], 'exam_builder');
        $this->actingAs($user)->get('/examinations/'.$this->exam->id.'/grading')->assertForbidden();
        $this->get('/examination-attempts/'.$attempt->id.'/grading')->assertForbidden();
        $this->grade($attempt, $this->shortEssay->id, '2')->assertForbidden();
        $this->assertSame(0, ExaminationEssayGrade::count());
    }

    public function test_assigned_co_instructor_with_both_permissions_may_grade(): void
    {
        $attempt = $this->submitAttempt($this->candidateInA);
        $user = $this->customInstructor([PermissionCode::AccessStaffArea, PermissionCode::TeachClasses, PermissionCode::RecordGrades, PermissionCode::ManageExaminations], 'co_grader');
        $this->actingAs($user)->grade($attempt, $this->shortEssay->id, '2')->assertSessionHasNoErrors()->assertRedirect();
        $this->assertSame($user->id, ExaminationEssayGrade::sole()->graded_by);
    }

    // Show page ----------------------------------------------------------------

    public function test_attempt_page_shows_only_essays_without_scoring_key(): void
    {
        $attempt = $this->submitAttempt($this->candidateInA, essayText: 'Synthetic long answer');
        $this->actingAs($this->alpha)->get('/examination-attempts/'.$attempt->id.'/grading')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('staff/examinations/grade-attempt')
                ->has('questions', 2)
                ->where('questions.0.answer', 'Synthetic long answer')
                ->where('questions.0.version', 0)
                ->where('questions.0.grade', null)
                ->missing('attempt.scoring_key')
                ->missing('scoring_key')
                ->missing('questions.0.correct_choice_id')
                ->where('attempt.status', 'pending_review'));
        $this->assertStringNotContainsString('correct_choice_id', json_encode($this->get('/examination-attempts/'.$attempt->id.'/grading')->viewData('page')));
    }

    // Validation ---------------------------------------------------------------

    public function test_score_bounds_and_types_are_validated(): void
    {
        $attempt = $this->submitAttempt($this->candidateInA);
        $this->actingAs($this->alpha);
        foreach (['-1', '3.01', '4', 'abc', '', '1.234', '1e1', 'NaN'] as $invalid) {
            $this->grade($attempt, $this->shortEssay->id, $invalid)->assertSessionHasErrors('score');
        }
        $this->grade($attempt, $this->shortEssay->id, ['2'])->assertSessionHasErrors('score');
        $this->put($this->gradeUrl($attempt), ['item_id' => $this->shortEssay->id, 'version' => 0])->assertSessionHasErrors('score');
        $this->grade($attempt, $this->shortEssay->id, '2', -1)->assertSessionHasErrors('version');
        $this->grade($attempt, 'x', '2')->assertSessionHasErrors('item_id');
        $this->grade($attempt, $this->shortEssay->id, '2', 0, ['comment' => str_repeat('a', 5001)])->assertSessionHasErrors('comment');
        $this->assertSame(0, ExaminationEssayGrade::count());
        $this->assertSame(0, ExaminationEssayRevision::count());
    }

    public function test_boundary_and_decimal_scores_are_accepted(): void
    {
        $attempt = $this->submitAttempt($this->candidateInA);
        $this->actingAs($this->alpha);
        $this->grade($attempt, $this->shortEssay->id, '0')->assertSessionHasNoErrors();
        $this->grade($attempt, $this->longEssay->id, '5')->assertSessionHasNoErrors();
        $this->assertSame('0.00', ExaminationEssayGrade::where('examination_question_id', $this->shortEssay->id)->sole()->score);
        $this->assertSame('5.00', ExaminationEssayGrade::where('examination_question_id', $this->longEssay->id)->sole()->score);

        $second = $this->submitAttempt($this->secondCandidateInA);
        $this->grade($second, $this->shortEssay->id, '2.75')->assertSessionHasNoErrors();
        $this->assertSame('2.75', ExaminationEssayGrade::where('examination_attempt_id', $second->id)->sole()->score);
    }

    public function test_objective_or_foreign_items_cannot_be_graded_manually(): void
    {
        $attempt = $this->submitAttempt($this->candidateInA);
        $otherExam = $this->createExamination($this->offeringA1, 'Other exam');
        $foreign = $this->addItem($otherExam, Question::factory()->essay()->create(['subject_id' => $this->offeringA1->subject_id]), 1, '10');
        $this->actingAs($this->alpha);
        $this->grade($attempt, $this->objectiveItem->id, '1')->assertSessionHasErrors('item');
        $this->grade($attempt, $foreign->id, '1')->assertSessionHasErrors('item');
        $this->grade($attempt, 999999, '1')->assertSessionHasErrors('item');
        $this->assertSame(0, ExaminationEssayGrade::count());
    }

    public function test_maximum_uses_the_submitted_snapshot_not_later_item_changes(): void
    {
        $attempt = $this->submitAttempt($this->candidateInA);
        // Points changed after submission must not widen the bound for this attempt.
        $this->shortEssay->points = 10;
        $this->shortEssay->save();
        $this->actingAs($this->alpha)->grade($attempt, $this->shortEssay->id, '4')->assertSessionHasErrors('score');
        $this->grade($attempt, $this->shortEssay->id, '3')->assertSessionHasNoErrors();
        $this->assertSame('3.00', ExaminationEssayGrade::sole()->max_points);
    }

    // Versioning, corrections, history -------------------------------------------

    public function test_stale_version_is_rejected_and_keeps_the_first_save(): void
    {
        $attempt = $this->submitAttempt($this->candidateInA);
        $co = $this->customInstructor([PermissionCode::AccessStaffArea, PermissionCode::TeachClasses, PermissionCode::RecordGrades, PermissionCode::ManageExaminations], 'co_grader');
        // Both graders opened the page at version 0.
        $this->actingAs($this->alpha)->grade($attempt, $this->shortEssay->id, '2')->assertSessionHasNoErrors();
        $this->actingAs($co)->grade($attempt, $this->shortEssay->id, '1', 0, ['reason' => 'Stale correction'])->assertSessionHasErrors('version');

        $grade = ExaminationEssayGrade::sole();
        $this->assertSame('2.00', $grade->score);
        $this->assertSame(1, $grade->version);
        $this->assertSame($this->alpha->id, $grade->graded_by);
        $this->assertSame(1, ExaminationEssayRevision::count());
    }

    public function test_first_grade_with_nonzero_version_is_rejected(): void
    {
        $attempt = $this->submitAttempt($this->candidateInA);
        $this->actingAs($this->alpha)->grade($attempt, $this->shortEssay->id, '2', 3)->assertSessionHasErrors('version');
        $this->assertSame(0, ExaminationEssayGrade::count());
    }

    public function test_retrying_an_identical_save_is_idempotent(): void
    {
        $attempt = $this->submitAttempt($this->candidateInA);
        $this->actingAs($this->alpha);
        $this->grade($attempt, $this->shortEssay->id, '2.5', 0, ['comment' => 'Synthetic feedback'])->assertSessionHasNoErrors();
        $this->grade($attempt, $this->shortEssay->id, '2.50', 0, ['comment' => 'Synthetic feedback'])->assertSessionHasNoErrors();

        $this->assertSame(1, ExaminationEssayGrade::sole()->version);
        $this->assertSame(1, ExaminationEssayRevision::count());
        $this->assertSame(1, AuditLog::where('action', 'examination.essay_graded')->count());
    }

    public function test_correction_requires_a_reason_for_score_or_comment_change(): void
    {
        $attempt = $this->submitAttempt($this->candidateInA);
        $this->actingAs($this->alpha);
        $this->grade($attempt, $this->shortEssay->id, '2', 0, ['comment' => 'First'])->assertSessionHasNoErrors();
        $this->grade($attempt, $this->shortEssay->id, '1', 1)->assertSessionHasErrors('reason');
        $this->grade($attempt, $this->shortEssay->id, '1', 1, ['comment' => 'First', 'reason' => '   '])->assertSessionHasErrors('reason');
        $this->grade($attempt, $this->shortEssay->id, '2', 1, ['comment' => 'Changed feedback'])->assertSessionHasErrors('reason');
        $this->assertSame('2.00', ExaminationEssayGrade::sole()->score);
        $this->assertSame('First', ExaminationEssayGrade::sole()->comment);
        $this->assertSame(1, ExaminationEssayRevision::count());
    }

    public function test_corrections_append_revision_history_with_previous_and_new_values(): void
    {
        $attempt = $this->submitAttempt($this->candidateInA);
        $co = $this->customInstructor([PermissionCode::AccessStaffArea, PermissionCode::TeachClasses, PermissionCode::RecordGrades, PermissionCode::ManageExaminations], 'co_grader');
        $this->actingAs($this->alpha)->grade($attempt, $this->shortEssay->id, '2', 0, ['comment' => 'First'])->assertSessionHasNoErrors();
        $this->actingAs($co)->grade($attempt, $this->shortEssay->id, '1.5', 1, ['comment' => 'First', 'reason' => 'Rubric recheck'])->assertSessionHasNoErrors();
        $this->actingAs($this->alpha)->grade($attempt, $this->shortEssay->id, '1.5', 2, ['comment' => 'Revised', 'reason' => 'Clarified feedback'])->assertSessionHasNoErrors();

        $revisions = ExaminationEssayRevision::orderBy('id')->get();
        $this->assertCount(3, $revisions);
        $this->assertSame([null, '2.00', '1.50'], $revisions->pluck('previous_score')->all());
        $this->assertSame(['2.00', '1.50', '1.50'], $revisions->pluck('new_score')->all());
        $this->assertSame([1, 2, 3], $revisions->pluck('version')->all());
        $this->assertSame([$this->alpha->id, $co->id, $this->alpha->id], $revisions->pluck('actor_id')->map(fn ($id) => (int) $id)->all());
        $this->assertSame([null, 'Rubric recheck', 'Clarified feedback'], $revisions->pluck('reason')->all());
        $this->assertSame([null, 'First', 'First'], $revisions->pluck('previous_comment')->all());
        $this->assertSame(['First', 'First', 'Revised'], $revisions->pluck('new_comment')->all());

        $grade = ExaminationEssayGrade::sole();
        $this->assertSame(3, $grade->version);
        $this->assertSame($this->alpha->id, (int) $grade->graded_by);

        $this->actingAs($this->alpha)->get('/examination-attempts/'.$attempt->id.'/grading')->assertInertia(fn (Assert $page) => $page
            ->has('history.data', 3)
            ->where('history.data.0.before', '1.50')->where('history.data.0.reason', 'Clarified feedback')
            ->where('history.data.2.before', null)->where('history.data.2.after', '2.00')
            ->where('questions.0.version', 3));
    }

    public function test_revision_history_cannot_be_updated_or_deleted(): void
    {
        $attempt = $this->submitAttempt($this->candidateInA);
        $this->actingAs($this->alpha)->grade($attempt, $this->shortEssay->id, '2')->assertSessionHasNoErrors();
        $revision = ExaminationEssayRevision::sole();
        try {
            $revision->reason = 'Tampered';
            $revision->save();
            $this->fail('Revision update was allowed.');
        } catch (LogicException) {
        }
        try {
            $revision->delete();
            $this->fail('Revision deletion was allowed.');
        } catch (LogicException) {
        }
        $this->assertNull(ExaminationEssayRevision::sole()->reason);
    }

    public function test_database_rejects_out_of_range_essay_scores(): void
    {
        $attempt = $this->submitAttempt($this->candidateInA);
        $this->actingAs($this->alpha)->grade($attempt, $this->shortEssay->id, '2')->assertSessionHasNoErrors();
        $this->expectException(QueryException::class);
        DB::table('examination_essay_grades')->update(['score' => 3.5]);
    }

    // Audit --------------------------------------------------------------------

    public function test_audit_entry_records_scores_without_feedback_answers_or_reason_text_in_values(): void
    {
        $attempt = $this->submitAttempt($this->candidateInA, essayText: 'Confidential synthetic answer');
        $this->actingAs($this->alpha);
        $this->grade($attempt, $this->shortEssay->id, '2', 0, ['comment' => 'Private feedback text'])->assertSessionHasNoErrors();
        $this->grade($attempt, $this->shortEssay->id, '1', 1, ['comment' => 'Private revised feedback', 'reason' => 'Rubric recheck'])->assertSessionHasNoErrors();

        $logs = AuditLog::where('action', 'examination.essay_graded')->orderBy('id')->get();
        $this->assertCount(2, $logs);
        $this->assertSame($this->alpha->id, (int) $logs[0]->actor_id);
        $this->assertSame($attempt->id, (int) $logs[0]->auditable_id);
        $this->assertSame('2.00', $logs[0]->new_values['score']);
        $this->assertSame('2.00', $logs[1]->old_values['score']);
        $this->assertSame('1.00', $logs[1]->new_values['score']);
        foreach ($logs as $log) {
            $encoded = json_encode([$log->old_values, $log->new_values]);
            $this->assertStringNotContainsString('Private', $encoded);
            $this->assertStringNotContainsString('Confidential synthetic answer', $encoded);
            $this->assertStringNotContainsString('comment', $encoded);
            $this->assertStringNotContainsString('scoring_key', $encoded);
        }
    }

    // Navigation -----------------------------------------------------------------

    public function test_next_pending_attempt_is_offered_and_followed_after_completion(): void
    {
        $first = $this->submitAttempt($this->candidateInA);
        $this->travel(1)->minutes();
        $second = $this->submitAttempt($this->secondCandidateInA);
        $this->actingAs($this->alpha);

        $this->get('/examination-attempts/'.$first->id.'/grading')->assertInertia(fn (Assert $page) => $page->where('nextAttemptId', $second->id));
        // The attempt is still pending after the first essay, so the redirect stays.
        $this->grade($first, $this->shortEssay->id, '2', 0, ['next' => true])->assertRedirect('/examination-attempts/'.$first->id.'/grading');
        $this->grade($first, $this->longEssay->id, '4', 0, ['next' => true])->assertRedirect('/examination-attempts/'.$second->id.'/grading');

        $this->grade($second, $this->shortEssay->id, '2')->assertRedirect('/examination-attempts/'.$second->id.'/grading');
        $this->grade($second, $this->longEssay->id, '4', 0, ['next' => true])->assertRedirect('/examination-attempts/'.$second->id.'/grading');
        $this->get('/examination-attempts/'.$second->id.'/grading')->assertInertia(fn (Assert $page) => $page->where('nextAttemptId', null));
    }

    public function test_next_pending_attempt_stays_within_the_same_examination(): void
    {
        $attempt = $this->submitAttempt($this->candidateInA);
        $otherExam = $this->createExamination($this->offeringA1, 'Other essay exam');
        $this->addItem($otherExam, Question::factory()->essay()->create(['subject_id' => $this->offeringA1->subject_id]), 1, '5');
        $this->submitAttempt($this->secondCandidateInA, exam: $otherExam);

        $this->actingAs($this->alpha)->get('/examination-attempts/'.$attempt->id.'/grading')->assertInertia(fn (Assert $page) => $page->where('nextAttemptId', null));
    }

    public function test_service_rejects_grading_directly_for_unsubmitted_attempt(): void
    {
        $attempt = app(CandidateAttemptService::class)->start($this->candidateInA->user, $this->exam, null);
        try {
            app(ManualEssayGradingService::class)->grade($this->alpha, $attempt, $this->shortEssay->id, '1', null, 0, null);
            $this->fail('Grading an in-progress attempt was allowed.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('attempt', $exception->errors());
        }
        $this->actingAs($this->alpha)->get('/examination-attempts/'.$attempt->id.'/grading')->assertStatus(422);
    }
}
