<?php

namespace Tests\Feature\Academic;

use App\Enums\AuditAction;
use App\Enums\SystemRole;
use App\Models\AcademicPeriod;
use App\Models\AuditLog;
use App\Models\Candidate;
use App\Models\ClassBatch;
use App\Models\ClassSubject;
use App\Models\InstructorAssignment;
use App\Models\Subject;
use App\Models\User;
use App\Services\ClassBatchService;
use App\Services\InstructorAssignmentService;
use Database\Factories\CampusFactory;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ClassBatchTest extends TestCase
{
    private User $admin;

    private AcademicPeriod $period;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->userWithRole(SystemRole::AcademicAdministrator);
        $this->period = AcademicPeriod::factory()->active()->create(['name' => 'Active Period']);
        // The institution's only campus: new classes go there without choosing it.
        CampusFactory::defaultCampusId();
    }

    public function test_administrator_creates_a_class_in_a_period(): void
    {
        $response = $this->actingAs($this->admin)
            ->post('/classes', ['academic_period_id' => $this->period->id, 'name' => ' Sample Batch A ']);

        $classBatch = ClassBatch::query()->where('name', 'Sample Batch A')->sole();
        $response->assertRedirect(route('classes.show', $classBatch));
        $this->assertSame($this->period->id, $classBatch->academic_period_id);

        $entry = AuditLog::query()->where('action', AuditAction::ClassBatchCreated->value)->sole();
        $this->assertSame(['name' => 'Sample Batch A', 'academic_period' => 'Active Period', 'campus' => 'Main Campus'], $entry->new_values);
    }

    public function test_class_names_are_unique_within_a_period_only(): void
    {
        ClassBatch::factory()->for($this->period)->create(['name' => 'Sample Batch A']);
        $otherPeriod = AcademicPeriod::factory()->create();

        $this->actingAs($this->admin)
            ->post('/classes', ['academic_period_id' => $this->period->id, 'name' => 'Sample Batch A'])
            ->assertSessionHasErrors(['name' => 'Another class of this campus in this academic period already uses this name.']);

        $this->actingAs($this->admin)
            ->post('/classes', ['academic_period_id' => $otherPeriod->id, 'name' => 'Sample Batch A'])
            ->assertSessionHasNoErrors();
    }

    public function test_renaming_keeps_the_period_and_rejects_period_changes(): void
    {
        $classBatch = ClassBatch::factory()->for($this->period)->create(['name' => 'Old Name']);
        $otherPeriod = AcademicPeriod::factory()->create();

        $this->actingAs($this->admin)
            ->put("/classes/{$classBatch->id}", ['name' => 'New Name', 'academic_period_id' => $otherPeriod->id])
            ->assertSessionHasErrors(['academic_period_id' => 'The academic period of an existing class cannot be changed.']);

        $this->actingAs($this->admin)
            ->put("/classes/{$classBatch->id}", ['name' => 'New Name'])
            ->assertRedirect(route('classes.show', $classBatch));

        $fresh = $classBatch->fresh();
        $this->assertSame('New Name', $fresh->name);
        $this->assertSame($this->period->id, $fresh->academic_period_id);
    }

    public function test_index_shows_the_active_period_by_default(): void
    {
        ClassBatch::factory()->for($this->period)->create(['name' => 'Current Class']);
        ClassBatch::factory()->for(AcademicPeriod::factory())->create(['name' => 'Old Class']);

        $this->actingAs($this->admin)
            ->get('/classes')
            ->assertInertia(fn (Assert $page) => $page
                ->component('staff/classes/index')
                ->where('filters.period', (string) $this->period->id)
                ->has('classes.data', 1)
                ->where('classes.data.0.name', 'Current Class'));
    }

    public function test_show_lists_subjects_instructors_and_candidates(): void
    {
        $classBatch = ClassBatch::factory()->for($this->period)->create();
        $taken = Subject::factory()->create(['name' => 'Subject 1']);
        Subject::factory()->create(['name' => 'Subject 2']);
        Subject::factory()->inactive()->create(['name' => 'Subject Retired']);
        $offering = $this->app->make(ClassBatchService::class)->addSubject($classBatch, $taken);
        $instructor = $this->userWithRole(SystemRole::Instructor, ['name' => 'Instructor Alpha']);
        $this->app->make(InstructorAssignmentService::class)->assign($offering, $instructor);
        Candidate::factory()->create(['class_batch_id' => $classBatch->id, 'last_name' => '001']);

        $this->actingAs($this->admin)
            ->get("/classes/{$classBatch->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('staff/classes/show')
                ->has('offerings', 1)
                ->where('offerings.0.subject.name', 'Subject 1')
                ->where('offerings.0.instructors.0.name', 'Instructor Alpha')
                ->has('candidates.data', 1)
                ->where('subjectOptions', fn ($options) => collect($options)->pluck('name')->all() === ['Subject 2'])
                ->where('instructorOptions', fn ($options) => collect($options)->pluck('name')->contains('Instructor Alpha'))
                ->where('can.manageAssignments', true));
    }

    public function test_administrator_adds_an_active_subject_once(): void
    {
        $classBatch = ClassBatch::factory()->for($this->period)->create();
        $subject = Subject::factory()->create();
        $retired = Subject::factory()->inactive()->create();

        $this->actingAs($this->admin)
            ->post("/classes/{$classBatch->id}/subjects", ['subject_id' => $subject->id])
            ->assertRedirect(route('classes.show', $classBatch));
        $this->assertDatabaseHas('class_subjects', ['class_batch_id' => $classBatch->id, 'subject_id' => $subject->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => AuditAction::ClassSubjectAdded->value]);

        $this->actingAs($this->admin)
            ->post("/classes/{$classBatch->id}/subjects", ['subject_id' => $subject->id])
            ->assertSessionHasErrors(['subject_id' => 'This class already takes this subject.']);

        $this->actingAs($this->admin)
            ->post("/classes/{$classBatch->id}/subjects", ['subject_id' => $retired->id])
            ->assertSessionHasErrors(['subject_id' => 'Select an active subject.']);
    }

    public function test_removing_a_subject_also_removes_its_instructor_assignments(): void
    {
        $classBatch = ClassBatch::factory()->for($this->period)->create();
        $offering = $this->app->make(ClassBatchService::class)->addSubject($classBatch, Subject::factory()->create(['name' => 'Subject 1']));
        $instructor = $this->userWithRole(SystemRole::Instructor, ['name' => 'Instructor Alpha']);
        $this->app->make(InstructorAssignmentService::class)->assign($offering, $instructor);

        $this->actingAs($this->admin)
            ->delete("/classes/{$classBatch->id}/subjects/{$offering->id}")
            ->assertRedirect(route('classes.show', $classBatch));

        $this->assertDatabaseMissing('class_subjects', ['id' => $offering->id]);
        $this->assertSame(0, InstructorAssignment::query()->count());

        $entry = AuditLog::query()->where('action', AuditAction::ClassSubjectRemoved->value)->sole();
        $this->assertSame(['Instructor Alpha'], $entry->old_values['instructors']);
    }

    public function test_a_subject_can_only_be_removed_through_its_own_class(): void
    {
        $classBatch = ClassBatch::factory()->for($this->period)->create();
        $otherClass = ClassBatch::factory()->for($this->period)->create();
        $offering = $this->app->make(ClassBatchService::class)->addSubject($otherClass, Subject::factory()->create());

        $this->actingAs($this->admin)
            ->delete("/classes/{$classBatch->id}/subjects/{$offering->id}")
            ->assertNotFound();

        $this->assertDatabaseHas('class_subjects', ['id' => $offering->id]);
    }

    public function test_instructors_cannot_manage_classes(): void
    {
        $instructor = $this->userWithRole(SystemRole::Instructor);
        $classBatch = ClassBatch::factory()->for($this->period)->create();

        $this->actingAs($instructor)->get('/classes')->assertForbidden();
        $this->actingAs($instructor)->get("/classes/{$classBatch->id}")->assertForbidden();
        $this->actingAs($instructor)
            ->post("/classes/{$classBatch->id}/subjects", ['subject_id' => Subject::factory()->create()->id])
            ->assertForbidden();

        $this->assertSame(0, ClassSubject::query()->count());
    }
}
