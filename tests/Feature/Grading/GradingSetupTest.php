<?php

namespace Tests\Feature\Grading;

use App\Enums\AuditAction;
use App\Enums\Permission as PermissionCode;
use App\Enums\SystemRole;
use App\Models\AssessmentCategory;
use App\Models\AuditLog;
use App\Models\ClassSubject;
use App\Models\Permission;
use App\Models\Role;
use App\Services\Grading\GradingThresholdService;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * The Grading Setup page (every grading setting in one place), Copy
 * Weights, "Start from another subject" on the weights page, and the
 * dashboard alert for subjects without weights.
 *
 * Fixture (BuildsGradingFixtures): Batch A Subject 1 has Quizzes 40 /
 * Examinations 60; Batch A Subject 2 and Batch B Subject 1 have no weights.
 */
class GradingSetupTest extends TestCase
{
    use BuildsGradingFixtures;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildGradingFixtures();
    }

    // ------------------------------------------------------------------
    // The page
    // ------------------------------------------------------------------

    public function test_administrator_sees_every_subject_of_the_active_period_with_its_weights(): void
    {
        $this->actingAs($this->academicAdmin)
            ->get('/grading-setup')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('staff/grading-setup/index')
                ->where('period', ['id' => $this->activePeriod->id, 'name' => 'Period Current', 'isActive' => true])
                ->where('thresholds', null)
                ->has('subjectWeights', 3)
                ->where('subjectWeights.0.classSubjectId', $this->offeringA1->id)
                ->where('subjectWeights.0.components', [['name' => 'Quizzes', 'weight' => '40'], ['name' => 'Examinations', 'weight' => '60']])
                ->where('subjectWeights.1.classSubjectId', $this->offeringA2->id)
                ->where('subjectWeights.1.components', [])
                ->where('subjectWeights.2.classSubjectId', $this->offeringB1->id)
                ->where('subjectWeights.2.components', [])
                // Only subjects that have weights can be copied from.
                ->has('copySources', 1)
                ->where('copySources.0.classSubjectId', $this->offeringA1->id)
                ->where('copySources.0.label', 'Sample Batch A · Subject 1 (Period Current)')
                ->where('can.configurePerformance', true));
    }

    public function test_the_worked_example_is_calculated_by_the_grade_engine(): void
    {
        $this->app->make(GradingThresholdService::class)->save($this->activePeriod, '75', '80', null);

        // Quizzes 90% x 40 = 36, Examinations 80% x 60 = 48: 84.00, Passing.
        // With only Quizzes assessed, the grade is over 40% of the weight: 90.00.
        $this->actingAs($this->academicAdmin)
            ->get('/grading-setup')
            ->assertInertia(fn (Assert $page) => $page
                ->where('example.source', 'Sample Batch A · Subject 1')
                ->where('example.components', [
                    ['name' => 'Quizzes', 'weight' => 40, 'result' => 90, 'points' => 36],
                    ['name' => 'Examinations', 'weight' => 60, 'result' => 80, 'points' => 48],
                ])
                ->where('example.grade', 84)
                ->where('example.standing.value', 'passing')
                ->where('example.partial', ['assessed' => ['Quizzes'], 'assessedWeight' => 40, 'grade' => 90]));
    }

    public function test_a_period_without_weights_gets_a_generic_example(): void
    {
        $this->actingAs($this->academicAdmin)
            ->get("/grading-setup?period={$this->pastPeriod->id}")
            ->assertInertia(fn (Assert $page) => $page
                ->where('period.id', $this->pastPeriod->id)
                ->where('example.source', null)
                ->where('example.grade', 84));
    }

    public function test_an_unknown_period_falls_back_to_the_active_one(): void
    {
        $this->actingAs($this->academicAdmin)
            ->get('/grading-setup?period=99999')
            ->assertInertia(fn (Assert $page) => $page->where('period.id', $this->activePeriod->id));
    }

    public function test_only_users_who_configure_grading_can_open_it(): void
    {
        $this->get('/grading-setup')->assertRedirect('/login');
        $this->actingAs($this->alpha)->get('/grading-setup')->assertForbidden();
        $this->actingAs($this->candidateInA->user)->get('/grading-setup')->assertForbidden();
        $this->actingAs($this->userWithRole(SystemRole::SuperAdministrator))->get('/grading-setup')->assertOk();

        // The permission alone is enough; the other links follow their own permissions.
        $role = Role::query()->create(['code' => 'grading_officer', 'name' => 'Grading Officer']);
        $role->permissions()->sync(Permission::query()->whereIn('code', [
            PermissionCode::AccessStaffArea->value,
            PermissionCode::ConfigureGrading->value,
        ])->pluck('id'));
        $officer = $this->userWithRole(SystemRole::Instructor);
        $officer->role()->associate($role)->save();

        $this->actingAs($officer->fresh())
            ->get('/grading-setup')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('can', ['manageClasses' => false, 'managePeriods' => false, 'configurePerformance' => false, 'configureFitness' => false, 'manageAttendance' => false]));
    }

    // ------------------------------------------------------------------
    // Copy Weights
    // ------------------------------------------------------------------

    public function test_copy_weights_fills_subjects_that_have_none_and_audits_each_copy(): void
    {
        $this->actingAs($this->academicAdmin)
            ->post('/grading-setup/copy-weights', [
                'source' => $this->offeringA1->id,
                'targets' => [$this->offeringA2->id, $this->offeringB1->id],
                'period' => $this->activePeriod->id,
            ])
            ->assertRedirect(route('grading-setup.index', ['period' => $this->activePeriod->id]))
            ->assertInertiaFlash('toast.message', 'Weights copied to 2 subjects.');

        foreach ([$this->offeringA2, $this->offeringB1] as $target) {
            $this->assertSame(
                [['Quizzes', '40.00'], ['Examinations', '60.00']],
                $target->assessmentCategories()->get()->map(fn (AssessmentCategory $category) => [$category->name, (string) $category->weight])->all(),
            );

            $entry = AuditLog::query()
                ->where('action', AuditAction::GradingSchemeUpdated->value)
                ->where('auditable_id', $target->id)
                ->sole();
            $this->assertSame('Copied from Sample Batch A · Subject 1 (Period Current)', $entry->reason);
        }

        // The source is unchanged.
        $this->assertSame(2, $this->offeringA1->assessmentCategories()->count());
    }

    public function test_copy_weights_never_changes_a_subject_that_already_has_weights(): void
    {
        $this->setScheme($this->offeringB1, ['Written Work' => '100']);

        $this->actingAs($this->academicAdmin)
            ->from('/grading-setup')
            ->post('/grading-setup/copy-weights', [
                'source' => $this->offeringA1->id,
                'targets' => [$this->offeringA2->id, $this->offeringB1->id],
            ])
            ->assertRedirect('/grading-setup')
            ->assertSessionHasErrors(['targets' => 'Sample Batch B · Subject 1 already has weights. Change it on its own page instead. Nothing was copied.']);

        // All or nothing: Subject 2 of Batch A did not receive the weights either.
        $this->assertSame(0, $this->offeringA2->assessmentCategories()->count());
        $this->assertSame(['Written Work'], $this->offeringB1->assessmentCategories()->pluck('name')->all());
    }

    public function test_copy_weights_needs_a_source_with_weights_and_at_least_one_target(): void
    {
        $this->actingAs($this->academicAdmin)
            ->post('/grading-setup/copy-weights', ['source' => $this->offeringA2->id, 'targets' => [$this->offeringB1->id]])
            ->assertSessionHasErrors(['source' => 'The chosen subject has no weights to copy. Choose one that is set up.']);

        $this->actingAs($this->academicAdmin)
            ->post('/grading-setup/copy-weights', ['source' => $this->offeringA1->id, 'targets' => []])
            ->assertSessionHasErrors('targets');

        $this->actingAs($this->academicAdmin)
            ->post('/grading-setup/copy-weights', ['source' => 99999, 'targets' => [99998]])
            ->assertSessionHasErrors(['source', 'targets.0']);

        $this->assertSame(0, $this->offeringB1->assessmentCategories()->count());
    }

    public function test_copying_a_subject_onto_itself_changes_nothing(): void
    {
        $this->actingAs($this->academicAdmin)
            ->post('/grading-setup/copy-weights', ['source' => $this->offeringA1->id, 'targets' => [$this->offeringA1->id]])
            ->assertSessionHasNoErrors()
            ->assertInertiaFlash('toast.message', 'Weights copied to 0 subjects.');

        $this->assertSame(2, $this->offeringA1->assessmentCategories()->count());
    }

    public function test_instructors_cannot_copy_weights(): void
    {
        $this->actingAs($this->alpha)
            ->post('/grading-setup/copy-weights', ['source' => $this->offeringA1->id, 'targets' => [$this->offeringA2->id]])
            ->assertForbidden();

        $this->assertSame(0, $this->offeringA2->assessmentCategories()->count());
    }

    // ------------------------------------------------------------------
    // The weights page of one subject
    // ------------------------------------------------------------------

    public function test_an_empty_subject_can_start_from_another_subjects_weights(): void
    {
        $this->actingAs($this->academicAdmin)
            ->get("/classes/{$this->batchA->id}/subjects/{$this->offeringA2->id}/grading?return=setup")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('staff/classes/grading')
                ->where('returnTo', 'setup')
                ->has('copySources', 1)
                ->where('copySources.0.classSubjectId', $this->offeringA1->id));

        // A subject that has weights is changed in place, so nothing is offered.
        $this->actingAs($this->academicAdmin)
            ->get("/classes/{$this->batchA->id}/subjects/{$this->offeringA1->id}/grading")
            ->assertInertia(fn (Assert $page) => $page->where('copySources', [])->where('returnTo', null));
    }

    public function test_saving_from_grading_setup_returns_there(): void
    {
        $this->actingAs($this->academicAdmin)
            ->put("/classes/{$this->batchA->id}/subjects/{$this->offeringA2->id}/grading", [
                'categories' => [['id' => null, 'name' => 'Quizzes', 'weight' => '100']],
                'return' => 'setup',
            ])
            ->assertRedirect(route('grading-setup.index', ['period' => $this->activePeriod->id]))
            ->assertInertiaFlash('toast.message', 'Weights for Subject 2 saved.');

        $this->actingAs($this->academicAdmin)
            ->put("/classes/{$this->batchA->id}/subjects/{$this->offeringA2->id}/grading", [
                'categories' => [['id' => null, 'name' => 'Quizzes', 'weight' => '100']],
                'return' => 'https://example.com',
            ])
            ->assertSessionHasErrors('return');
    }

    public function test_passing_and_warning_grades_opened_from_grading_setup_return_there(): void
    {
        $this->actingAs($this->academicAdmin)
            ->get("/academic-periods/{$this->activePeriod->id}/grading-thresholds?return=setup")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('returnTo', 'setup'));

        $this->actingAs($this->academicAdmin)
            ->get("/academic-periods/{$this->activePeriod->id}/grading-thresholds?return=elsewhere")
            ->assertInertia(fn (Assert $page) => $page->where('returnTo', null));

        $this->actingAs($this->academicAdmin)
            ->put("/academic-periods/{$this->activePeriod->id}/grading-thresholds", [
                'passing_grade' => '75',
                'warning_grade' => '80',
                'return' => 'setup',
            ])
            ->assertRedirect(route('grading-setup.index', ['period' => $this->activePeriod->id]));

        $this->actingAs($this->academicAdmin)
            ->put("/academic-periods/{$this->activePeriod->id}/grading-thresholds", [
                'passing_grade' => '75',
                'warning_grade' => '80',
                'return' => 'https://example.com',
            ])
            ->assertSessionHasErrors('return');
    }

    // ------------------------------------------------------------------
    // Dashboard and performance areas
    // ------------------------------------------------------------------

    public function test_dashboard_counts_subjects_of_the_active_period_without_weights(): void
    {
        $this->actingAs($this->academicAdmin)
            ->get('/dashboard')
            ->assertInertia(fn (Assert $page) => $page
                ->where('missingWeights', ['count' => 2, 'periodId' => $this->activePeriod->id, 'periodName' => 'Period Current']));

        $this->actingAs($this->alpha)
            ->get('/dashboard')
            ->assertInertia(fn (Assert $page) => $page->where('missingWeights', null));

        $this->setScheme($this->offeringA2, ['Quizzes' => '100']);
        $this->setScheme($this->offeringB1, ['Quizzes' => '100']);

        $this->actingAs($this->academicAdmin)
            ->get('/dashboard')
            ->assertInertia(fn (Assert $page) => $page->where('missingWeights', null));
    }

    public function test_the_overview_reports_subjects_that_count_toward_no_area(): void
    {
        // The fixture's subjects belong to no performance area.
        $this->actingAs($this->academicAdmin)
            ->get('/grading-setup')
            ->assertInertia(fn (Assert $page) => $page
                ->where('areas.list', [])
                ->where('areas.unmappedSubjects', ['Subject 1', 'Subject 2'])
                ->where('areas.hasMustPass', false));
    }

    public function test_class_subjects_of_other_periods_are_not_listed(): void
    {
        $old = ClassSubject::query()->where('class_batch_id', $this->batchOld->id)->sole();

        $this->actingAs($this->academicAdmin)
            ->get('/grading-setup')
            ->assertInertia(fn (Assert $page) => $page->where(
                'subjectWeights',
                fn ($rows) => collect($rows)->pluck('classSubjectId')->doesntContain($old->id),
            ));
    }
}
