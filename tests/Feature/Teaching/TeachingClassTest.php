<?php

namespace Tests\Feature\Teaching;

use App\Enums\SystemRole;
use App\Models\AcademicPeriod;
use App\Models\Candidate;
use App\Models\ClassBatch;
use App\Models\InstructorAssignment;
use App\Models\Subject;
use App\Services\InstructorAssignmentService;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class TeachingClassTest extends TestCase
{
    use BuildsTeachingFixtures;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildTeachingFixtures();
    }

    public function test_my_classes_lists_only_classes_the_instructor_teaches(): void
    {
        $this->actingAs($this->bravo)
            ->get('/my-classes')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('staff/teaching/classes/index')
                ->where('filters.period', (string) $this->activePeriod->id)
                ->has('classes', 2)
                ->where('classes.0.name', 'Sample Batch A')
                // Only the subjects Bravo teaches there, not Alpha's.
                ->where('classes.0.subjects', [['code' => 'SUBJ-2', 'name' => 'Subject 2']])
                ->where('classes.0.enrolledCount', 2)
                ->where('classes.1.name', 'Sample Batch B'));
    }

    public function test_past_periods_are_selectable_only_where_the_instructor_taught(): void
    {
        $this->actingAs($this->alpha)
            ->get("/my-classes?period={$this->pastPeriod->id}")
            ->assertInertia(fn (Assert $page) => $page
                ->has('periods', 2)
                ->has('classes', 1)
                ->where('classes.0.name', 'Sample Batch Old'));

        // Bravo never taught in the past period, so the filter falls back to the active period.
        $this->actingAs($this->bravo)
            ->get("/my-classes?period={$this->pastPeriod->id}")
            ->assertInertia(fn (Assert $page) => $page
                ->has('periods', 1)
                ->where('filters.period', (string) $this->activePeriod->id));
    }

    public function test_my_classes_opens_the_active_period_even_when_a_later_period_exists(): void
    {
        $upcoming = AcademicPeriod::factory()->create(['name' => 'Period Upcoming', 'starts_on' => '2027-01-04', 'ends_on' => '2027-05-28']);
        $upcomingClass = ClassBatch::factory()->for($upcoming)->create(['name' => 'Sample Batch Next']);
        $this->teach($this->alpha, $this->offering($upcomingClass, Subject::query()->where('code', 'SUBJ-1')->sole()));

        $this->actingAs($this->alpha)
            ->get('/my-classes')
            ->assertInertia(fn (Assert $page) => $page
                ->where('filters.period', (string) $this->activePeriod->id)
                ->where('periods.0.isActive', true)
                ->has('periods', 3));
    }

    public function test_past_period_classes_stay_open_to_the_instructor_who_taught_them(): void
    {
        $pastCandidate = Candidate::query()->where('class_batch_id', $this->batchOld->id)->sole();

        $this->actingAs($this->alpha)->get("/my-classes/{$this->batchOld->id}")->assertOk();
        $this->actingAs($this->alpha)->get("/candidates/{$pastCandidate->id}")->assertOk();

        $this->actingAs($this->bravo)->get("/my-classes/{$this->batchOld->id}")->assertForbidden();
        $this->actingAs($this->bravo)->get("/candidates/{$pastCandidate->id}")->assertForbidden();
    }

    public function test_instructor_opens_a_class_they_teach(): void
    {
        $this->actingAs($this->alpha)
            ->get("/my-classes/{$this->batchA->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('staff/teaching/classes/show')
                ->where('subjects.0.code', 'SUBJ-1')
                ->where('subjects.0.name', 'Subject 1')
                ->has('subjects', 1)
                ->has('candidates.data', 3));
    }

    public function test_class_candidates_can_be_searched_and_filtered(): void
    {
        $this->actingAs($this->alpha)
            ->get("/my-classes/{$this->batchA->id}?search=A2")
            ->assertInertia(fn (Assert $page) => $page->has('candidates.data', 1)->where('candidates.data.0.name', 'Candidate A2'));

        $this->actingAs($this->alpha)
            ->get("/my-classes/{$this->batchA->id}?status=withdrawn")
            ->assertInertia(fn (Assert $page) => $page->has('candidates.data', 1)->where('candidates.data.0.status.label', 'Withdrawn'));
    }

    public function test_instructor_cannot_access_an_unrelated_class(): void
    {
        $this->actingAs($this->alpha)
            ->get("/my-classes/{$this->batchB->id}")
            ->assertForbidden();
    }

    public function test_access_ends_when_the_assignment_is_removed(): void
    {
        $assignment = InstructorAssignment::query()->where('instructor_id', $this->alpha->id)
            ->whereHas('classSubject', fn ($query) => $query->where('class_batch_id', $this->batchA->id))
            ->sole();

        $this->app->make(InstructorAssignmentService::class)->unassign($assignment);

        $this->actingAs($this->alpha)->get("/my-classes/{$this->batchA->id}")->assertForbidden();
        $this->actingAs($this->alpha)->get("/candidates/{$this->candidateInA->id}")->assertForbidden();
    }

    public function test_administrators_and_candidates_have_no_teaching_view(): void
    {
        $this->actingAs($this->userWithRole(SystemRole::AcademicAdministrator))->get('/my-classes')->assertForbidden();
        $this->actingAs($this->candidateInA->user)->get('/my-classes')->assertForbidden();
        $this->actingAs($this->candidateInA->user)->get("/my-classes/{$this->batchA->id}")->assertForbidden();
    }
}
