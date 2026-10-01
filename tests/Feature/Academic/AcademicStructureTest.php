<?php

namespace Tests\Feature\Academic;

use App\Enums\SystemRole;
use App\Models\AcademicPeriod;
use App\Models\Candidate;
use App\Models\ClassBatch;
use App\Models\Subject;
use App\Models\User;
use App\Services\ClassBatchService;
use App\Services\InstructorAssignmentService;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Academic periods, classes, subjects and instructors are shown together:
 * the period page lists its whole structure and the subject list says where
 * each subject is taught in the active period.
 */
class AcademicStructureTest extends TestCase
{
    private User $admin;

    private AcademicPeriod $period;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->userWithRole(SystemRole::AcademicAdministrator);
        $this->period = AcademicPeriod::factory()->active()->create(['name' => 'Period Current']);

        $classes = $this->app->make(ClassBatchService::class);
        $classA = ClassBatch::factory()->for($this->period)->create(['name' => 'Class A']);
        $subjectOne = Subject::factory()->create(['code' => 'SUB-1', 'name' => 'Subject 1']);
        $subjectTwo = Subject::factory()->create(['code' => 'SUB-2', 'name' => 'Subject 2']);

        $taught = $classes->addSubject($classA, $subjectOne);
        $classes->addSubject($classA, $subjectTwo);
        $this->app->make(InstructorAssignmentService::class)
            ->assign($taught, $this->userWithRole(SystemRole::Instructor, ['name' => 'Instructor Alpha']));

        Candidate::factory()->count(3)->create(['class_batch_id' => $classA->id]);

        // A class in another period must not appear on this period's page.
        $classes->addSubject(ClassBatch::factory()->for(AcademicPeriod::factory())->create(['name' => 'Old Class']), $subjectOne);
    }

    public function test_period_page_lists_classes_subjects_and_instructors(): void
    {
        $this->actingAs($this->admin)
            ->get(route('academic-periods.show', $this->period))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('staff/academic-periods/show')
                ->where('period.name', 'Period Current')
                ->has('classes', 1)
                ->where('classes.0.name', 'Class A')
                ->where('classes.0.candidateCount', 3)
                ->has('classes.0.subjects', 2)
                ->where('classes.0.subjects.0.name', 'Subject 1')
                ->where('classes.0.subjects.0.instructors.0.name', 'Instructor Alpha')
                ->has('classes.0.subjects.1.instructors', 0)
                ->where('totals', [
                    'classes' => 1,
                    'subjects' => 2,
                    'instructors' => 1,
                    'candidates' => 3,
                    'unassignedSubjects' => 1,
                ]));
    }

    public function test_instructors_cannot_open_the_period_page(): void
    {
        $this->actingAs($this->userWithRole(SystemRole::Instructor))
            ->get(route('academic-periods.show', $this->period))
            ->assertForbidden();
    }

    public function test_subject_list_shows_where_each_subject_is_taught_in_the_active_period(): void
    {
        $this->actingAs($this->admin)
            ->get('/subjects')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('staff/subjects/index')
                ->where('activePeriod.name', 'Period Current')
                ->where('subjects.data.0.name', 'Subject 1')
                ->where('subjects.data.0.classCount', 2)
                ->has('subjects.data.0.taughtIn', 1)
                ->where('subjects.data.0.taughtIn.0.className', 'Class A')
                ->where('subjects.data.0.taughtIn.0.instructors', ['Instructor Alpha'])
                ->where('subjects.data.1.taughtIn.0.instructors', []));
    }

    public function test_class_page_links_to_its_period(): void
    {
        $classA = ClassBatch::query()->where('name', 'Class A')->sole();

        $this->actingAs($this->admin)
            ->get(route('classes.show', $classA))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('classBatch.period.id', $this->period->id)
                ->where('can.viewPeriod', true));
    }
}
