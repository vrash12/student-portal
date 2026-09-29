<?php

namespace Tests\Feature\Teaching;

use App\Enums\Permission as PermissionCode;
use App\Enums\SystemRole;
use App\Models\AcademicPeriod;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Subject;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class InstructorDashboardTest extends TestCase
{
    use BuildsTeachingFixtures;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildTeachingFixtures();
    }

    public function test_instructor_sees_only_their_own_assignments_in_the_active_period(): void
    {
        $this->actingAs($this->alpha)
            ->get('/dashboard')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('staff/dashboard')
                ->where('teaching.period.name', 'Period Current')
                ->has('teaching.assignments', 1)
                ->where('teaching.assignments.0.subject.name', 'Subject 1')
                ->where('teaching.assignments.0.classBatch.name', 'Sample Batch A')
                // Withdrawn candidates are not counted as enrolled.
                ->where('teaching.assignments.0.enrolledCount', 2)
                ->where('teaching.totals', ['subjects' => 1, 'classes' => 1, 'enrolledCandidates' => 2]));
    }

    public function test_totals_count_each_class_and_candidate_once(): void
    {
        $this->actingAs($this->bravo)
            ->get('/dashboard')
            ->assertInertia(fn (Assert $page) => $page
                ->has('teaching.assignments', 2)
                ->where('teaching.totals', ['subjects' => 2, 'classes' => 2, 'enrolledCandidates' => 3]));

        // Teaching Subject 1 to a second class adds a row but not a subject, and
        // a second subject in the same class does not double-count its candidates.
        $this->teach($this->bravo, $this->offering($this->batchA, Subject::query()->where('code', 'SUBJ-1')->sole()));

        $this->actingAs($this->bravo)
            ->get('/dashboard')
            ->assertInertia(fn (Assert $page) => $page
                ->has('teaching.assignments', 3)
                ->where('teaching.totals', ['subjects' => 2, 'classes' => 2, 'enrolledCandidates' => 3]));
    }

    public function test_instructor_without_assignments_sees_an_empty_overview(): void
    {
        $newcomer = $this->userWithRole(SystemRole::Instructor);

        $this->actingAs($newcomer)
            ->get('/dashboard')
            ->assertInertia(fn (Assert $page) => $page
                ->has('teaching.assignments', 0)
                ->where('teaching.totals', ['subjects' => 0, 'classes' => 0, 'enrolledCandidates' => 0]));
    }

    public function test_without_an_active_period_the_overview_says_so(): void
    {
        AcademicPeriod::query()->update(['is_active' => false]);

        $this->actingAs($this->alpha)
            ->get('/dashboard')
            ->assertInertia(fn (Assert $page) => $page
                ->where('teaching.period', null)
                ->has('teaching.assignments', 0));
    }

    public function test_a_custom_role_with_teaching_permission_gets_the_teaching_view(): void
    {
        $role = Role::query()->create(['code' => 'teaching_lead', 'name' => 'Teaching Lead']);
        $role->permissions()->sync(Permission::query()->whereIn('code', [
            PermissionCode::AccessStaffArea->value,
            PermissionCode::TeachClasses->value,
            PermissionCode::ViewAllCandidates->value,
        ])->pluck('id'));
        $this->alpha->role()->associate($role)->save();

        $this->actingAs($this->alpha->fresh())
            ->get('/dashboard')
            ->assertInertia(fn (Assert $page) => $page
                ->where('teaching.totals.subjects', 1)
                ->where('showAcademicOverview', true));

        $this->actingAs($this->alpha->fresh())->get('/my-classes')->assertOk();
    }

    public function test_losing_the_teaching_permission_removes_the_teaching_view_even_with_old_assignments(): void
    {
        $role = Role::query()->create(['code' => 'staff_only', 'name' => 'Staff Only']);
        $role->permissions()->sync(Permission::query()->where('code', PermissionCode::AccessStaffArea->value)->pluck('id'));
        $this->alpha->role()->associate($role)->save();
        $former = $this->alpha->fresh();

        $this->actingAs($former)->get('/dashboard')->assertInertia(fn (Assert $page) => $page->where('teaching', null));
        $this->actingAs($former)->get('/my-classes')->assertForbidden();
        $this->actingAs($former)->get("/my-classes/{$this->batchA->id}")->assertForbidden();
        $this->actingAs($former)->get("/candidates/{$this->candidateInA->id}")->assertForbidden();
    }

    public function test_sections_follow_permissions_not_role_names(): void
    {
        $this->actingAs($this->alpha)
            ->get('/dashboard')
            ->assertInertia(fn (Assert $page) => $page
                ->where('showAcademicOverview', false)
                ->where('accountSummary', null));

        $this->actingAs($this->userWithRole(SystemRole::AcademicAdministrator))
            ->get('/dashboard')
            ->assertInertia(fn (Assert $page) => $page
                ->where('teaching', null)
                ->where('showAcademicOverview', true)
                ->has('accountSummary'));
    }
}
