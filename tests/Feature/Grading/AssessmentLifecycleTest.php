<?php

namespace Tests\Feature\Grading;

use App\Enums\AssessmentStatus;
use App\Enums\AuditAction;
use App\Enums\CandidateStatus;
use App\Enums\Permission as PermissionCode;
use App\Enums\SystemRole;
use App\Models\Assessment;
use App\Models\AssessmentScore;
use App\Models\AssessmentScoreRevision;
use App\Models\AuditLog;
use App\Models\Candidate;
use App\Models\ClassSubject;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\Grading\AssessmentService;
use App\Services\Grading\ScoreRecordingService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Assessment lifecycle (Milestone 4): create, edit, delete, and finalize,
 * with authorization, validation, audit entries, the assessment page, and
 * the database constraints on the assessments table.
 */
class AssessmentLifecycleTest extends TestCase
{
    use BuildsGradingFixtures;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildGradingFixtures();
    }

    // ---------------------------------------------------------------------
    // Create page and store: authorization
    // ---------------------------------------------------------------------

    public function test_assigned_instructor_opens_the_create_page_with_the_subject_categories(): void
    {
        $this->actingAs($this->alpha)
            ->get($this->createUrl($this->offeringA1))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('staff/teaching/assessments/create')
                ->where('offering.id', $this->offeringA1->id)
                ->where('offering.classBatch.id', $this->batchA->id)
                ->where('offering.subject.code', 'SUBJ-1')
                ->has('categories', 2)
                ->where('categories.0.id', $this->quizzes->id)
                ->where('categories.0.name', 'Quizzes')
                ->where('categories.0.weight', '40')
                ->where('categories.0.assessmentCount', 0)
                ->where('categories.1.name', 'Examinations')
                ->where('categories.1.weight', '60'));
    }

    public function test_assigned_instructor_creates_a_draft_assessment_and_is_taken_to_its_page(): void
    {
        $response = $this->actingAs($this->alpha)
            ->post($this->storeUrl($this->offeringA1), $this->payload());

        $assessment = Assessment::query()->sole();

        $response->assertRedirect("/assessments/{$assessment->id}")
            ->assertInertiaFlash('toast.type', 'success');

        $this->assertSame($this->offeringA1->id, $assessment->class_subject_id);
        $this->assertSame($this->quizzes->id, $assessment->assessment_category_id);
        $this->assertSame('Quiz 1', $assessment->title);
        $this->assertSame('50.00', $assessment->max_score);
        $this->assertSame('2026-10-05', $assessment->assessed_on?->toDateString());
        $this->assertSame(AssessmentStatus::Draft, $assessment->status);
        $this->assertNull($assessment->finalized_at);
        $this->assertNull($assessment->finalized_by);
        $this->assertSame($this->alpha->id, $assessment->created_by);

        $entry = AuditLog::query()->where('action', AuditAction::AssessmentCreated->value)->sole();
        $this->assertSame($this->alpha->id, $entry->actor_id);
        $this->assertSame('assessment', $entry->auditable_type);
        $this->assertSame($assessment->id, (int) $entry->auditable_id);
        $this->assertSame(
            ['title' => 'Quiz 1', 'category' => 'Quizzes', 'max_score' => '50.00', 'assessed_on' => '2026-10-05'],
            $entry->new_values,
        );
    }

    public function test_store_ignores_submitted_status_owner_and_subject_fields(): void
    {
        $this->actingAs($this->alpha)
            ->post($this->storeUrl($this->offeringA1), $this->payload([
                'status' => AssessmentStatus::Finalized->value,
                'finalized_at' => '2026-09-01 10:00:00',
                'finalized_by' => $this->bravo->id,
                'created_by' => $this->bravo->id,
                'class_subject_id' => $this->offeringB1->id,
            ]))
            ->assertRedirect();

        $assessment = Assessment::query()->sole();
        $this->assertSame(AssessmentStatus::Draft, $assessment->status);
        $this->assertNull($assessment->finalized_at);
        $this->assertNull($assessment->finalized_by);
        $this->assertSame($this->alpha->id, $assessment->created_by);
        $this->assertSame($this->offeringA1->id, $assessment->class_subject_id);
    }

    public function test_instructor_cannot_create_assessments_for_a_subject_they_do_not_teach(): void
    {
        // Bravo teaches Subject 2 of Batch A, but not Subject 1.
        $this->actingAs($this->bravo)->get($this->createUrl($this->offeringA1))->assertForbidden();
        $this->actingAs($this->bravo)->post($this->storeUrl($this->offeringA1), $this->payload())->assertForbidden();

        // Alpha teaches Subject 1, but not in Batch B.
        $this->actingAs($this->alpha)->get($this->createUrl($this->offeringB1))->assertForbidden();
        $this->actingAs($this->alpha)->post($this->storeUrl($this->offeringB1), $this->payload())->assertForbidden();

        $this->assertDatabaseCount('assessments', 0);
    }

    public function test_administrators_cannot_create_assessments(): void
    {
        $superAdmin = $this->userWithRole(SystemRole::SuperAdministrator);

        foreach ([$this->academicAdmin, $superAdmin] as $administrator) {
            $this->actingAs($administrator)->get($this->createUrl($this->offeringA1))->assertForbidden();
            $this->actingAs($administrator)->post($this->storeUrl($this->offeringA1), $this->payload())->assertForbidden();
        }

        $this->assertDatabaseCount('assessments', 0);
    }

    public function test_assigned_teaching_staff_without_the_record_grades_permission_cannot_create_assessments(): void
    {
        $viewer = $this->teachingViewer($this->offeringA1);

        // Viewing the gradebook is still allowed, so the 403 below is about grades.record.
        $this->actingAs($viewer)
            ->get("/my-classes/{$this->batchA->id}/subjects/{$this->offeringA1->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('can.recordGrades', false));

        $this->actingAs($viewer)->get($this->createUrl($this->offeringA1))->assertForbidden();
        $this->actingAs($viewer)->post($this->storeUrl($this->offeringA1), $this->payload())->assertForbidden();

        $this->assertDatabaseCount('assessments', 0);
    }

    public function test_create_routes_reject_a_subject_that_is_not_in_the_given_class(): void
    {
        $this->actingAs($this->alpha)
            ->get("/my-classes/{$this->batchB->id}/subjects/{$this->offeringA1->id}/assessments/create")
            ->assertNotFound();

        $this->actingAs($this->alpha)
            ->post("/my-classes/{$this->batchB->id}/subjects/{$this->offeringA1->id}/assessments", $this->payload())
            ->assertNotFound();

        $this->assertDatabaseCount('assessments', 0);
    }

    public function test_subject_without_grading_setup_shows_no_categories_and_cannot_receive_assessments(): void
    {
        // Subject 2 of Batch A (Bravo) has no grading categories yet.
        $this->actingAs($this->bravo)
            ->get($this->createUrl($this->offeringA2))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('staff/teaching/assessments/create')
                ->where('offering.id', $this->offeringA2->id)
                ->where('categories', []));

        $this->actingAs($this->bravo)
            ->post($this->storeUrl($this->offeringA2), $this->payload(['assessment_category_id' => null]))
            ->assertSessionHasErrors('assessment_category_id');

        // A category of another subject is not accepted either.
        $this->actingAs($this->bravo)
            ->post($this->storeUrl($this->offeringA2), $this->payload(['assessment_category_id' => $this->quizzes->id]))
            ->assertSessionHasErrors('assessment_category_id');

        $this->assertDatabaseCount('assessments', 0);
    }

    // ---------------------------------------------------------------------
    // Validation
    // ---------------------------------------------------------------------

    public function test_title_is_required_trimmed_and_limited_in_length(): void
    {
        foreach (['', '   ', str_repeat('T', 151)] as $title) {
            $this->actingAs($this->alpha)
                ->post($this->storeUrl($this->offeringA1), $this->payload(['title' => $title]))
                ->assertSessionHasErrors('title');
        }
        $this->assertDatabaseCount('assessments', 0);

        $this->actingAs($this->alpha)
            ->post($this->storeUrl($this->offeringA1), $this->payload(['title' => '  Quiz 2  ']))
            ->assertSessionHasNoErrors();
        $this->assertSame('Quiz 2', Assessment::query()->sole()->title);

        $this->actingAs($this->alpha)
            ->post($this->storeUrl($this->offeringA1), $this->payload(['title' => str_repeat('T', 150)]))
            ->assertSessionHasNoErrors();
        $this->assertDatabaseCount('assessments', 2);
    }

    public function test_title_must_be_unique_within_the_subject_but_may_repeat_in_another_subject(): void
    {
        $this->createAssessment($this->quizzes, 'Quiz 1');

        $this->actingAs($this->alpha)
            ->post($this->storeUrl($this->offeringA1), $this->payload(['title' => 'Quiz 1', 'assessment_category_id' => $this->examinations->id]))
            ->assertSessionHasErrors('title');
        $this->assertSame(1, $this->offeringA1->assessments()->count());

        // The same title in Subject 1 of Batch B is fine.
        $this->setScheme($this->offeringB1, ['Quizzes' => '100']);
        $categoryB1 = $this->offeringB1->assessmentCategories()->sole();

        $this->actingAs($this->bravo)
            ->post($this->storeUrl($this->offeringB1), $this->payload(['title' => 'Quiz 1', 'assessment_category_id' => $categoryB1->id]))
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $this->assertSame(1, $this->offeringB1->assessments()->where('title', 'Quiz 1')->count());
    }

    public function test_category_must_belong_to_the_same_subject(): void
    {
        $this->setScheme($this->offeringB1, ['Quizzes' => '100']);
        $categoryB1 = $this->offeringB1->assessmentCategories()->sole();

        foreach ([$categoryB1->id, 999999, 'abc'] as $categoryId) {
            $this->actingAs($this->alpha)
                ->post($this->storeUrl($this->offeringA1), $this->payload(['assessment_category_id' => $categoryId]))
                ->assertSessionHasErrors('assessment_category_id');
        }

        $this->assertDatabaseCount('assessments', 0);
    }

    public function test_service_rechecks_the_category_and_title_rules_behind_the_form_request(): void
    {
        $this->setScheme($this->offeringB1, ['Quizzes' => '100']);
        $categoryB1 = $this->offeringB1->assessmentCategories()->sole();
        $service = $this->app->make(AssessmentService::class);

        try {
            $service->create($this->offeringA1, [
                'title' => 'Quiz 1',
                'assessment_category_id' => $categoryB1->id,
                'max_score' => '50',
                'assessed_on' => null,
            ], $this->alpha);
            $this->fail('A category of another class subject must be rejected by the service.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('assessment_category_id', $exception->errors());
        }

        // Two requests passing validation at once: the unique index decides,
        // and the loser receives a normal validation error.
        $this->createAssessment($this->quizzes, 'Quiz 1');
        try {
            $this->createAssessment($this->examinations, 'Quiz 1');
            $this->fail('A duplicate title must be rejected by the service.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('title', $exception->errors());
        }

        $this->assertSame(1, $this->offeringA1->assessments()->count());
        $this->assertSame(1, AuditLog::query()->where('action', AuditAction::AssessmentCreated->value)->count());
    }

    public function test_deactivated_instructor_cannot_create_or_change_assessments(): void
    {
        $assessment = $this->createAssessment($this->quizzes, 'Quiz 1');
        $this->recordScores($assessment, [$this->candidateInA->id => '42']);
        $this->alpha->forceFill(['is_active' => false])->save();

        $this->actingAs($this->alpha)->post($this->storeUrl($this->offeringA1), $this->payload(['title' => 'Quiz 2']))->assertRedirect('/login');
        $this->actingAs($this->alpha)->post("/assessments/{$assessment->id}/finalize")->assertRedirect('/login');
        $this->actingAs($this->alpha)->delete("/assessments/{$assessment->id}")->assertRedirect('/login');

        $this->assertSame(1, Assessment::query()->count());
        $this->assertSame(AssessmentStatus::Draft, $assessment->refresh()->status);
    }

    public function test_maximum_score_must_be_positive_at_most_9999_99_with_two_decimals(): void
    {
        foreach (['', '0', '0.00', '-5', '10000', '9999.999', '12.345', 'abc'] as $maxScore) {
            $this->actingAs($this->alpha)
                ->post($this->storeUrl($this->offeringA1), $this->payload(['title' => "Invalid {$maxScore}", 'max_score' => $maxScore]))
                ->assertSessionHasErrors('max_score');
        }
        $this->assertDatabaseCount('assessments', 0);

        foreach (['0.01' => '0.01', '9999.99' => '9999.99', '37.5' => '37.50'] as $maxScore => $stored) {
            $this->actingAs($this->alpha)
                ->post($this->storeUrl($this->offeringA1), $this->payload(['title' => "Valid {$maxScore}", 'max_score' => $maxScore]))
                ->assertSessionHasNoErrors();

            $this->assertSame($stored, Assessment::query()->where('title', "Valid {$maxScore}")->sole()->max_score);
        }
    }

    public function test_date_is_optional_and_must_use_the_year_month_day_format(): void
    {
        foreach (['2026/10/05', '05-10-2026', '2026-02-30', '2026-13-01', 'next week'] as $date) {
            $this->actingAs($this->alpha)
                ->post($this->storeUrl($this->offeringA1), $this->payload(['title' => "Dated {$date}", 'assessed_on' => $date]))
                ->assertSessionHasErrors('assessed_on');
        }
        $this->assertDatabaseCount('assessments', 0);

        $this->actingAs($this->alpha)
            ->post($this->storeUrl($this->offeringA1), $this->payload(['title' => 'Undated', 'assessed_on' => null]))
            ->assertSessionHasNoErrors();
        $this->assertNull(Assessment::query()->where('title', 'Undated')->sole()->assessed_on);

        $this->actingAs($this->alpha)
            ->post($this->storeUrl($this->offeringA1), $this->payload(['title' => 'Empty date', 'assessed_on' => '']))
            ->assertSessionHasNoErrors();
        $this->assertNull(Assessment::query()->where('title', 'Empty date')->sole()->assessed_on);
    }

    // ---------------------------------------------------------------------
    // Edit and update (drafts only)
    // ---------------------------------------------------------------------

    public function test_edit_page_of_a_draft_shows_its_details_and_highest_recorded_score(): void
    {
        $assessment = $this->createAssessment($this->quizzes, 'Quiz 1', '50', '2026-10-05');
        $this->recordScores($assessment, [$this->candidateInA->id => '45', $this->secondInA->id => '30.5']);

        $this->actingAs($this->alpha)
            ->get("/assessments/{$assessment->id}/edit")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('staff/teaching/assessments/edit')
                ->where('offering.id', $this->offeringA1->id)
                ->has('categories', 2)
                ->where('assessment.id', $assessment->id)
                ->where('assessment.title', 'Quiz 1')
                ->where('assessment.category.id', $this->quizzes->id)
                ->where('assessment.maxScore', '50')
                ->where('assessment.assessedOn', '2026-10-05')
                ->where('assessment.status.value', 'draft')
                ->where('assessment.highestScore', '45'));
    }

    public function test_updating_a_draft_saves_the_details_and_audits_only_what_changed(): void
    {
        $assessment = $this->createAssessment($this->quizzes, 'Quiz 1', '50', '2026-10-05');

        $this->actingAs($this->alpha)
            ->put("/assessments/{$assessment->id}", $this->payload([
                'title' => 'Midterm Examination',
                'assessment_category_id' => $this->examinations->id,
                'max_score' => '80.5',
                'assessed_on' => '2026-10-05',
            ]))
            ->assertSessionHasNoErrors()
            ->assertRedirect("/assessments/{$assessment->id}")
            ->assertInertiaFlash('toast.type', 'success');

        $assessment->refresh();
        $this->assertSame('Midterm Examination', $assessment->title);
        $this->assertSame($this->examinations->id, $assessment->assessment_category_id);
        $this->assertSame('80.50', $assessment->max_score);
        $this->assertSame(AssessmentStatus::Draft, $assessment->status);

        $entry = AuditLog::query()->where('action', AuditAction::AssessmentUpdated->value)->sole();
        $this->assertSame($this->alpha->id, $entry->actor_id);
        $this->assertSame('assessment', $entry->auditable_type);
        $this->assertSame($assessment->id, (int) $entry->auditable_id);
        $this->assertSame(['title' => 'Quiz 1', 'category' => 'Quizzes', 'max_score' => '50.00'], $entry->old_values);
        $this->assertSame(['title' => 'Midterm Examination', 'category' => 'Examinations', 'max_score' => '80.50'], $entry->new_values);
    }

    public function test_saving_a_draft_without_changes_writes_no_audit_entry(): void
    {
        $assessment = $this->createAssessment($this->quizzes, 'Quiz 1', '50', '2026-10-05');

        $this->actingAs($this->alpha)
            ->put("/assessments/{$assessment->id}", $this->payload(['max_score' => '50.00']))
            ->assertSessionHasNoErrors()
            ->assertRedirect("/assessments/{$assessment->id}");

        $this->assertSame(0, AuditLog::query()->where('action', AuditAction::AssessmentUpdated->value)->count());
    }

    public function test_update_validates_title_uniqueness_and_category_of_the_same_subject(): void
    {
        $this->createAssessment($this->quizzes, 'Quiz 1');
        $quiz2 = $this->createAssessment($this->quizzes, 'Quiz 2');

        $this->actingAs($this->alpha)
            ->put("/assessments/{$quiz2->id}", $this->payload(['title' => 'Quiz 1']))
            ->assertSessionHasErrors('title');

        $this->setScheme($this->offeringB1, ['Quizzes' => '100']);
        $this->actingAs($this->alpha)
            ->put("/assessments/{$quiz2->id}", $this->payload([
                'title' => 'Quiz 2',
                'assessment_category_id' => $this->offeringB1->assessmentCategories()->sole()->id,
            ]))
            ->assertSessionHasErrors('assessment_category_id');

        $this->actingAs($this->alpha)
            ->put("/assessments/{$quiz2->id}", $this->payload(['title' => 'Quiz 2', 'max_score' => '0']))
            ->assertSessionHasErrors('max_score');

        $quiz2->refresh();
        $this->assertSame('Quiz 2', $quiz2->title);
        $this->assertSame($this->quizzes->id, $quiz2->assessment_category_id);
        $this->assertSame('50.00', $quiz2->max_score);
    }

    public function test_maximum_score_cannot_be_lowered_below_the_highest_recorded_score(): void
    {
        $assessment = $this->createAssessment($this->quizzes, 'Quiz 1', '50');
        $this->recordScores($assessment, [$this->candidateInA->id => '45.5', $this->secondInA->id => '30']);

        $this->actingAs($this->alpha)
            ->put("/assessments/{$assessment->id}", $this->payload(['max_score' => '45.49']))
            ->assertSessionHasErrors('max_score');
        $this->assertSame('50.00', $assessment->refresh()->max_score);
        $this->assertSame(0, AuditLog::query()->where('action', AuditAction::AssessmentUpdated->value)->count());

        // Equal to the highest recorded score is allowed.
        $this->actingAs($this->alpha)
            ->put("/assessments/{$assessment->id}", $this->payload(['max_score' => '45.5']))
            ->assertSessionHasNoErrors();
        $this->assertSame('45.50', $assessment->refresh()->max_score);
    }

    public function test_finalized_assessment_cannot_be_edited(): void
    {
        $assessment = $this->finalizedAssessment();

        $this->actingAs($this->alpha)
            ->get("/assessments/{$assessment->id}/edit")
            ->assertRedirect("/assessments/{$assessment->id}")
            ->assertInertiaFlash('toast.type', 'warning');

        $this->actingAs($this->alpha)
            ->from("/assessments/{$assessment->id}")
            ->put("/assessments/{$assessment->id}", $this->payload(['title' => 'Renamed', 'max_score' => '100']))
            ->assertRedirect("/assessments/{$assessment->id}")
            ->assertSessionHasErrors('assessment');

        $assessment->refresh();
        $this->assertSame('Quiz 1', $assessment->title);
        $this->assertSame('50.00', $assessment->max_score);
        $this->assertSame(0, AuditLog::query()->where('action', AuditAction::AssessmentUpdated->value)->count());
    }

    // ---------------------------------------------------------------------
    // Delete (drafts only)
    // ---------------------------------------------------------------------

    public function test_deleting_a_draft_removes_its_scores_and_revisions_and_keeps_a_snapshot_in_the_audit_log(): void
    {
        $assessment = $this->createAssessment($this->quizzes, 'Quiz 1', '50', '2026-10-05');
        $this->recordScores($assessment, [$this->candidateInA->id => '45']);
        $this->recordScores($assessment, [$this->candidateInA->id => '47.5']);
        $this->app->make(ScoreRecordingService::class)->recordDraftScores($assessment, [
            $this->secondInA->id => ['score' => null, 'comment' => 'Absent', 'expected_score' => null, 'expected_comment' => null],
        ], $this->alpha);
        $other = $this->createAssessment($this->quizzes, 'Quiz 2');
        $this->recordScores($other, [$this->candidateInA->id => '20']);

        $scoreIds = $assessment->scores()->pluck('id')->all();
        $this->assertCount(2, $scoreIds);
        $this->assertGreaterThanOrEqual(3, AssessmentScoreRevision::query()->whereIn('assessment_score_id', $scoreIds)->count());

        $this->actingAs($this->alpha)
            ->delete("/assessments/{$assessment->id}")
            ->assertRedirect("/my-classes/{$this->batchA->id}/subjects/{$this->offeringA1->id}")
            ->assertInertiaFlash('toast.type', 'success');

        $this->assertDatabaseMissing('assessments', ['id' => $assessment->id]);
        $this->assertSame(0, AssessmentScore::query()->where('assessment_id', $assessment->id)->count());
        $this->assertSame(0, AssessmentScoreRevision::query()->whereIn('assessment_score_id', $scoreIds)->count());

        // Other assessments are untouched.
        $this->assertSame(1, $other->scores()->count());
        $this->assertSame(1, AssessmentScoreRevision::query()->whereIn('assessment_score_id', $other->scores()->select('id'))->count());

        $expectedScores = [
            // Staff comments are not copied into the general audit log.
            ['candidate' => $this->candidateInA->candidate_number, 'score' => '47.50'],
            ['candidate' => $this->secondInA->candidate_number, 'score' => null],
        ];
        usort($expectedScores, fn (array $first, array $second): int => strcmp($first['candidate'], $second['candidate']));

        $entry = AuditLog::query()->where('action', AuditAction::AssessmentDeleted->value)->sole();
        $this->assertSame($this->alpha->id, $entry->actor_id);
        $this->assertSame('assessment', $entry->auditable_type);
        $this->assertSame($assessment->id, (int) $entry->auditable_id);
        $this->assertSame([
            'title' => 'Quiz 1',
            'category' => 'Quizzes',
            'max_score' => '50.00',
            'assessed_on' => '2026-10-05',
            'scores' => $expectedScores,
        ], $entry->old_values);
    }

    public function test_draft_without_scores_can_be_deleted(): void
    {
        $assessment = $this->createAssessment($this->quizzes, 'Quiz 1');

        $this->actingAs($this->alpha)
            ->delete("/assessments/{$assessment->id}")
            ->assertSessionHasNoErrors()
            ->assertRedirect("/my-classes/{$this->batchA->id}/subjects/{$this->offeringA1->id}");

        $this->assertDatabaseMissing('assessments', ['id' => $assessment->id]);
        $entry = AuditLog::query()->where('action', AuditAction::AssessmentDeleted->value)->sole();
        $this->assertSame([], $entry->old_values['scores']);
    }

    public function test_finalized_assessment_cannot_be_deleted(): void
    {
        $assessment = $this->finalizedAssessment();

        $this->actingAs($this->alpha)
            ->from("/assessments/{$assessment->id}")
            ->delete("/assessments/{$assessment->id}")
            ->assertRedirect("/assessments/{$assessment->id}")
            ->assertSessionHasErrors('assessment');

        $this->assertDatabaseHas('assessments', ['id' => $assessment->id, 'status' => 'finalized']);
        $this->assertSame(1, $assessment->scores()->count());
        $this->assertSame(0, AuditLog::query()->where('action', AuditAction::AssessmentDeleted->value)->count());
    }

    // ---------------------------------------------------------------------
    // Finalize
    // ---------------------------------------------------------------------

    public function test_finalizing_makes_the_assessment_final_and_audits_the_score_counts(): void
    {
        $assessment = $this->createAssessment($this->quizzes, 'Quiz 1', '50');
        $this->recordScores($assessment, [$this->candidateInA->id => '42']);
        // A comment without a score (absence) does not count as a recorded score.
        $this->app->make(ScoreRecordingService::class)->recordDraftScores($assessment, [
            $this->secondInA->id => ['score' => null, 'comment' => 'Absent', 'expected_score' => null, 'expected_comment' => null],
        ], $this->alpha);

        $this->actingAs($this->alpha)
            ->post("/assessments/{$assessment->id}/finalize")
            ->assertSessionHasNoErrors()
            ->assertRedirect("/assessments/{$assessment->id}")
            ->assertInertiaFlash('toast.type', 'success');

        $assessment->refresh();
        $this->assertSame(AssessmentStatus::Finalized, $assessment->status);
        $this->assertNotNull($assessment->finalized_at);
        $this->assertSame($this->alpha->id, $assessment->finalized_by);

        $entry = AuditLog::query()->where('action', AuditAction::AssessmentFinalized->value)->sole();
        $this->assertSame($this->alpha->id, $entry->actor_id);
        $this->assertSame('assessment', $entry->auditable_type);
        $this->assertSame($assessment->id, (int) $entry->auditable_id);
        // A2 (comment only) is without a score; the withdrawn A3 is not counted.
        $this->assertSame(['title' => 'Quiz 1', 'scores_recorded' => 1, 'candidates_without_score' => 1], $entry->new_values);
    }

    public function test_candidates_without_score_ignores_withdrawn_candidates_and_candidates_of_other_classes(): void
    {
        $assessment = $this->createAssessment($this->quizzes, 'Quiz 1', '50');
        $this->recordScores($assessment, [$this->candidateInA->id => '42', $this->secondInA->id => '35']);

        // Another enrolled candidate joins Batch A without a score.
        Candidate::factory()->create(['class_batch_id' => $this->batchA->id, 'last_name' => 'A5']);
        // Another withdrawn candidate in Batch A, and an enrolled one in Batch B.
        Candidate::factory()->create(['class_batch_id' => $this->batchA->id, 'last_name' => 'A6', 'status' => CandidateStatus::Withdrawn->value]);
        Candidate::factory()->create(['class_batch_id' => $this->batchB->id, 'last_name' => 'B2']);

        $this->actingAs($this->alpha)
            ->post("/assessments/{$assessment->id}/finalize")
            ->assertSessionHasNoErrors();

        $entry = AuditLog::query()->where('action', AuditAction::AssessmentFinalized->value)->sole();
        $this->assertSame(2, $entry->new_values['scores_recorded']);
        $this->assertSame(1, $entry->new_values['candidates_without_score']);
    }

    public function test_finalizing_requires_at_least_one_recorded_score(): void
    {
        $assessment = $this->createAssessment($this->quizzes, 'Quiz 1');

        $this->actingAs($this->alpha)
            ->from("/assessments/{$assessment->id}")
            ->post("/assessments/{$assessment->id}/finalize")
            ->assertRedirect("/assessments/{$assessment->id}")
            ->assertSessionHasErrors('assessment');

        // Comments without scores are still not enough.
        $this->app->make(ScoreRecordingService::class)->recordDraftScores($assessment, [
            $this->candidateInA->id => ['score' => null, 'comment' => 'Absent', 'expected_score' => null, 'expected_comment' => null],
        ], $this->alpha);

        $this->actingAs($this->alpha)
            ->post("/assessments/{$assessment->id}/finalize")
            ->assertSessionHasErrors('assessment');

        $assessment->refresh();
        $this->assertSame(AssessmentStatus::Draft, $assessment->status);
        $this->assertNull($assessment->finalized_at);
        $this->assertNull($assessment->finalized_by);
        $this->assertSame(0, AuditLog::query()->where('action', AuditAction::AssessmentFinalized->value)->count());
    }

    public function test_finalized_assessment_cannot_be_finalized_again(): void
    {
        $assessment = $this->finalizedAssessment();
        $finalizedAt = $assessment->finalized_at?->toIso8601String();

        $this->travel(1)->hours();

        $this->actingAs($this->alpha)
            ->from("/assessments/{$assessment->id}")
            ->post("/assessments/{$assessment->id}/finalize")
            ->assertRedirect("/assessments/{$assessment->id}")
            ->assertSessionHasErrors('assessment');

        $assessment->refresh();
        $this->assertSame(AssessmentStatus::Finalized, $assessment->status);
        $this->assertSame($finalizedAt, $assessment->finalized_at?->toIso8601String());
        $this->assertSame($this->alpha->id, $assessment->finalized_by);
        $this->assertSame(1, AuditLog::query()->where('action', AuditAction::AssessmentFinalized->value)->count());
    }

    // ---------------------------------------------------------------------
    // Authorization of edit, update, delete, finalize
    // ---------------------------------------------------------------------

    public function test_only_assigned_instructors_with_record_grades_may_change_an_assessment(): void
    {
        $assessment = $this->createAssessment($this->quizzes, 'Quiz 1', '50');
        $this->recordScores($assessment, [$this->candidateInA->id => '42']);

        $outsiders = [
            'other instructor' => $this->bravo,
            'academic administrator' => $this->academicAdmin,
            'super administrator' => $this->userWithRole(SystemRole::SuperAdministrator),
            'teaching viewer without grades.record' => $this->teachingViewer($this->offeringA1),
            'candidate' => $this->candidateInA->user,
        ];

        foreach ($outsiders as $label => $user) {
            $this->actingAs($user)->get("/assessments/{$assessment->id}/edit")->assertForbidden();
            $this->actingAs($user)->put("/assessments/{$assessment->id}", $this->payload(['title' => "Renamed by {$label}"]))->assertForbidden();
            $this->actingAs($user)->post("/assessments/{$assessment->id}/finalize")->assertForbidden();
            $this->actingAs($user)->delete("/assessments/{$assessment->id}")->assertForbidden();
        }

        $assessment->refresh();
        $this->assertSame('Quiz 1', $assessment->title);
        $this->assertSame(AssessmentStatus::Draft, $assessment->status);
        $this->assertSame(1, $assessment->scores()->count());
    }

    public function test_instructor_loses_access_to_the_assessment_when_the_assignment_is_removed(): void
    {
        $assessment = $this->createAssessment($this->quizzes, 'Quiz 1');

        $this->offeringA1->instructorAssignments()->where('instructor_id', $this->alpha->id)->sole()->delete();

        $this->actingAs($this->alpha)->get("/assessments/{$assessment->id}")->assertForbidden();
        $this->actingAs($this->alpha)->get("/assessments/{$assessment->id}/edit")->assertForbidden();
        $this->actingAs($this->alpha)->delete("/assessments/{$assessment->id}")->assertForbidden();
        $this->assertDatabaseHas('assessments', ['id' => $assessment->id]);
    }

    // ---------------------------------------------------------------------
    // Assessment page
    // ---------------------------------------------------------------------

    public function test_assessment_page_of_a_draft_lists_the_gradable_candidates(): void
    {
        $assessment = $this->createAssessment($this->quizzes, 'Quiz 1', '50', '2026-10-05');
        $this->recordScores($assessment, [$this->candidateInA->id => '45']);
        $this->recordScores($assessment, [$this->candidateInA->id => '40']);

        $this->actingAs($this->alpha)
            ->get("/assessments/{$assessment->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('staff/teaching/assessments/show')
                ->where('offering.id', $this->offeringA1->id)
                ->where('assessment.id', $assessment->id)
                ->where('assessment.title', 'Quiz 1')
                ->where('assessment.category.name', 'Quizzes')
                ->where('assessment.category.weight', '40')
                ->where('assessment.maxScore', '50')
                ->where('assessment.assessedOn', '2026-10-05')
                ->where('assessment.status.value', 'draft')
                ->where('assessment.createdBy', 'Instructor Alpha')
                ->where('assessment.finalizedBy', null)
                ->where('assessment.finalizedAt', null)
                ->where('can.manage', true)
                // A1 and A2; the withdrawn A3 without a score is not listed.
                ->has('roster', 2)
                ->where('roster.0.candidate.id', $this->candidateInA->id)
                ->where('roster.0.score', '40')
                ->where('roster.0.gradable', true)
                ->where('roster.1.candidate.id', $this->secondInA->id)
                ->where('roster.1.score', null)
                ->where('roster.1.gradable', true)
                // The first value is not a change; the update from 45 to 40 is.
                ->where('history.total', 1)
                ->has('history.entries', 1)
                ->where('history.entries.0.kind.value', 'updated')
                ->where('history.entries.0.previousScore', '45')
                ->where('history.entries.0.newScore', '40'));
    }

    public function test_assessment_page_of_a_finalized_assessment_keeps_scores_of_candidates_who_left_the_class(): void
    {
        $leaver = Candidate::factory()->create(['class_batch_id' => $this->batchA->id, 'last_name' => 'A4']);
        $assessment = $this->createAssessment($this->quizzes, 'Quiz 1', '50');
        $this->recordScores($assessment, [
            $this->candidateInA->id => '45',
            $this->secondInA->id => '30',
            $leaver->id => '25',
        ]);
        $this->finalize($assessment);

        // A2 moves to Batch B; A4 withdraws. Their recorded scores stay visible, read-only.
        Candidate::query()->whereKey($this->secondInA->id)->update(['class_batch_id' => $this->batchB->id]);
        Candidate::query()->whereKey($leaver->id)->update(['status' => CandidateStatus::Withdrawn->value]);

        $this->actingAs($this->alpha)
            ->get("/assessments/{$assessment->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('staff/teaching/assessments/show')
                ->where('assessment.status.value', 'finalized')
                ->where('assessment.finalizedBy', 'Instructor Alpha')
                ->where('assessment.finalizedAt', fn (?string $finalizedAt): bool => $finalizedAt !== null)
                ->where('can.manage', true)
                // A1 (gradable), A2 (other class, read-only), A4 (withdrawn, read-only).
                // A3 (withdrawn, no score) and B1 (other class, no score) are not listed.
                ->has('roster', 3)
                ->where('roster.0.candidate.id', $this->candidateInA->id)
                ->where('roster.0.gradable', true)
                ->where('roster.0.score', '45')
                ->where('roster.1.candidate.id', $this->secondInA->id)
                ->where('roster.1.gradable', false)
                ->where('roster.1.score', '30')
                ->where('roster.2.candidate.id', $leaver->id)
                ->where('roster.2.gradable', false)
                ->where('roster.2.score', '25')
                ->where('roster.2.candidate.status.value', 'withdrawn')
                ->where('history.total', 0)
                ->where('history.entries', []));
    }

    public function test_teaching_viewer_without_record_grades_sees_the_assessment_read_only(): void
    {
        $viewer = $this->teachingViewer($this->offeringA1);
        $assessment = $this->createAssessment($this->quizzes, 'Quiz 1');

        $this->actingAs($viewer)
            ->get("/assessments/{$assessment->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('staff/teaching/assessments/show')
                ->where('can.manage', false));
    }

    public function test_assessment_page_is_hidden_from_unrelated_staff_and_candidates(): void
    {
        $assessment = $this->createAssessment($this->quizzes, 'Quiz 1');

        $this->actingAs($this->bravo)->get("/assessments/{$assessment->id}")->assertForbidden();
        $this->actingAs($this->academicAdmin)->get("/assessments/{$assessment->id}")->assertForbidden();
        $this->actingAs($this->userWithRole(SystemRole::SuperAdministrator))->get("/assessments/{$assessment->id}")->assertForbidden();
        $this->actingAs($this->candidateInA->user)->get("/assessments/{$assessment->id}")->assertForbidden();

        $this->actingAs($this->alpha)->get('/assessments/999999')->assertNotFound();
    }

    // ---------------------------------------------------------------------
    // Database constraints
    // ---------------------------------------------------------------------

    public function test_database_accepts_a_valid_assessment_row(): void
    {
        $this->insertAssessment(['title' => 'Raw draft']);
        $this->insertAssessment([
            'title' => 'Raw finalized',
            'status' => 'finalized',
            'finalized_at' => now(),
            'finalized_by' => $this->alpha->id,
        ]);

        $this->assertSame(2, $this->offeringA1->assessments()->count());
    }

    public function test_database_rejects_invalid_maximum_scores(): void
    {
        $this->assertRejectedByDatabase(fn () => $this->insertAssessment(['max_score' => '0']), 'max_score = 0 must be rejected.');
        $this->assertRejectedByDatabase(fn () => $this->insertAssessment(['max_score' => '-1']), 'A negative max_score must be rejected.');
        $this->assertRejectedByDatabase(fn () => $this->insertAssessment(['max_score' => '10000']), 'max_score above 9999.99 must be rejected.');

        $this->assessmentRowUpdateRejected(['max_score' => '0'], 'Updating max_score to 0 must be rejected.');

        $this->assertDatabaseMissing('assessments', ['max_score' => '0.00']);
    }

    public function test_database_rejects_an_unknown_status(): void
    {
        $this->assertRejectedByDatabase(fn () => $this->insertAssessment(['status' => 'archived']), 'An unknown status must be rejected.');

        $this->assessmentRowUpdateRejected(['status' => 'published'], 'Updating to an unknown status must be rejected.');
    }

    public function test_database_requires_finalization_details_exactly_when_finalized(): void
    {
        $this->assertRejectedByDatabase(
            fn () => $this->insertAssessment(['status' => 'finalized']),
            'A finalized assessment without finalized_at and finalized_by must be rejected.',
        );
        $this->assertRejectedByDatabase(
            fn () => $this->insertAssessment(['status' => 'finalized', 'finalized_at' => now()]),
            'A finalized assessment without finalized_by must be rejected.',
        );
        $this->assertRejectedByDatabase(
            fn () => $this->insertAssessment(['status' => 'finalized', 'finalized_by' => $this->alpha->id]),
            'A finalized assessment without finalized_at must be rejected.',
        );
        $this->assertRejectedByDatabase(
            fn () => $this->insertAssessment(['status' => 'draft', 'finalized_at' => now(), 'finalized_by' => $this->alpha->id]),
            'A draft with finalization details must be rejected.',
        );

        // Reverting a finalized assessment to draft while keeping its details is rejected too.
        $assessment = $this->finalizedAssessment();
        $this->assertRejectedByDatabase(
            fn () => DB::table('assessments')->where('id', $assessment->id)->update(['status' => 'draft']),
            'A draft with finalization details must be rejected.',
        );
        $this->assertDatabaseHas('assessments', ['id' => $assessment->id, 'status' => 'finalized']);
    }

    public function test_database_rejects_a_category_of_another_class_subject(): void
    {
        $this->setScheme($this->offeringB1, ['Quizzes' => '100']);
        $categoryB1 = $this->offeringB1->assessmentCategories()->sole();

        $this->assertRejectedByDatabase(
            fn () => $this->insertAssessment(['assessment_category_id' => $categoryB1->id]),
            'A category of Batch B Subject 1 must not be usable for Batch A Subject 1.',
        );
        $this->assertRejectedByDatabase(
            fn () => $this->insertAssessment(['class_subject_id' => $this->offeringB1->id, 'assessment_category_id' => $this->quizzes->id]),
            'A category of Batch A Subject 1 must not be usable for Batch B Subject 1.',
        );
        $this->assertRejectedByDatabase(
            fn () => $this->insertAssessment(['assessment_category_id' => 999999]),
            'An unknown category must be rejected.',
        );

        // Moving an existing assessment into another subject while keeping its category is rejected.
        $assessment = $this->createAssessment($this->quizzes, 'Quiz 1');
        $this->assertRejectedByDatabase(
            fn () => DB::table('assessments')->where('id', $assessment->id)->update(['class_subject_id' => $this->offeringB1->id]),
            'Moving an assessment away from its category subject must be rejected.',
        );

        $this->insertAssessment(['class_subject_id' => $this->offeringB1->id, 'assessment_category_id' => $categoryB1->id]);
        $this->assertSame(1, $this->offeringB1->assessments()->count());
    }

    public function test_database_rejects_a_duplicate_title_within_a_class_subject(): void
    {
        $this->insertAssessment(['title' => 'Quiz 1']);

        $this->assertRejectedByDatabase(
            fn () => $this->insertAssessment(['title' => 'Quiz 1', 'assessment_category_id' => $this->examinations->id]),
            'A duplicate title in the same class subject must be rejected.',
        );

        $this->setScheme($this->offeringB1, ['Quizzes' => '100']);
        $this->insertAssessment([
            'title' => 'Quiz 1',
            'class_subject_id' => $this->offeringB1->id,
            'assessment_category_id' => $this->offeringB1->assessmentCategories()->sole()->id,
        ]);

        $this->assertSame(2, Assessment::query()->where('title', 'Quiz 1')->count());
    }

    public function test_database_protects_the_category_and_creator_of_an_assessment(): void
    {
        $assessment = $this->createAssessment($this->quizzes, 'Quiz 1');

        $this->assertRejectedByDatabase(
            fn () => DB::table('assessment_categories')->where('id', $this->quizzes->id)->delete(),
            'A category with assessments must not be deletable.',
        );
        $this->assertRejectedByDatabase(
            fn () => DB::table('assessments')->where('id', $assessment->id)->update(['created_by' => 999999]),
            'The creator must be an existing user.',
        );

        $this->assertDatabaseHas('assessment_categories', ['id' => $this->quizzes->id]);
    }

    // ---------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------

    private function createUrl(ClassSubject $offering): string
    {
        return "/my-classes/{$offering->class_batch_id}/subjects/{$offering->id}/assessments/create";
    }

    private function storeUrl(ClassSubject $offering): string
    {
        return "/my-classes/{$offering->class_batch_id}/subjects/{$offering->id}/assessments";
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return [
            'title' => 'Quiz 1',
            'assessment_category_id' => $this->quizzes->id,
            'max_score' => '50',
            'assessed_on' => '2026-10-05',
            ...$overrides,
        ];
    }

    /**
     * A finalized "Quiz 1" (max 50) with a score for candidate A1.
     */
    private function finalizedAssessment(): Assessment
    {
        $assessment = $this->createAssessment($this->quizzes, 'Quiz 1', '50', '2026-10-05');
        $this->recordScores($assessment, [$this->candidateInA->id => '42']);
        $this->finalize($assessment);

        return $assessment;
    }

    /**
     * Teaching staff with a custom role that grants the staff area and
     * teaching but not grades.record, assigned to the given subject.
     */
    private function teachingViewer(ClassSubject $offering): User
    {
        $role = Role::query()->create(['code' => 'teaching_viewer', 'name' => 'Teaching Viewer']);
        $role->permissions()->sync(
            Permission::query()
                ->whereIn('code', [PermissionCode::AccessStaffArea->value, PermissionCode::TeachClasses->value])
                ->pluck('id')
                ->all(),
        );

        $viewer = User::factory()->create(['role_id' => $role->id, 'name' => 'Instructor Viewer']);
        $this->teach($viewer, $offering);

        return $viewer;
    }

    /**
     * Inserts an assessment row directly, bypassing the application, so the
     * database constraints themselves are exercised.
     *
     * @param  array<string, mixed>  $overrides
     */
    private function insertAssessment(array $overrides = []): void
    {
        static $sequence = 0;
        $sequence++;

        DB::table('assessments')->insert([
            'class_subject_id' => $this->offeringA1->id,
            'assessment_category_id' => $this->quizzes->id,
            'title' => "Raw assessment {$sequence}",
            'max_score' => '50.00',
            'assessed_on' => null,
            'status' => 'draft',
            'finalized_at' => null,
            'finalized_by' => null,
            'created_by' => $this->alpha->id,
            'created_at' => now(),
            'updated_at' => now(),
            ...$overrides,
        ]);
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function assessmentRowUpdateRejected(array $values, string $message): void
    {
        $assessment = Assessment::query()->where('title', 'Update target')->first()
            ?? $this->createAssessment($this->quizzes, 'Update target');

        $this->assertRejectedByDatabase(
            fn () => DB::table('assessments')->where('id', $assessment->id)->update($values),
            $message,
        );
    }

    private function assertRejectedByDatabase(callable $write, string $message): void
    {
        try {
            $write();
        } catch (QueryException) {
            $this->addToAssertionCount(1);

            return;
        }

        $this->fail($message);
    }
}
