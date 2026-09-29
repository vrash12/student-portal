<?php

namespace Tests\Feature\Academic;

use App\Enums\AuditAction;
use App\Enums\SystemRole;
use App\Models\AcademicPeriod;
use App\Models\AuditLog;
use App\Models\ClassBatch;
use App\Models\ClassSubject;
use App\Models\InstructorAssignment;
use App\Models\Subject;
use App\Models\User;
use App\Services\ClassBatchService;
use App\Services\InstructorAssignmentService;
use Inertia\Testing\AssertableInertia as Assert;
use InvalidArgumentException;
use Tests\TestCase;

class InstructorAssignmentTest extends TestCase
{
    private User $admin;

    private ClassSubject $offering;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->userWithRole(SystemRole::AcademicAdministrator);
        $classBatch = ClassBatch::factory()->for(AcademicPeriod::factory()->active())->create(['name' => 'Sample Batch A']);
        $this->offering = $this->app->make(ClassBatchService::class)
            ->addSubject($classBatch, Subject::factory()->create(['name' => 'Subject 1']));
    }

    public function test_administrator_assigns_an_instructor(): void
    {
        $instructor = $this->userWithRole(SystemRole::Instructor, ['name' => 'Instructor Alpha']);

        $this->actingAs($this->admin)
            ->from("/classes/{$this->offering->class_batch_id}")
            ->post('/instructor-assignments', ['class_subject_id' => $this->offering->id, 'instructor_id' => $instructor->id])
            ->assertRedirect("/classes/{$this->offering->class_batch_id}")
            ->assertInertiaFlash('toast.type', 'success');

        $this->assertDatabaseHas('instructor_assignments', ['class_subject_id' => $this->offering->id, 'instructor_id' => $instructor->id]);

        $entry = AuditLog::query()->where('action', AuditAction::InstructorAssigned->value)->sole();
        $this->assertSame(['instructor' => 'Instructor Alpha', 'class' => 'Sample Batch A', 'subject' => 'Subject 1'], $entry->new_values);
    }

    public function test_only_active_teaching_staff_can_be_assigned(): void
    {
        $academicAdmin = $this->userWithRole(SystemRole::AcademicAdministrator);
        $inactiveInstructor = User::factory()->withRole(SystemRole::Instructor)->inactive()->create();
        $candidate = $this->userWithRole(SystemRole::Candidate);

        foreach ([$academicAdmin, $inactiveInstructor, $candidate] as $account) {
            $this->actingAs($this->admin)
                ->post('/instructor-assignments', ['class_subject_id' => $this->offering->id, 'instructor_id' => $account->id])
                ->assertSessionHasErrors(['instructor_id' => 'Select an active instructor.']);
        }

        $this->assertSame(0, InstructorAssignment::query()->count());
    }

    public function test_the_service_refuses_ineligible_accounts_even_without_validation(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->app->make(InstructorAssignmentService::class)
            ->assign($this->offering, $this->userWithRole(SystemRole::AcademicAdministrator));
    }

    public function test_an_instructor_is_assigned_to_a_class_subject_only_once(): void
    {
        $instructor = $this->userWithRole(SystemRole::Instructor);
        $this->app->make(InstructorAssignmentService::class)->assign($this->offering, $instructor);

        $this->actingAs($this->admin)
            ->post('/instructor-assignments', ['class_subject_id' => $this->offering->id, 'instructor_id' => $instructor->id])
            ->assertSessionHasErrors(['instructor_id' => 'This instructor is already assigned to this subject for this class.']);
    }

    public function test_administrator_removes_an_assignment(): void
    {
        $instructor = $this->userWithRole(SystemRole::Instructor, ['name' => 'Instructor Alpha']);
        $assignment = $this->app->make(InstructorAssignmentService::class)->assign($this->offering, $instructor);

        $this->actingAs($this->admin)
            ->from("/instructors/{$instructor->id}")
            ->delete("/instructor-assignments/{$assignment->id}")
            ->assertRedirect("/instructors/{$instructor->id}");

        $this->assertDatabaseMissing('instructor_assignments', ['id' => $assignment->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => AuditAction::InstructorUnassigned->value, 'auditable_id' => $assignment->id]);
    }

    public function test_instructor_directory_lists_teaching_staff_only(): void
    {
        $this->userWithRole(SystemRole::Instructor, ['name' => 'Instructor Alpha']);
        User::factory()->withRole(SystemRole::Instructor)->inactive()->create(['name' => 'Instructor Former']);

        $this->actingAs($this->admin)
            ->get('/instructors')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('staff/instructors/index')
                ->has('instructors.data', 2)
                ->where('instructors.data.0.name', 'Instructor Alpha')
                ->where('instructors.data.1.isActive', false));
    }

    public function test_instructor_page_lists_assignments_and_unassigned_subjects(): void
    {
        $instructor = $this->userWithRole(SystemRole::Instructor);
        $this->app->make(InstructorAssignmentService::class)->assign($this->offering, $instructor);
        $second = $this->app->make(ClassBatchService::class)
            ->addSubject($this->offering->classBatch, Subject::factory()->create(['name' => 'Subject 2']));

        $this->actingAs($this->admin)
            ->get("/instructors/{$instructor->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('staff/instructors/show')
                ->has('assignments', 1)
                ->where('assignments.0.subject.name', 'Subject 1')
                ->where('offeringOptions', [['id' => $second->id, 'label' => 'Sample Batch A · Subject 2']]));
    }

    public function test_non_teaching_accounts_have_no_instructor_page(): void
    {
        $this->actingAs($this->admin)
            ->get("/instructors/{$this->admin->id}")
            ->assertNotFound();
    }

    public function test_instructors_cannot_manage_assignments(): void
    {
        $instructor = $this->userWithRole(SystemRole::Instructor);

        $this->actingAs($instructor)->get('/instructors')->assertForbidden();
        $this->actingAs($instructor)
            ->post('/instructor-assignments', ['class_subject_id' => $this->offering->id, 'instructor_id' => $instructor->id])
            ->assertForbidden();

        $this->assertSame(0, InstructorAssignment::query()->count());
    }
}
