<?php

namespace Tests\Feature\Grading;

use App\Enums\AuditAction;
use App\Enums\Permission as PermissionCode;
use App\Enums\SystemRole;
use App\Models\Assessment;
use App\Models\AssessmentCategory;
use App\Models\AuditLog;
use App\Models\ClassSubject;
use App\Models\InstructorAssignment;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Administrative grading setup of a class subject (categories and weights),
 * the grading summary on the class page, and subject removal once grading
 * exists.
 */
class GradingSchemeTest extends TestCase
{
    use BuildsGradingFixtures;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildGradingFixtures();
    }

    // ------------------------------------------------------------------
    // Page and authorization
    // ------------------------------------------------------------------

    public function test_academic_administrator_opens_the_grading_setup_page(): void
    {
        $this->actingAs($this->academicAdmin)
            ->get($this->gradingUrl($this->offeringA1))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('staff/classes/grading')
                ->where('offering.id', $this->offeringA1->id)
                ->where('offering.classBatch.id', $this->batchA->id)
                ->where('offering.classBatch.name', 'Sample Batch A')
                ->where('offering.subject.name', 'Subject 1')
                ->where('offering.period.isActive', true)
                ->has('categories', 2)
                ->where('categories.0', [
                    'id' => $this->quizzes->id,
                    'name' => 'Quizzes',
                    'weight' => '40',
                    'assessmentCount' => 0,
                ])
                ->where('categories.1', [
                    'id' => $this->examinations->id,
                    'name' => 'Examinations',
                    'weight' => '60',
                    'assessmentCount' => 0,
                ])
                ->where('hasFinalizedAssessments', false)
                ->where('totalWeight', 100)
                ->where('maxCategories', 10)
                ->where('can.viewClass', true));
    }

    public function test_page_counts_assessments_per_category_and_reports_finalized_assessments(): void
    {
        $this->createAssessment($this->quizzes, 'Quiz 1');
        $this->createAssessment($this->quizzes, 'Quiz 2');

        $this->actingAs($this->academicAdmin)
            ->get($this->gradingUrl($this->offeringA1))
            ->assertInertia(fn (Assert $page) => $page
                ->where('categories.0.assessmentCount', 2)
                ->where('categories.1.assessmentCount', 0)
                ->where('hasFinalizedAssessments', false));

        $this->finalizedExamination();

        $this->actingAs($this->academicAdmin)
            ->get($this->gradingUrl($this->offeringA1))
            ->assertInertia(fn (Assert $page) => $page
                ->where('categories.0.assessmentCount', 2)
                ->where('categories.1.assessmentCount', 1)
                ->where('hasFinalizedAssessments', true));
    }

    public function test_page_of_a_subject_without_grading_lists_no_categories(): void
    {
        $this->actingAs($this->academicAdmin)
            ->get($this->gradingUrl($this->offeringA2))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('staff/classes/grading')
                ->where('offering.subject.name', 'Subject 2')
                ->has('categories', 0)
                ->where('hasFinalizedAssessments', false));
    }

    public function test_super_administrator_can_configure_grading(): void
    {
        $superAdmin = $this->userWithRole(SystemRole::SuperAdministrator);

        $this->actingAs($superAdmin)->get($this->gradingUrl($this->offeringB1))->assertOk();

        $this->actingAs($superAdmin)
            ->put($this->gradingUrl($this->offeringB1), $this->payload([[null, 'Written Work', '100']]))
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('classes.show', $this->batchB));

        $this->assertSame([['Written Work', '100.00']], $this->schemeOf($this->offeringB1));
    }

    public function test_instructors_cannot_open_or_change_the_grading_setup(): void
    {
        // Alpha teaches Batch A Subject 1; teaching a subject does not allow
        // configuring its grading.
        foreach ([$this->alpha, $this->bravo] as $instructor) {
            $this->actingAs($instructor)->get($this->gradingUrl($this->offeringA1))->assertForbidden();
            $this->actingAs($instructor)
                ->put($this->gradingUrl($this->offeringA1), $this->payload([
                    [$this->quizzes->id, 'Quizzes', '50'],
                    [$this->examinations->id, 'Examinations', '50'],
                ]))
                ->assertForbidden();
        }

        $this->actingAs($this->bravo)
            ->put($this->gradingUrl($this->offeringA2), $this->payload([[null, 'Written Work', '100']]))
            ->assertForbidden();

        $this->assertSame([['Quizzes', '40.00'], ['Examinations', '60.00']], $this->schemeOf($this->offeringA1));
        $this->assertSame([], $this->schemeOf($this->offeringA2));
    }

    public function test_guests_are_redirected_to_sign_in(): void
    {
        $this->get($this->gradingUrl($this->offeringA1))->assertRedirect(route('login'));
        $this->put($this->gradingUrl($this->offeringA1), $this->payload([[null, 'Written Work', '100']]))
            ->assertRedirect(route('login'));

        $this->assertSame([['Quizzes', '40.00'], ['Examinations', '60.00']], $this->schemeOf($this->offeringA1));
    }

    public function test_grading_setup_is_only_reachable_through_the_subjects_own_class(): void
    {
        $wrongUrl = "/classes/{$this->batchB->id}/subjects/{$this->offeringA1->id}/grading";

        $this->actingAs($this->academicAdmin)->get($wrongUrl)->assertNotFound();
        $this->actingAs($this->academicAdmin)
            ->put($wrongUrl, $this->payload([[null, 'Written Work', '100']]))
            ->assertNotFound();

        $this->assertSame([['Quizzes', '40.00'], ['Examinations', '60.00']], $this->schemeOf($this->offeringA1));
    }

    public function test_without_class_management_the_save_returns_to_the_grading_page(): void
    {
        $gradingOfficer = $this->userWithPermissions('grading_officer', [
            PermissionCode::AccessStaffArea,
            PermissionCode::ConfigureGrading,
        ]);

        $this->actingAs($gradingOfficer)
            ->get($this->gradingUrl($this->offeringA2))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('can.viewClass', false));

        $this->actingAs($gradingOfficer)
            ->put($this->gradingUrl($this->offeringA2), $this->payload([[null, 'Written Work', '100']]))
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('classes.grading.edit', [$this->batchA, $this->offeringA2]));

        $this->assertSame([['Written Work', '100.00']], $this->schemeOf($this->offeringA2));
    }

    // ------------------------------------------------------------------
    // Saving a scheme
    // ------------------------------------------------------------------

    public function test_administrator_saves_a_scheme_and_returns_to_the_class(): void
    {
        $this->actingAs($this->academicAdmin)
            ->put($this->gradingUrl($this->offeringA2), $this->payload([
                [null, ' Quizzes ', '20'],
                [null, 'Examination', '30.5'],
                [null, 'Practical', '49.50'],
            ]))
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('classes.show', $this->batchA))
            ->assertInertiaFlash('toast.type', 'success')
            ->assertInertiaFlash('toast.message', 'Weights for Subject 2 saved.');

        $this->assertSame(
            [['Quizzes', '20.00'], ['Examination', '30.50'], ['Practical', '49.50']],
            $this->schemeOf($this->offeringA2),
        );

        $entry = $this->latestSchemeAudit($this->offeringA2);
        $this->assertNotNull($entry);
        $this->assertSame($this->academicAdmin->id, $entry->actor_id);
        $this->assertSame('class_subject', $entry->auditable_type);
        $this->assertSame(['categories' => []], $entry->old_values);
        // New categories are recorded with the ids they received.
        $ids = $this->offeringA2->assessmentCategories()->pluck('id')->all();
        $this->assertSame(['categories' => [
            ['id' => $ids[0], 'name' => 'Quizzes', 'weight' => '20.00'],
            ['id' => $ids[1], 'name' => 'Examination', 'weight' => '30.50'],
            ['id' => $ids[2], 'name' => 'Practical', 'weight' => '49.50'],
        ]], $entry->new_values);
        $this->assertNull($entry->reason);
    }

    public function test_weights_must_add_up_to_exactly_100(): void
    {
        $this->actingAs($this->academicAdmin)
            ->put($this->gradingUrl($this->offeringA1), $this->payload([
                [$this->quizzes->id, 'Quizzes', '40'],
                [$this->examinations->id, 'Examinations', '50'],
            ]))
            ->assertSessionHasErrors('categories');

        $this->actingAs($this->academicAdmin)
            ->put($this->gradingUrl($this->offeringA1), $this->payload([
                [$this->quizzes->id, 'Quizzes', '40.01'],
                [$this->examinations->id, 'Examinations', '60'],
            ]))
            ->assertSessionHasErrors('categories');

        $this->assertSame([['Quizzes', '40.00'], ['Examinations', '60.00']], $this->schemeOf($this->offeringA1));
        $this->assertSame(1, $this->schemeAuditCount($this->offeringA1), 'Only the fixture setup is audited.');

        $this->actingAs($this->academicAdmin)
            ->put($this->gradingUrl($this->offeringA1), $this->payload([
                [$this->quizzes->id, 'Quizzes', '33.33'],
                [$this->examinations->id, 'Examinations', '33.33'],
                [null, 'Practical', '33.34'],
            ]))
            ->assertSessionHasNoErrors();

        $this->assertSame(
            [['Quizzes', '33.33'], ['Examinations', '33.33'], ['Practical', '33.34']],
            $this->schemeOf($this->offeringA1),
        );
    }

    public function test_weight_boundaries_are_accepted(): void
    {
        $this->actingAs($this->academicAdmin)
            ->put($this->gradingUrl($this->offeringA2), $this->payload([[null, 'Everything', '100']]))
            ->assertSessionHasNoErrors();
        $this->assertSame([['Everything', '100.00']], $this->schemeOf($this->offeringA2));

        $this->actingAs($this->academicAdmin)
            ->put($this->gradingUrl($this->offeringB1), $this->payload([
                [null, 'Minor', '0.01'],
                [null, 'Major', '99.99'],
            ]))
            ->assertSessionHasNoErrors();
        $this->assertSame([['Minor', '0.01'], ['Major', '99.99']], $this->schemeOf($this->offeringB1));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidWeights(): array
    {
        return [
            'zero' => ['0'],
            'negative' => ['-5'],
            'above 100' => ['100.01'],
            'far above 100' => ['150'],
            'three decimals' => ['12.345'],
            'not a number' => ['abc'],
            'blank' => [''],
        ];
    }

    #[DataProvider('invalidWeights')]
    public function test_invalid_weights_are_rejected(string $weight): void
    {
        $this->actingAs($this->academicAdmin)
            ->put($this->gradingUrl($this->offeringA2), $this->payload([
                [null, 'Written Work', $weight],
                [null, 'Oral', '50'],
            ]))
            ->assertSessionHasErrors('categories.0.weight');

        $this->assertSame([], $this->schemeOf($this->offeringA2));
        $this->assertSame(0, $this->schemeAuditCount($this->offeringA2));
    }

    public function test_category_names_are_required_trimmed_and_limited_to_100_characters(): void
    {
        $this->actingAs($this->academicAdmin)
            ->put($this->gradingUrl($this->offeringA2), $this->payload([[null, '   ', '100']]))
            ->assertSessionHasErrors('categories.0.name');

        $this->actingAs($this->academicAdmin)
            ->put($this->gradingUrl($this->offeringA2), $this->payload([[null, str_repeat('N', 101), '100']]))
            ->assertSessionHasErrors('categories.0.name');

        $this->assertSame([], $this->schemeOf($this->offeringA2));

        $longest = str_repeat('N', 100);
        $this->actingAs($this->academicAdmin)
            ->put($this->gradingUrl($this->offeringA2), $this->payload([[null, "  {$longest}  ", '100']]))
            ->assertSessionHasNoErrors();

        $this->assertSame([[$longest, '100.00']], $this->schemeOf($this->offeringA2));
    }

    public function test_category_names_must_be_unique_ignoring_case(): void
    {
        $this->actingAs($this->academicAdmin)
            ->put($this->gradingUrl($this->offeringA2), $this->payload([
                [null, 'Quizzes', '50'],
                [null, 'Quizzes', '50'],
            ]))
            ->assertSessionHasErrors('categories.1.name');

        $this->actingAs($this->academicAdmin)
            ->put($this->gradingUrl($this->offeringA2), $this->payload([
                [null, 'Quizzes', '50'],
                [null, 'QUIZZES', '50'],
            ]))
            ->assertSessionHasErrors('categories.1.name');

        // An existing category cannot be duplicated by a new one either.
        $this->actingAs($this->academicAdmin)
            ->put($this->gradingUrl($this->offeringA1), $this->payload([
                [$this->quizzes->id, 'Quizzes', '40'],
                [$this->examinations->id, 'Examinations', '30'],
                [null, ' quizzes ', '30'],
            ]))
            ->assertSessionHasErrors('categories.2.name');

        $this->assertSame([], $this->schemeOf($this->offeringA2));
        $this->assertSame([['Quizzes', '40.00'], ['Examinations', '60.00']], $this->schemeOf($this->offeringA1));
    }

    public function test_names_the_database_treats_as_equal_are_reported_as_a_validation_error(): void
    {
        // The utf8mb4_unicode_ci collation compares "Examen" and "Exámen" as
        // equal, so the unique index would reject the second row. The user
        // must get a validation message, not a server error.
        $response = $this->actingAs($this->academicAdmin)
            ->put($this->gradingUrl($this->offeringA2), $this->payload([
                [null, 'Examen', '50'],
                [null, 'Exámen', '50'],
            ]));

        $response->assertStatus(302);
        $response->assertSessionHas('errors');
        $this->assertSame([], $this->schemeOf($this->offeringA2));
    }

    public function test_a_category_name_sent_as_a_list_is_reported_as_a_validation_error(): void
    {
        // A crafted request (not the page) sends a list instead of a name.
        $response = $this->actingAs($this->academicAdmin)
            ->put($this->gradingUrl($this->offeringA2), ['categories' => [
                ['id' => null, 'name' => ['Quizzes'], 'weight' => '100'],
            ]]);

        $response->assertStatus(302);
        $response->assertSessionHasErrors('categories.0.name');
        $this->assertSame([], $this->schemeOf($this->offeringA2));
    }

    public function test_malformed_weights_and_unknown_category_fields_are_rejected(): void
    {
        $this->actingAs($this->academicAdmin)
            ->put($this->gradingUrl($this->offeringA2), ['categories' => [
                ['id' => null, 'name' => 'Quizzes', 'weight' => ['100']],
            ]])
            ->assertSessionHasErrors('categories.0.weight');

        $this->actingAs($this->academicAdmin)
            ->put($this->gradingUrl($this->offeringA2), ['categories' => [
                ['id' => null, 'name' => 'Quizzes', 'weight' => '100', 'position' => 3],
            ]])
            ->assertSessionHasErrors('categories.0');

        $this->assertSame([], $this->schemeOf($this->offeringA2));
    }

    public function test_a_subject_has_at_most_ten_categories(): void
    {
        $eleven = [];
        foreach (range(1, 11) as $number) {
            $eleven[] = [null, "Category {$number}", $number === 11 ? '10' : '9'];
        }

        $this->actingAs($this->academicAdmin)
            ->put($this->gradingUrl($this->offeringA2), $this->payload($eleven))
            ->assertSessionHasErrors('categories');
        $this->assertSame([], $this->schemeOf($this->offeringA2));

        $ten = array_map(fn (int $number): array => [null, "Category {$number}", '10'], range(1, 10));

        $this->actingAs($this->academicAdmin)
            ->put($this->gradingUrl($this->offeringA2), $this->payload($ten))
            ->assertSessionHasNoErrors();
        $this->assertCount(10, $this->schemeOf($this->offeringA2));
    }

    public function test_an_empty_category_list_is_rejected(): void
    {
        $this->actingAs($this->academicAdmin)
            ->put($this->gradingUrl($this->offeringA1), ['categories' => []])
            ->assertSessionHasErrors('categories');

        $this->actingAs($this->academicAdmin)
            ->put($this->gradingUrl($this->offeringA1), [])
            ->assertSessionHasErrors('categories');

        $this->assertSame([['Quizzes', '40.00'], ['Examinations', '60.00']], $this->schemeOf($this->offeringA1));
    }

    public function test_category_ids_of_another_subject_are_rejected(): void
    {
        $this->setScheme($this->offeringB1, ['Assignments' => '100']);
        $foreign = $this->offeringB1->assessmentCategories()->sole();

        $this->actingAs($this->academicAdmin)
            ->put($this->gradingUrl($this->offeringA1), $this->payload([
                [$foreign->id, 'Quizzes', '40'],
                [$this->examinations->id, 'Examinations', '60'],
            ]))
            ->assertSessionHas('errors');

        $this->actingAs($this->academicAdmin)
            ->put($this->gradingUrl($this->offeringA1), $this->payload([
                [999999, 'Quizzes', '40'],
                [$this->examinations->id, 'Examinations', '60'],
            ]))
            ->assertSessionHas('errors');

        $this->assertSame([['Quizzes', '40.00'], ['Examinations', '60.00']], $this->schemeOf($this->offeringA1));
        $this->assertSame([['Assignments', '100.00']], $this->schemeOf($this->offeringB1));
        $this->assertSame($this->offeringB1->id, $foreign->fresh()->class_subject_id);
    }

    public function test_the_same_category_cannot_be_submitted_twice(): void
    {
        $this->actingAs($this->academicAdmin)
            ->put($this->gradingUrl($this->offeringA1), $this->payload([
                [$this->quizzes->id, 'Quizzes', '40'],
                [$this->quizzes->id, 'Examinations', '60'],
            ]))
            ->assertSessionHas('errors');

        $this->assertSame([['Quizzes', '40.00'], ['Examinations', '60.00']], $this->schemeOf($this->offeringA1));
    }

    // ------------------------------------------------------------------
    // Removing categories
    // ------------------------------------------------------------------

    public function test_a_category_with_assessments_cannot_be_removed_but_can_be_renamed_and_reweighted(): void
    {
        $this->createAssessment($this->quizzes, 'Quiz 1');

        $this->actingAs($this->academicAdmin)
            ->put($this->gradingUrl($this->offeringA1), $this->payload([
                [$this->examinations->id, 'Examinations', '100'],
            ]))
            ->assertSessionHasErrors('categories');

        $this->assertDatabaseHas('assessment_categories', ['id' => $this->quizzes->id, 'name' => 'Quizzes']);

        // Only draft assessments exist, so no reason is needed.
        $this->actingAs($this->academicAdmin)
            ->put($this->gradingUrl($this->offeringA1), $this->payload([
                [$this->quizzes->id, 'Short Quizzes', '25'],
                [$this->examinations->id, 'Examinations', '75'],
            ]))
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('classes.show', $this->batchA));

        $this->assertSame([['Short Quizzes', '25.00'], ['Examinations', '75.00']], $this->schemeOf($this->offeringA1));
        $this->assertSame($this->quizzes->id, Assessment::query()->where('title', 'Quiz 1')->sole()->assessment_category_id);
    }

    public function test_categories_without_assessments_are_deleted_when_removed(): void
    {
        $this->createAssessment($this->examinations, 'Midterm');

        $this->actingAs($this->academicAdmin)
            ->put($this->gradingUrl($this->offeringA1), $this->payload([
                [$this->examinations->id, 'Examinations', '100'],
            ]))
            ->assertSessionHasNoErrors();

        $this->assertDatabaseMissing('assessment_categories', ['id' => $this->quizzes->id]);
        $this->assertSame([['Examinations', '100.00']], $this->schemeOf($this->offeringA1));

        $entry = $this->latestSchemeAudit($this->offeringA1);
        $this->assertSame([
            ['id' => $this->quizzes->id, 'name' => 'Quizzes', 'weight' => '40.00'],
            ['id' => $this->examinations->id, 'name' => 'Examinations', 'weight' => '60.00'],
        ], $entry->old_values['categories']);
        $this->assertSame([['id' => $this->examinations->id, 'name' => 'Examinations', 'weight' => '100.00']], $entry->new_values['categories']);
    }

    public function test_a_removed_categorys_name_can_be_reused_in_the_same_save(): void
    {
        $this->actingAs($this->academicAdmin)
            ->put($this->gradingUrl($this->offeringA1), $this->payload([
                [$this->examinations->id, 'Examinations', '60'],
                [null, 'Quizzes', '40'],
            ]))
            ->assertSessionHasNoErrors();

        $this->assertDatabaseMissing('assessment_categories', ['id' => $this->quizzes->id]);
        $this->assertSame([['Examinations', '60.00'], ['Quizzes', '40.00']], $this->schemeOf($this->offeringA1));
    }

    public function test_submitting_only_new_categories_replaces_an_unused_scheme(): void
    {
        $this->actingAs($this->academicAdmin)
            ->put($this->gradingUrl($this->offeringA1), $this->payload([
                [null, 'Written Work', '50'],
                [null, 'Oral Recitation', '50'],
            ]))
            ->assertSessionHasNoErrors();

        $this->assertDatabaseMissing('assessment_categories', ['id' => $this->quizzes->id]);
        $this->assertDatabaseMissing('assessment_categories', ['id' => $this->examinations->id]);
        $this->assertSame([['Written Work', '50.00'], ['Oral Recitation', '50.00']], $this->schemeOf($this->offeringA1));
    }

    // ------------------------------------------------------------------
    // Reasons and audit
    // ------------------------------------------------------------------

    public function test_a_reason_is_required_once_assessments_are_finalized(): void
    {
        $this->finalizedExamination();
        $auditsBefore = $this->schemeAuditCount($this->offeringA1);

        $reweighted = [
            [$this->quizzes->id, 'Quizzes', '30'],
            [$this->examinations->id, 'Examinations', '70'],
        ];

        $this->actingAs($this->academicAdmin)
            ->put($this->gradingUrl($this->offeringA1), $this->payload($reweighted))
            ->assertSessionHasErrors('reason');

        $this->actingAs($this->academicAdmin)
            ->put($this->gradingUrl($this->offeringA1), $this->payload($reweighted, '    '))
            ->assertSessionHasErrors('reason');

        // A rename alone is also a change.
        $this->actingAs($this->academicAdmin)
            ->put($this->gradingUrl($this->offeringA1), $this->payload([
                [$this->quizzes->id, 'Short Quizzes', '40'],
                [$this->examinations->id, 'Examinations', '60'],
            ]))
            ->assertSessionHasErrors('reason');

        $this->assertSame([['Quizzes', '40.00'], ['Examinations', '60.00']], $this->schemeOf($this->offeringA1));
        $this->assertSame($auditsBefore, $this->schemeAuditCount($this->offeringA1));

        $this->actingAs($this->academicAdmin)
            ->put($this->gradingUrl($this->offeringA1), $this->payload($reweighted, '  Approved by the academic board.  '))
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('classes.show', $this->batchA));

        $this->assertSame([['Quizzes', '30.00'], ['Examinations', '70.00']], $this->schemeOf($this->offeringA1));
        $this->assertSame('Approved by the academic board.', $this->latestSchemeAudit($this->offeringA1)->reason);
    }

    public function test_finalized_assessments_of_another_subject_do_not_require_a_reason(): void
    {
        $this->finalizedExamination();

        $this->actingAs($this->academicAdmin)
            ->put($this->gradingUrl($this->offeringB1), $this->payload([
                [null, 'Quizzes', '40'],
                [null, 'Examinations', '60'],
            ]))
            ->assertSessionHasNoErrors();

        $this->assertSame([['Quizzes', '40.00'], ['Examinations', '60.00']], $this->schemeOf($this->offeringB1));
    }

    public function test_draft_assessments_do_not_require_a_reason(): void
    {
        $quiz = $this->createAssessment($this->quizzes, 'Quiz 1');
        $this->recordScores($quiz, [$this->candidateInA->id => '45']);

        $this->actingAs($this->academicAdmin)
            ->put($this->gradingUrl($this->offeringA1), $this->payload([
                [$this->quizzes->id, 'Quizzes', '50'],
                [$this->examinations->id, 'Examinations', '50'],
            ]))
            ->assertSessionHasNoErrors();

        $this->assertSame([['Quizzes', '50.00'], ['Examinations', '50.00']], $this->schemeOf($this->offeringA1));
        $this->assertNull($this->latestSchemeAudit($this->offeringA1)->reason);
    }

    public function test_the_reason_is_limited_to_500_characters(): void
    {
        $this->finalizedExamination();

        $this->actingAs($this->academicAdmin)
            ->put($this->gradingUrl($this->offeringA1), $this->payload([
                [$this->quizzes->id, 'Quizzes', '30'],
                [$this->examinations->id, 'Examinations', '70'],
            ], str_repeat('r', 501)))
            ->assertSessionHasErrors('reason');

        $this->assertSame([['Quizzes', '40.00'], ['Examinations', '60.00']], $this->schemeOf($this->offeringA1));
    }

    public function test_saving_an_unchanged_scheme_writes_no_audit_entry(): void
    {
        // Even with finalized assessments, an unchanged scheme needs no reason.
        $this->finalizedExamination();
        $auditsBefore = $this->schemeAuditCount($this->offeringA1);
        $idsBefore = $this->offeringA1->assessmentCategories()->pluck('id')->all();

        $this->actingAs($this->academicAdmin)
            ->put($this->gradingUrl($this->offeringA1), $this->payload([
                [$this->quizzes->id, 'Quizzes', '40'],
                [$this->examinations->id, ' Examinations ', '60.00'],
            ]))
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('classes.show', $this->batchA));

        $this->assertSame($auditsBefore, $this->schemeAuditCount($this->offeringA1));
        $this->assertSame($idsBefore, $this->offeringA1->assessmentCategories()->pluck('id')->all());
        $this->assertSame([['Quizzes', '40.00'], ['Examinations', '60.00']], $this->schemeOf($this->offeringA1));
    }

    public function test_audit_entry_keeps_the_previous_and_new_categories_with_the_reason(): void
    {
        $this->finalizedExamination();

        $this->actingAs($this->academicAdmin)
            ->put($this->gradingUrl($this->offeringA1), $this->payload([
                [$this->quizzes->id, 'Quizzes', '20'],
                [$this->examinations->id, 'Final Examinations', '50'],
                [null, 'Practical', '30'],
            ], 'Practical component added this period.'))
            ->assertSessionHasNoErrors();

        $entry = $this->latestSchemeAudit($this->offeringA1);
        $this->assertSame($this->academicAdmin->id, $entry->actor_id);
        $this->assertSame($this->offeringA1->id, $entry->auditable_id);
        // Ids show which category (and so which assessments) each weight belongs to.
        $practicalId = $this->offeringA1->assessmentCategories()->where('name', 'Practical')->value('id');
        $this->assertSame(['categories' => [
            ['id' => $this->quizzes->id, 'name' => 'Quizzes', 'weight' => '40.00'],
            ['id' => $this->examinations->id, 'name' => 'Examinations', 'weight' => '60.00'],
        ]], $entry->old_values);
        $this->assertSame(['categories' => [
            ['id' => $this->quizzes->id, 'name' => 'Quizzes', 'weight' => '20.00'],
            ['id' => $this->examinations->id, 'name' => 'Final Examinations', 'weight' => '50.00'],
            ['id' => $practicalId, 'name' => 'Practical', 'weight' => '30.00'],
        ]], $entry->new_values);
        $this->assertSame('Practical component added this period.', $entry->reason);
    }

    // ------------------------------------------------------------------
    // Names and order
    // ------------------------------------------------------------------

    public function test_two_categories_can_swap_names_in_one_save(): void
    {
        $this->actingAs($this->academicAdmin)
            ->put($this->gradingUrl($this->offeringA1), $this->payload([
                [$this->quizzes->id, 'Examinations', '40'],
                [$this->examinations->id, 'Quizzes', '60'],
            ]))
            ->assertSessionHasNoErrors();

        $this->assertSame('Examinations', $this->quizzes->fresh()->name);
        $this->assertSame('Quizzes', $this->examinations->fresh()->name);
        $this->assertSame([['Examinations', '40.00'], ['Quizzes', '60.00']], $this->schemeOf($this->offeringA1));
    }

    public function test_a_change_of_letter_case_is_saved(): void
    {
        $this->actingAs($this->academicAdmin)
            ->put($this->gradingUrl($this->offeringA1), $this->payload([
                [$this->quizzes->id, 'QUIZZES', '40'],
                [$this->examinations->id, 'Examinations', '60'],
            ]))
            ->assertSessionHasNoErrors();

        $this->assertSame('QUIZZES', $this->quizzes->fresh()->name);
        $this->assertSame(2, $this->schemeAuditCount($this->offeringA1));
    }

    public function test_positions_follow_the_submitted_order(): void
    {
        $this->actingAs($this->academicAdmin)
            ->put($this->gradingUrl($this->offeringA1), $this->payload([
                [$this->examinations->id, 'Examinations', '50'],
                [null, 'Practical', '20'],
                [$this->quizzes->id, 'Quizzes', '30'],
            ]))
            ->assertSessionHasNoErrors();

        $this->assertSame(0, $this->examinations->fresh()->position);
        $this->assertSame(2, $this->quizzes->fresh()->position);
        $this->assertSame(1, AssessmentCategory::query()->where('class_subject_id', $this->offeringA1->id)->where('name', 'Practical')->sole()->position);

        $this->actingAs($this->academicAdmin)
            ->get($this->gradingUrl($this->offeringA1))
            ->assertInertia(fn (Assert $page) => $page
                ->where('categories', fn ($categories) => collect($categories)->pluck('name')->all() === ['Examinations', 'Practical', 'Quizzes']));

        // Reordering alone is a change and is saved.
        $this->actingAs($this->academicAdmin)
            ->put($this->gradingUrl($this->offeringA1), $this->payload([
                [$this->quizzes->id, 'Quizzes', '30'],
                [$this->examinations->id, 'Examinations', '50'],
                [AssessmentCategory::query()->where('name', 'Practical')->sole()->id, 'Practical', '20'],
            ]))
            ->assertSessionHasNoErrors();

        $this->assertSame([['Quizzes', '30.00'], ['Examinations', '50.00'], ['Practical', '20.00']], $this->schemeOf($this->offeringA1));
    }

    // ------------------------------------------------------------------
    // Class subject removal
    // ------------------------------------------------------------------

    public function test_a_subject_with_assessments_cannot_be_removed_from_its_class(): void
    {
        $this->createAssessment($this->quizzes, 'Quiz 1');

        $this->actingAs($this->academicAdmin)
            ->delete("/classes/{$this->batchA->id}/subjects/{$this->offeringA1->id}")
            ->assertRedirect(route('classes.show', $this->batchA))
            ->assertInertiaFlash('toast.type', 'error');

        $this->assertDatabaseHas('class_subjects', ['id' => $this->offeringA1->id]);
        $this->assertSame([['Quizzes', '40.00'], ['Examinations', '60.00']], $this->schemeOf($this->offeringA1));
        $this->assertDatabaseHas('assessments', ['class_subject_id' => $this->offeringA1->id, 'title' => 'Quiz 1']);
        $this->assertSame(1, InstructorAssignment::query()->where('class_subject_id', $this->offeringA1->id)->count());
        $this->assertDatabaseMissing('audit_logs', ['action' => AuditAction::ClassSubjectRemoved->value]);
    }

    public function test_a_subject_with_only_finalized_assessments_cannot_be_removed_either(): void
    {
        $this->finalizedExamination();

        $this->actingAs($this->academicAdmin)
            ->delete("/classes/{$this->batchA->id}/subjects/{$this->offeringA1->id}")
            ->assertRedirect(route('classes.show', $this->batchA))
            ->assertInertiaFlash('toast.type', 'error');

        $this->assertDatabaseHas('class_subjects', ['id' => $this->offeringA1->id]);
        $this->assertSame(2, AssessmentCategory::query()->where('class_subject_id', $this->offeringA1->id)->count());
    }

    public function test_a_subject_without_assessments_is_removed_with_its_grading_categories(): void
    {
        $this->setScheme($this->offeringA2, ['Written Work' => '70', 'Oral' => '30']);

        $this->actingAs($this->academicAdmin)
            ->delete("/classes/{$this->batchA->id}/subjects/{$this->offeringA2->id}")
            ->assertRedirect(route('classes.show', $this->batchA))
            ->assertInertiaFlash('toast.type', 'success');

        $this->assertDatabaseMissing('class_subjects', ['id' => $this->offeringA2->id]);
        $this->assertSame(0, AssessmentCategory::query()->where('class_subject_id', $this->offeringA2->id)->count());
        $this->assertSame(0, InstructorAssignment::query()->where('class_subject_id', $this->offeringA2->id)->count());

        $entry = AuditLog::query()->where('action', AuditAction::ClassSubjectRemoved->value)->sole();
        $this->assertSame(['Written Work', 'Oral'], $entry->old_values['grading_categories']);
        $this->assertSame(['Instructor Bravo'], $entry->old_values['instructors']);

        // Other subjects keep their grading.
        $this->assertSame([['Quizzes', '40.00'], ['Examinations', '60.00']], $this->schemeOf($this->offeringA1));
    }

    // ------------------------------------------------------------------
    // Class page
    // ------------------------------------------------------------------

    public function test_class_page_summarises_grading_for_each_subject(): void
    {
        $this->createAssessment($this->quizzes, 'Quiz 1');
        $this->finalizedExamination();
        $this->actingAs($this->academicAdmin)
            ->put($this->gradingUrl($this->offeringA1), $this->payload([
                [$this->quizzes->id, 'Quizzes', '40.5'],
                [$this->examinations->id, 'Examinations', '59.50'],
            ], 'Adjusted by the academic board.'))
            ->assertSessionHasNoErrors();

        $this->actingAs($this->academicAdmin)
            ->get("/classes/{$this->batchA->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('staff/classes/show')
                ->has('offerings', 2)
                ->where('offerings.0.id', $this->offeringA1->id)
                ->where('offerings.0.grading', [
                    ['name' => 'Quizzes', 'weight' => '40.5'],
                    ['name' => 'Examinations', 'weight' => '59.5'],
                ])
                ->where('offerings.0.assessmentCount', 2)
                ->where('offerings.1.id', $this->offeringA2->id)
                ->where('offerings.1.grading', [])
                ->where('offerings.1.assessmentCount', 0)
                ->where('can.configureGrading', true));
    }

    public function test_class_page_hides_grading_setup_without_the_permission(): void
    {
        $classManager = $this->userWithPermissions('class_manager', [
            PermissionCode::AccessStaffArea,
            PermissionCode::ManageClassBatches,
        ]);

        $this->actingAs($classManager)
            ->get("/classes/{$this->batchA->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('can.configureGrading', false));

        $this->actingAs($classManager)->get($this->gradingUrl($this->offeringA1))->assertForbidden();
        $this->actingAs($classManager)
            ->put($this->gradingUrl($this->offeringA1), $this->payload([[null, 'Written Work', '100']]))
            ->assertForbidden();

        $this->assertSame([['Quizzes', '40.00'], ['Examinations', '60.00']], $this->schemeOf($this->offeringA1));
    }

    // ------------------------------------------------------------------
    // Database constraints
    // ------------------------------------------------------------------

    public function test_database_rejects_category_weights_outside_the_allowed_range(): void
    {
        foreach (['0', '-1', '100.01', '0.00'] as $weight) {
            $this->assertQueryFails(
                fn () => $this->insertCategory($this->offeringA2, "Weight {$weight}", $weight),
                "A weight of {$weight} must be rejected by the database.",
            );
        }

        $this->assertSame(0, AssessmentCategory::query()->where('class_subject_id', $this->offeringA2->id)->count());

        $this->insertCategory($this->offeringA2, 'Everything', '100');
        $this->insertCategory($this->offeringB1, 'Smallest', '0.01');
        $this->assertSame(1, AssessmentCategory::query()->where('class_subject_id', $this->offeringA2->id)->count());
        $this->assertSame(1, AssessmentCategory::query()->where('class_subject_id', $this->offeringB1->id)->count());
    }

    public function test_database_keeps_category_names_unique_per_subject_and_protects_used_subjects(): void
    {
        $this->assertQueryFails(fn () => $this->insertCategory($this->offeringA1, 'Quizzes', '10'));

        // The same name in another subject is fine.
        $this->insertCategory($this->offeringA2, 'Quizzes', '100');

        // A class subject with grading categories cannot be deleted directly.
        $this->assertQueryFails(fn () => DB::table('class_subjects')->where('id', $this->offeringA1->id)->delete());
        $this->assertDatabaseHas('class_subjects', ['id' => $this->offeringA1->id]);
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function gradingUrl(ClassSubject $offering): string
    {
        return "/classes/{$offering->class_batch_id}/subjects/{$offering->id}/grading";
    }

    /**
     * @param  list<array{0: int|null, 1: string, 2: string}>  $rows  [id, name, weight]
     * @return array<string, mixed>
     */
    private function payload(array $rows, ?string $reason = null): array
    {
        $data = [
            'categories' => array_map(fn (array $row): array => [
                'id' => $row[0],
                'name' => $row[1],
                'weight' => $row[2],
            ], $rows),
        ];

        if ($reason !== null) {
            $data['reason'] = $reason;
        }

        return $data;
    }

    /**
     * The stored scheme in display order, as [name, weight] pairs.
     *
     * @return list<array{0: string, 1: string}>
     */
    private function schemeOf(ClassSubject $offering): array
    {
        return AssessmentCategory::query()
            ->where('class_subject_id', $offering->id)
            ->orderBy('position')
            ->orderBy('id')
            ->get()
            ->map(fn (AssessmentCategory $category): array => [$category->name, $category->weight])
            ->all();
    }

    private function schemeAuditCount(ClassSubject $offering): int
    {
        return AuditLog::query()
            ->where('action', AuditAction::GradingSchemeUpdated->value)
            ->where('auditable_type', 'class_subject')
            ->where('auditable_id', $offering->id)
            ->count();
    }

    private function latestSchemeAudit(ClassSubject $offering): ?AuditLog
    {
        return AuditLog::query()
            ->where('action', AuditAction::GradingSchemeUpdated->value)
            ->where('auditable_type', 'class_subject')
            ->where('auditable_id', $offering->id)
            ->latest('id')
            ->first();
    }

    /**
     * A finalized examination in Batch A Subject 1 with one recorded score.
     */
    private function finalizedExamination(): Assessment
    {
        $midterm = $this->createAssessment($this->examinations, 'Midterm Examination', '100');
        $this->recordScores($midterm, [$this->candidateInA->id => '80']);
        $this->finalize($midterm);

        return $midterm;
    }

    /**
     * @param  list<PermissionCode>  $permissions
     */
    private function userWithPermissions(string $roleCode, array $permissions): User
    {
        $role = Role::query()->create(['code' => $roleCode, 'name' => Str::headline($roleCode)]);
        $role->permissions()->sync(Permission::query()
            ->whereIn('code', array_map(fn (PermissionCode $permission): string => $permission->value, $permissions))
            ->pluck('id'));

        return User::factory()->create(['role_id' => $role->id]);
    }

    private function insertCategory(ClassSubject $offering, string $name, string $weight): void
    {
        DB::table('assessment_categories')->insert([
            'class_subject_id' => $offering->id,
            'name' => $name,
            'weight' => $weight,
            'position' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function assertQueryFails(callable $statement, string $message = 'The database must reject this statement.'): void
    {
        try {
            $statement();
        } catch (QueryException) {
            $this->addToAssertionCount(1);

            return;
        }

        $this->fail($message);
    }
}
