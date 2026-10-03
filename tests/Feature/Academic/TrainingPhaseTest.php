<?php

namespace Tests\Feature\Academic;

use App\Enums\AuditAction;
use App\Enums\SystemRole;
use App\Models\AcademicPeriod;
use App\Models\AuditLog;
use App\Models\Candidate;
use App\Models\ClassBatch;
use App\Models\ClassSubject;
use App\Models\Subject;
use App\Models\TrainingPhase;
use App\Models\User;
use App\Services\ClassBatchService;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Training phases and the phase and units of each subject of a class (owner
 * request, 2026-10-03).
 */
class TrainingPhaseTest extends TestCase
{
    private User $admin;

    private ClassBatch $class;

    private Subject $subject;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->userWithRole(SystemRole::AcademicAdministrator);
        $this->class = ClassBatch::factory()->for(AcademicPeriod::factory()->active())->create(['name' => 'Class A']);
        $this->subject = Subject::factory()->create(['code' => 'SUBJ-1', 'name' => 'Subject 1']);
    }

    private function phase(int $number): TrainingPhase
    {
        return TrainingPhase::query()->where('number', $number)->sole();
    }

    // Phases ------------------------------------------------------------------

    public function test_three_placeholder_phases_are_listed_in_order(): void
    {
        $this->actingAs($this->admin)->get('/training-phases')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('staff/training-phases/index')
                ->where('phases.0.name', 'Phase 1')->where('phases.1.name', 'Phase 2')->where('phases.2.name', 'Phase 3')
                ->where('phases.0.subjectCount', 0)
                ->where('nextNumber', 4));
    }

    public function test_phases_are_added_renamed_and_renumbered_with_an_audit_trail(): void
    {
        $this->actingAs($this->admin)->post('/training-phases', ['number' => '4', 'name' => '  Field Training  '])
            ->assertRedirect('/training-phases')->assertSessionHasNoErrors();
        $field = TrainingPhase::query()->where('name', 'Field Training')->sole();
        $this->assertSame(4, $field->number);

        $this->put("/training-phases/{$this->phase(1)->id}", ['number' => '1', 'name' => 'Phase I - Indoctrination'])->assertSessionHasNoErrors();
        $this->assertSame('Phase I - Indoctrination', $this->phase(1)->name);

        $this->assertSame(1, AuditLog::query()->where('action', AuditAction::TrainingPhaseCreated->value)->count());
        $update = AuditLog::query()->where('action', AuditAction::TrainingPhaseUpdated->value)->sole();
        $this->assertSame('Phase 1', $update->old_values['name']);
        $this->assertSame('Phase I - Indoctrination', $update->new_values['name']);
    }

    public function test_numbers_and_names_are_unique_and_numbers_stay_in_range(): void
    {
        $this->actingAs($this->admin);
        $this->post('/training-phases', ['number' => '2', 'name' => 'Phase 2'])
            ->assertSessionHasErrors(['number' => 'Another phase already has this number.', 'name' => 'Another phase already uses this name.']);
        $this->post('/training-phases', ['number' => '0', 'name' => 'Phase 0'])->assertSessionHasErrors('number');
        $this->post('/training-phases', ['number' => '21', 'name' => 'Phase 21'])->assertSessionHasErrors('number');
        $this->post('/training-phases', ['number' => '', 'name' => ''])->assertSessionHasErrors(['number', 'name']);
        $this->assertSame(3, TrainingPhase::query()->count());
    }

    public function test_only_a_phase_without_subjects_can_be_deleted(): void
    {
        $this->app->make(ClassBatchService::class)->addSubject($this->class, $this->subject, $this->phase(1), '3');

        $this->actingAs($this->admin)->delete("/training-phases/{$this->phase(1)->id}")->assertRedirect('/training-phases')
            ->assertSessionHas('inertia.flash_data.toast', ['type' => 'error', 'message' => 'Phase 1 is used by 1 subject of a class and cannot be deleted. Move them to another phase first.']);
        $this->assertSame(3, TrainingPhase::query()->count());

        $unused = $this->phase(3);
        $this->delete("/training-phases/{$unused->id}")->assertRedirect('/training-phases');
        $this->assertNull(TrainingPhase::query()->find($unused->id));
        $this->assertSame(['number' => 3, 'name' => 'Phase 3'], AuditLog::query()->where('action', AuditAction::TrainingPhaseDeleted->value)->sole()->old_values);
    }

    public function test_instructors_and_candidates_cannot_manage_phases(): void
    {
        $instructor = $this->userWithRole(SystemRole::Instructor);
        $candidate = Candidate::factory()->create(['class_batch_id' => $this->class->id]);

        foreach ([$instructor, $candidate->user] as $user) {
            $this->actingAs($user)->get('/training-phases')->assertForbidden();
            $this->actingAs($user)->post('/training-phases', ['number' => '4', 'name' => 'Phase 4'])->assertForbidden();
            $this->actingAs($user)->put("/training-phases/{$this->phase(1)->id}", ['number' => '1', 'name' => 'Renamed'])->assertForbidden();
            $this->actingAs($user)->delete("/training-phases/{$this->phase(3)->id}")->assertForbidden();
        }
        $this->assertSame(['Phase 1', 'Phase 2', 'Phase 3'], TrainingPhase::query()->ordered()->pluck('name')->all());
    }

    // Phase and units of a subject of a class -----------------------------------

    public function test_a_subject_is_added_in_a_phase_with_units_or_with_one_unit_by_default(): void
    {
        $this->actingAs($this->admin)->post("/classes/{$this->class->id}/subjects", [
            'subject_id' => $this->subject->id, 'training_phase_id' => $this->phase(2)->id, 'units' => '3',
        ])->assertSessionHasNoErrors();
        $offering = ClassSubject::query()->where('subject_id', $this->subject->id)->sole();
        $this->assertSame($this->phase(2)->id, $offering->training_phase_id);
        $this->assertSame('3.00', $offering->units);

        $subject2 = Subject::factory()->create(['code' => 'SUBJ-2', 'name' => 'Subject 2']);
        $this->post("/classes/{$this->class->id}/subjects", ['subject_id' => $subject2->id])->assertSessionHasNoErrors();
        $default = ClassSubject::query()->where('subject_id', $subject2->id)->sole();
        $this->assertNull($default->training_phase_id);
        $this->assertSame('1.00', $default->units);
    }

    public function test_phase_and_units_are_validated(): void
    {
        $this->actingAs($this->admin);
        foreach ([['units' => '0'], ['units' => '51'], ['units' => 'three'], ['units' => '1.234'], ['training_phase_id' => 999999]] as $invalid) {
            $this->post("/classes/{$this->class->id}/subjects", ['subject_id' => $this->subject->id, ...$invalid])
                ->assertSessionHasErrors(array_keys($invalid));
        }
        $this->assertSame(0, ClassSubject::query()->count());
    }

    public function test_the_phase_and_units_of_a_subject_are_changed_with_an_audit_trail(): void
    {
        $offering = $this->app->make(ClassBatchService::class)->addSubject($this->class, $this->subject);

        $this->actingAs($this->admin)->put("/classes/{$this->class->id}/subjects/{$offering->id}", ['training_phase_id' => $this->phase(1)->id, 'units' => '2.5'])
            ->assertRedirect("/classes/{$this->class->id}")->assertSessionHasNoErrors();

        $offering->refresh();
        $this->assertSame($this->phase(1)->id, $offering->training_phase_id);
        $this->assertSame('2.50', $offering->units);
        $entry = AuditLog::query()->where('action', AuditAction::ClassSubjectUpdated->value)->sole();
        $this->assertSame(['phase' => null, 'units' => '1'], $entry->old_values);
        $this->assertSame(['class' => 'Class A', 'subject' => 'Subject 1', 'phase' => 'Phase 1', 'units' => '2.5'], $entry->new_values);

        // Saving the same values records nothing new; units are required here.
        $this->put("/classes/{$this->class->id}/subjects/{$offering->id}", ['training_phase_id' => $this->phase(1)->id, 'units' => '2.50'])->assertSessionHasNoErrors();
        $this->assertSame(1, AuditLog::query()->where('action', AuditAction::ClassSubjectUpdated->value)->count());
        $this->put("/classes/{$this->class->id}/subjects/{$offering->id}", ['training_phase_id' => '', 'units' => ''])->assertSessionHasErrors('units');
    }

    public function test_a_subject_is_changed_only_through_its_own_class_and_only_by_class_managers(): void
    {
        $offering = $this->app->make(ClassBatchService::class)->addSubject($this->class, $this->subject);
        $otherClass = ClassBatch::factory()->for(AcademicPeriod::factory())->create(['name' => 'Class B']);

        $this->actingAs($this->admin)->put("/classes/{$otherClass->id}/subjects/{$offering->id}", ['units' => '2'])->assertNotFound();
        $this->actingAs($this->userWithRole(SystemRole::Instructor))->put("/classes/{$this->class->id}/subjects/{$offering->id}", ['units' => '2'])->assertForbidden();
        $this->assertSame('1.00', $offering->fresh()->units);
    }

    public function test_the_class_page_lists_subjects_in_phase_order_with_their_units(): void
    {
        $classes = $this->app->make(ClassBatchService::class);
        $classes->addSubject($this->class, $this->subject, $this->phase(2), '3');
        $classes->addSubject($this->class, Subject::factory()->create(['code' => 'SUBJ-0', 'name' => 'A Subject Without Phase']));
        $classes->addSubject($this->class, Subject::factory()->create(['code' => 'SUBJ-9', 'name' => 'Zulu Subject']), $this->phase(1), '1.5');

        $this->actingAs($this->admin)->get("/classes/{$this->class->id}")->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('phases', 3)
                ->where('offerings.0.subject.name', 'Zulu Subject')->where('offerings.0.phase.name', 'Phase 1')->where('offerings.0.units', '1.5')
                ->where('offerings.1.subject.name', 'Subject 1')->where('offerings.1.units', '3')
                ->where('offerings.2.subject.name', 'A Subject Without Phase')->where('offerings.2.phase', null));
    }
}
