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
use App\Services\TrainingPhaseService;
use Illuminate\Database\QueryException;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Training phases and the phase and units of each subject of a class (owner
 * request, 2026-10-03). The whole course lasts one year: an academic period
 * lasts at most a year, and each phase belongs to one period with dates
 * inside it, in phase order, without overlapping.
 *
 * The active year runs Aug 3 – Dec 18, 2026 with Phase 1 (Aug 3 – Sep 17),
 * Phase 2 (Sep 18 – Nov 2) and Phase 3 (Nov 3 – Dec 18).
 */
class TrainingPhaseTest extends TestCase
{
    private User $admin;

    private AcademicPeriod $year;

    private ClassBatch $class;

    private Subject $subject;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->userWithRole(SystemRole::AcademicAdministrator);
        $this->year = AcademicPeriod::factory()->active()->create(['name' => '2026-2027', 'starts_on' => '2026-08-03', 'ends_on' => '2026-12-18']);
        $this->class = ClassBatch::factory()->for($this->year)->create(['name' => 'Class A']);
        $this->subject = Subject::factory()->create(['code' => 'SUBJ-1', 'name' => 'Subject 1']);

        $phases = $this->app->make(TrainingPhaseService::class);
        foreach ([[1, '2026-08-03', '2026-09-17'], [2, '2026-09-18', '2026-11-02'], [3, '2026-11-03', '2026-12-18']] as [$number, $from, $to]) {
            $phases->create($this->year, ['number' => $number, 'name' => "Phase {$number}", 'starts_on' => $from, 'ends_on' => $to]);
        }
    }

    private function phase(int $number, ?AcademicPeriod $year = null): TrainingPhase
    {
        return TrainingPhase::query()->where('academic_period_id', ($year ?? $this->year)->id)->where('number', $number)->sole();
    }

    /** A second year, Jan 4 – May 28, 2027, without phases. */
    private function otherYear(): AcademicPeriod
    {
        return AcademicPeriod::factory()->create(['name' => '2027', 'starts_on' => '2027-01-04', 'ends_on' => '2027-05-28']);
    }

    // Phases of a year ---------------------------------------------------------

    public function test_the_phases_of_the_active_year_are_listed_in_order_with_their_dates(): void
    {
        $this->actingAs($this->admin)->get('/training-phases')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('staff/training-phases/index')
                ->where('period.name', '2026-2027')->where('period.startsOn', '2026-08-03')->where('period.endsOn', '2026-12-18')
                ->where('phases.0.name', 'Phase 1')->where('phases.0.startsOn', '2026-08-03')->where('phases.0.endsOn', '2026-09-17')
                ->where('phases.2.name', 'Phase 3')->where('phases.2.endsOn', '2026-12-18')
                ->where('phases.0.subjectCount', 0)
                // The phases fill the year: no room for another.
                ->where('next', null));

        $this->delete("/training-phases/{$this->phase(3)->id}");
        $this->get('/training-phases')->assertInertia(fn (Assert $page) => $page
            ->where('next', ['number' => 3, 'startsOn' => '2026-11-03', 'endsOn' => '2026-12-18']));
    }

    public function test_each_year_has_its_own_phases(): void
    {
        $other = $this->otherYear();

        // The same number and name in another year.
        $this->actingAs($this->admin)->post('/training-phases', ['academic_period_id' => $other->id, 'number' => '1', 'name' => 'Phase 1', 'starts_on' => '2027-01-04', 'ends_on' => '2027-02-26'])
            ->assertRedirect("/training-phases?period={$other->id}")->assertSessionHasNoErrors();

        $this->get("/training-phases?period={$other->id}")->assertInertia(fn (Assert $page) => $page
            ->where('period.name', '2027')->has('phases', 1)->where('phases.0.startsOn', '2027-01-04')
            ->where('next', ['number' => 2, 'startsOn' => '2027-02-27', 'endsOn' => '2027-05-28']));
        // An unknown year shows the active one.
        $this->get('/training-phases?period=999999')->assertInertia(fn (Assert $page) => $page->where('period.name', '2026-2027'));
    }

    public function test_phases_are_added_renamed_and_moved_with_an_audit_trail(): void
    {
        $this->actingAs($this->admin)->put("/training-phases/{$this->phase(3)->id}", ['number' => '3', 'name' => 'Phase 3', 'starts_on' => '2026-11-03', 'ends_on' => '2026-11-30'])
            ->assertSessionHasNoErrors();
        $this->post('/training-phases', ['academic_period_id' => $this->year->id, 'number' => '4', 'name' => '  Field Training  ', 'starts_on' => '2026-12-01', 'ends_on' => '2026-12-18'])
            ->assertRedirect("/training-phases?period={$this->year->id}")->assertSessionHasNoErrors();

        $field = TrainingPhase::query()->where('name', 'Field Training')->sole();
        $this->assertSame([4, '2026-12-01', '2026-12-18', $this->year->id], [$field->number, $field->starts_on->toDateString(), $field->ends_on->toDateString(), $field->academic_period_id]);

        $this->put("/training-phases/{$this->phase(1)->id}", ['number' => '1', 'name' => 'Phase I - Indoctrination', 'starts_on' => '2026-08-03', 'ends_on' => '2026-09-17'])->assertSessionHasNoErrors();
        $this->assertSame('Phase I - Indoctrination', $this->phase(1)->name);

        $created = AuditLog::query()->where('action', AuditAction::TrainingPhaseCreated->value)->latest('id')->first();
        $this->assertSame(['academic_period_id' => $this->year->id, 'number' => 4, 'name' => 'Field Training', 'starts_on' => '2026-12-01', 'ends_on' => '2026-12-18'], $created->new_values);
        $moved = AuditLog::query()->where('action', AuditAction::TrainingPhaseUpdated->value)->orderBy('id')->first();
        $this->assertSame(['ends_on' => '2026-12-18'], $moved->old_values);
        $this->assertSame(['ends_on' => '2026-11-30'], $moved->new_values);
    }

    public function test_phase_dates_stay_inside_the_year_in_order_without_overlapping(): void
    {
        $this->actingAs($this->admin);
        $phase2 = fn (array $dates) => $this->put("/training-phases/{$this->phase(2)->id}", ['number' => '2', 'name' => 'Phase 2', ...$dates]);

        $this->post('/training-phases', ['academic_period_id' => $this->otherYear()->id, 'number' => '1', 'name' => 'Phase 1', 'starts_on' => '2026-12-28', 'ends_on' => '2027-01-31'])
            ->assertSessionHasErrors(['starts_on' => 'The phase must start within 2027, on or after Jan 4, 2027.']);
        $phase2(['starts_on' => '2026-09-18', 'ends_on' => '2026-12-31'])->assertSessionHasErrors('ends_on');
        $phase2(['starts_on' => '2026-09-10', 'ends_on' => '2026-11-02'])
            ->assertSessionHasErrors(['starts_on' => 'The phase must start after Phase 1 ends on Sep 17, 2026.']);
        $phase2(['starts_on' => '2026-09-18', 'ends_on' => '2026-11-05'])
            ->assertSessionHasErrors(['ends_on' => 'The phase must end before Phase 3 starts on Nov 3, 2026.']);
        $phase2(['starts_on' => '2026-10-01', 'ends_on' => '2026-09-30'])->assertSessionHasErrors(['ends_on' => 'The phase must end on or after its start date.']);
        $phase2(['starts_on' => '', 'ends_on' => 'soon'])->assertSessionHasErrors(['starts_on', 'ends_on']);

        $this->assertSame(['2026-09-18', '2026-11-02'], [$this->phase(2)->starts_on->toDateString(), $this->phase(2)->ends_on->toDateString()]);
    }

    public function test_numbers_and_names_are_unique_within_the_year_and_the_year_never_changes(): void
    {
        $this->actingAs($this->admin);
        $this->post('/training-phases', ['academic_period_id' => $this->year->id, 'number' => '2', 'name' => 'Phase 2', 'starts_on' => '2026-12-18', 'ends_on' => '2026-12-18'])
            ->assertSessionHasErrors(['number' => 'Another phase of this year already has this number.', 'name' => 'Another phase of this year already uses this name.']);
        $this->post('/training-phases', ['number' => '4', 'name' => 'Phase 4', 'starts_on' => '2026-12-18', 'ends_on' => '2026-12-18'])->assertSessionHasErrors('academic_period_id');
        $this->post('/training-phases', ['academic_period_id' => $this->year->id, 'number' => '21', 'name' => 'Phase 21', 'starts_on' => '2026-12-18', 'ends_on' => '2026-12-18'])->assertSessionHasErrors('number');

        $other = $this->otherYear();
        $this->put("/training-phases/{$this->phase(1)->id}", ['academic_period_id' => $other->id, 'number' => '1', 'name' => 'Phase 1', 'starts_on' => '2027-01-04', 'ends_on' => '2027-01-31'])
            ->assertSessionHasErrors(['academic_period_id' => 'A phase stays in the academic year it was added to.']);

        $this->assertSame(3, TrainingPhase::query()->count());
        $this->assertSame($this->year->id, $this->phase(1)->academic_period_id);
    }

    public function test_the_service_checks_the_dates_again_when_saving(): void
    {
        $this->expectExceptionMessage('The phase must start after Phase 3 ends on Dec 18, 2026.');

        $this->app->make(TrainingPhaseService::class)->create($this->year, ['number' => 4, 'name' => 'Phase 4', 'starts_on' => '2026-12-18', 'ends_on' => '2026-12-18']);
    }

    public function test_only_a_phase_without_subjects_can_be_deleted(): void
    {
        $this->app->make(ClassBatchService::class)->addSubject($this->class, $this->subject, $this->phase(1), '3');

        $this->actingAs($this->admin)->delete("/training-phases/{$this->phase(1)->id}")->assertRedirect("/training-phases?period={$this->year->id}")
            ->assertSessionHas('inertia.flash_data.toast', ['type' => 'error', 'message' => 'Phase 1 is used by 1 subject of a class and cannot be deleted. Move them to another phase first.']);
        $this->assertSame(3, TrainingPhase::query()->count());

        $unused = $this->phase(3);
        $this->delete("/training-phases/{$unused->id}")->assertRedirect("/training-phases?period={$this->year->id}");
        $this->assertNull(TrainingPhase::query()->find($unused->id));
        $this->assertSame(['academic_period_id' => $this->year->id, 'number' => 3, 'name' => 'Phase 3', 'starts_on' => '2026-11-03', 'ends_on' => '2026-12-18'],
            AuditLog::query()->where('action', AuditAction::TrainingPhaseDeleted->value)->sole()->old_values);
    }

    public function test_instructors_and_candidates_cannot_manage_phases(): void
    {
        $instructor = $this->userWithRole(SystemRole::Instructor);
        $candidate = Candidate::factory()->create(['class_batch_id' => $this->class->id]);
        $data = ['academic_period_id' => $this->year->id, 'number' => '4', 'name' => 'Phase 4', 'starts_on' => '2026-12-18', 'ends_on' => '2026-12-18'];

        foreach ([$instructor, $candidate->user] as $user) {
            $this->actingAs($user)->get('/training-phases')->assertForbidden();
            $this->actingAs($user)->post('/training-phases', $data)->assertForbidden();
            $this->actingAs($user)->put("/training-phases/{$this->phase(1)->id}", ['number' => '1', 'name' => 'Renamed', 'starts_on' => '2026-08-03', 'ends_on' => '2026-09-17'])->assertForbidden();
            $this->actingAs($user)->delete("/training-phases/{$this->phase(3)->id}")->assertForbidden();
        }
        $this->assertSame(['Phase 1', 'Phase 2', 'Phase 3'], TrainingPhase::query()->ordered()->pluck('name')->all());
    }

    // Academic years ------------------------------------------------------------

    public function test_an_academic_year_lasts_at_most_one_year(): void
    {
        $this->actingAs($this->admin);
        $this->post('/academic-periods', ['name' => '2027-2028', 'starts_on' => '2027-08-02', 'ends_on' => '2028-08-02'])
            ->assertSessionHasErrors(['ends_on' => 'The course lasts one year: end the academic year on or before Aug 1, 2028.']);
        $this->post('/academic-periods', ['name' => '2027-2028', 'starts_on' => '2027-08-02', 'ends_on' => '2028-08-01'])->assertSessionHasNoErrors();
        $this->assertSame(['2027-08-02', '2028-08-01'], [
            AcademicPeriod::query()->where('name', '2027-2028')->sole()->starts_on->toDateString(),
            AcademicPeriod::query()->where('name', '2027-2028')->sole()->ends_on->toDateString(),
        ]);

        // The database refuses a longer year too.
        $this->expectException(QueryException::class);
        AcademicPeriod::factory()->create(['starts_on' => '2028-08-01', 'ends_on' => '2029-08-01']);
    }

    public function test_a_year_cannot_be_shortened_past_its_phases(): void
    {
        $this->actingAs($this->admin)->put("/academic-periods/{$this->year->id}", ['name' => '2026-2027', 'starts_on' => '2026-08-10', 'ends_on' => '2026-12-01'])
            ->assertSessionHasErrors([
                'starts_on' => 'Phase 1 starts on Aug 3, 2026. Start the year on or before that day, or change the phase first.',
                'ends_on' => 'Phase 3 ends on Dec 18, 2026. End the year on or after that day, or change the phase first.',
            ]);
        $this->put("/academic-periods/{$this->year->id}", ['name' => '2026-2027', 'starts_on' => '2026-08-03', 'ends_on' => '2027-07-30'])->assertSessionHasNoErrors();
        $this->assertSame('2027-07-30', $this->year->fresh()->ends_on->toDateString());
    }

    public function test_the_year_page_lists_its_phases(): void
    {
        $this->actingAs($this->admin)->get("/academic-periods/{$this->year->id}")->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('phases', 3)->where('phases.1.name', 'Phase 2')->where('phases.1.startsOn', '2026-09-18'));
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

    public function test_phase_and_units_are_validated_and_the_phase_must_be_of_the_class_year(): void
    {
        $other = $this->otherYear();
        $otherPhase = $this->app->make(TrainingPhaseService::class)->create($other, ['number' => 1, 'name' => 'Phase 1', 'starts_on' => '2027-01-04', 'ends_on' => '2027-02-26']);

        $this->actingAs($this->admin);
        foreach ([['units' => '0'], ['units' => '51'], ['units' => 'three'], ['units' => '1.234'], ['training_phase_id' => 999999]] as $invalid) {
            $this->post("/classes/{$this->class->id}/subjects", ['subject_id' => $this->subject->id, ...$invalid])
                ->assertSessionHasErrors(array_keys($invalid));
        }
        $this->post("/classes/{$this->class->id}/subjects", ['subject_id' => $this->subject->id, 'training_phase_id' => $otherPhase->id])
            ->assertSessionHasErrors(['training_phase_id' => 'Select a training phase of the academic year of this class.']);
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

    public function test_the_class_page_lists_subjects_in_phase_order_and_only_the_phases_of_its_year(): void
    {
        $this->app->make(TrainingPhaseService::class)->create($this->otherYear(), ['number' => 1, 'name' => 'Other Year Phase', 'starts_on' => '2027-01-04', 'ends_on' => '2027-01-31']);
        $classes = $this->app->make(ClassBatchService::class);
        $classes->addSubject($this->class, $this->subject, $this->phase(2), '3');
        $classes->addSubject($this->class, Subject::factory()->create(['code' => 'SUBJ-0', 'name' => 'A Subject Without Phase']));
        $classes->addSubject($this->class, Subject::factory()->create(['code' => 'SUBJ-9', 'name' => 'Zulu Subject']), $this->phase(1), '1.5');

        $this->actingAs($this->admin)->get("/classes/{$this->class->id}")->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('phases', 3)->where('phases.0.name', 'Phase 1')->where('phases.0.endsOn', '2026-09-17')
                ->where('offerings.0.subject.name', 'Zulu Subject')->where('offerings.0.phase.name', 'Phase 1')->where('offerings.0.units', '1.5')
                ->where('offerings.1.subject.name', 'Subject 1')->where('offerings.1.units', '3')
                ->where('offerings.2.subject.name', 'A Subject Without Phase')->where('offerings.2.phase', null));
    }
}
