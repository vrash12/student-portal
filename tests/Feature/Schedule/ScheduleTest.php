<?php

namespace Tests\Feature\Schedule;

use App\Enums\AuditAction;
use App\Enums\CampusCode;
use App\Enums\SystemRole;
use App\Models\AcademicPeriod;
use App\Models\AuditLog;
use App\Models\Campus;
use App\Models\Candidate;
use App\Models\ClassBatch;
use App\Models\ClassSubject;
use App\Models\Examination;
use App\Models\ScheduleEntry;
use App\Models\Subject;
use App\Models\User;
use App\Services\Attendance\AttendanceService;
use App\Services\ClassBatchService;
use App\Services\InstructorAssignmentService;
use Carbon\CarbonImmutable;
use Database\Factories\CampusFactory;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Training schedule (owner request, 2026-10-06): weekly and one-time
 * sessions per class, the class week and an instructor's own week with
 * examinations, fitness tests and attendance, the candidate's week and
 * today/tomorrow on the portal, and who may see and change what.
 */
class ScheduleTest extends TestCase
{
    private Campus $south;

    private Campus $north;

    private User $admin;

    private User $alpha;

    private User $bravo;

    private ClassBatch $classA;

    private ClassBatch $classB;

    private ClassSubject $offeringA;

    private ClassSubject $offeringB;

    private Candidate $inA;

    private Candidate $inB;

    protected function setUp(): void
    {
        parent::setUp();

        // Wednesday 2026-10-07, 10:00 in the institution's timezone (UTC+8).
        $this->travelTo('2026-10-07 02:00:00');
        config(['institution.timezone' => 'Asia/Manila']);

        $this->south = CampusFactory::fixed(CampusCode::South);
        $this->north = CampusFactory::fixed(CampusCode::North);
        $this->admin = $this->userWithRole(SystemRole::AcademicAdministrator);

        $period = AcademicPeriod::factory()->active()->create(['name' => '2026-2027', 'starts_on' => '2026-08-03', 'ends_on' => '2027-07-30']);
        $this->classA = ClassBatch::factory()->for($period)->onCampus($this->south)->create(['name' => 'Class A']);
        $this->classB = ClassBatch::factory()->for($period)->onCampus($this->south)->create(['name' => 'Class B']);
        $this->inA = Candidate::factory()->create(['class_batch_id' => $this->classA->id]);
        $this->inB = Candidate::factory()->create(['class_batch_id' => $this->classB->id]);

        $subject = Subject::factory()->create(['code' => 'SUBJ-1', 'name' => 'Subject 1']);
        $this->alpha = User::factory()->withRole(SystemRole::Instructor)->onCampus($this->south)->create(['name' => 'Instructor Alpha']);
        $this->bravo = User::factory()->withRole(SystemRole::Instructor)->onCampus($this->south)->create(['name' => 'Instructor Bravo']);
        $classes = $this->app->make(ClassBatchService::class);
        $assignments = $this->app->make(InstructorAssignmentService::class);
        $this->offeringA = $classes->addSubject($this->classA, $subject);
        $this->offeringB = $classes->addSubject($this->classB, $subject);
        $assignments->assign($this->offeringA, $this->alpha);
        $assignments->assign($this->offeringB, $this->bravo);
    }

    public function test_a_weekly_session_is_added_audited_and_repeats_until_its_last_date(): void
    {
        $this->actingAs($this->admin)->post('/schedule/entries', $this->entry([
            'class_subject_id' => $this->offeringA->id,
            'instructor_id' => $this->alpha->id,
            'title' => '  Subject 1 lecture ',
            'location' => 'Room 2',
            'starts_on' => '2026-10-05',
            'repeats_weekly' => true,
            'ends_on' => '2026-10-19',
        ]))->assertRedirect()->assertSessionHasNoErrors();

        $entry = ScheduleEntry::query()->sole();
        $this->assertSame('Subject 1 lecture', $entry->title);
        $this->assertSame($this->south->id, $entry->campus_id);
        $this->assertSame('08:00', $entry->startLabel());

        $audit = AuditLog::query()->where('action', AuditAction::ScheduleEntryCreated->value)->sole();
        $this->assertSame('schedule_entry', $audit->auditable_type);
        $this->assertSame($this->south->id, $audit->campus_id);
        $this->assertSame(['class' => 'Class A', 'title' => 'Subject 1 lecture', 'subject' => 'Subject 1', 'instructor' => 'Instructor Alpha', 'location' => 'Room 2',
            'starts_on' => '2026-10-05', 'repeats_weekly' => true, 'ends_on' => '2026-10-19', 'start_time' => '08:00', 'end_time' => '09:30', 'notes' => null], $audit->new_values);

        // Mondays 5, 12 and 19 October only.
        $this->assertSame(['2026-10-05' => ['Subject 1 lecture']], $this->classWeek('2026-10-07'));
        $this->assertSame(['2026-10-12' => ['Subject 1 lecture']], $this->classWeek('2026-10-14'));
        $this->assertSame(['2026-10-19' => ['Subject 1 lecture']], $this->classWeek('2026-10-21'));
        $this->assertSame([], $this->classWeek('2026-10-28'));
        $this->assertSame([], $this->classWeek('2026-09-30'));
    }

    public function test_the_class_week_brings_examinations_fitness_tests_and_attendance_together(): void
    {
        $this->addEntry(['title' => 'Formation', 'starts_on' => '2026-10-08', 'start_time' => '06:00', 'end_time' => '06:30']);
        $this->exam($this->offeringA, 'Quiz 1', '2026-10-09 01:00:00', '2026-10-09 03:00:00');
        $this->exam($this->offeringA, 'Draft quiz', '2026-10-09 01:00:00', null, 'draft');
        $this->exam($this->offeringB, 'Class B quiz', '2026-10-09 01:00:00', null);
        app(AttendanceService::class)->create($this->classA, ['held_on' => '2026-10-06', 'title' => 'Field day', 'hours' => '2', 'notes' => null], $this->admin);

        $this->actingAs($this->admin)->get('/schedule?view=class&class='.$this->classA->id.'&week=2026-10-07')->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('staff/schedule/index')
                ->where('week.start', '2026-10-05')
                ->where('week.end', '2026-10-11')
                ->has('days', 7)
                ->where('days.1.items.0.kind', 'attendance')
                ->where('days.3.items.0.title', 'Formation')
                ->where('days.3.items.0.start', '06:00')
                // Opens 09:00 and closes 11:00 local time.
                ->where('days.4.items.0.kind', 'examination')
                ->where('days.4.items.0.title', 'Quiz 1')
                ->where('days.4.items.0.start', '09:00')
                ->where('days.4.items.0.end', '11:00')
                ->has('days.4.items', 1)
                ->where('can.create', true));
    }

    public function test_entries_are_validated(): void
    {
        $this->actingAs($this->admin)->post('/schedule/entries', $this->entry(['end_time' => '07:00']))
            ->assertSessionHasErrors(['end_time' => 'The session must end after it starts.']);
        $this->actingAs($this->admin)->post('/schedule/entries', $this->entry(['repeats_weekly' => true, 'ends_on' => '']))
            ->assertSessionHasErrors(['ends_on' => 'Enter the last date of the weekly session.']);
        $this->actingAs($this->admin)->post('/schedule/entries', $this->entry(['repeats_weekly' => true, 'ends_on' => '2026-10-01']))
            ->assertSessionHasErrors('ends_on');
        $this->actingAs($this->admin)->post('/schedule/entries', $this->entry(['repeats_weekly' => true, 'ends_on' => '2027-09-01']))
            ->assertSessionHasErrors(['ends_on' => 'The schedule of this class ends with its academic year (2026-2027).']);
        $this->actingAs($this->admin)->post('/schedule/entries', $this->entry(['starts_on' => '2026-07-01']))
            ->assertSessionHasErrors(['starts_on' => "Choose a date in the class's academic year (2026-2027)."]);
        $this->actingAs($this->admin)->post('/schedule/entries', $this->entry(['class_subject_id' => $this->offeringB->id]))
            ->assertSessionHasErrors(['class_subject_id' => 'Choose one of the subjects of this class.']);
        $northInstructor = User::factory()->withRole(SystemRole::Instructor)->onCampus($this->north)->create();
        $this->actingAs($this->admin)->post('/schedule/entries', $this->entry(['instructor_id' => $northInstructor->id]))
            ->assertSessionHasErrors('instructor_id');
        $this->actingAs($this->admin)->post('/schedule/entries', $this->entry(['instructor_id' => $this->admin->id]))
            ->assertSessionHasErrors('instructor_id');
        $this->actingAs($this->admin)->post('/schedule/entries', $this->entry(['title' => ' ']))->assertSessionHasErrors('title');
        $this->actingAs($this->admin)->post('/schedule/entries', $this->entry(['start_time' => '8am']))->assertSessionHasErrors('start_time');

        $this->assertSame(0, ScheduleEntry::query()->count());
    }

    public function test_an_entry_is_changed_and_removed_with_its_class_fixed(): void
    {
        $entry = $this->addEntry(['title' => 'Drill']);

        $this->actingAs($this->admin)->get("/schedule/entries/{$entry->id}/edit")->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('staff/schedule/edit')->has('classOptions', 1)->where('entry.title', 'Drill'));
        $this->actingAs($this->admin)->put("/schedule/entries/{$entry->id}", [...$this->entry(['title' => 'Drill']), 'class_batch_id' => $this->classB->id])
            ->assertSessionHasErrors('class_batch_id');

        $changes = $this->entry(['title' => 'Close-order drill', 'location' => 'Parade ground']);
        unset($changes['class_batch_id']);
        $this->actingAs($this->admin)->put("/schedule/entries/{$entry->id}", $changes)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('Close-order drill', $entry->refresh()->title);
        $this->assertSame($this->classA->id, $entry->class_batch_id);
        $audit = AuditLog::query()->where('action', AuditAction::ScheduleEntryUpdated->value)->sole();
        $this->assertSame(['title' => 'Drill', 'location' => null], $audit->old_values);

        $this->actingAs($this->admin)->delete("/schedule/entries/{$entry->id}")->assertRedirect();
        $this->assertSame(0, ScheduleEntry::query()->count());
        $this->assertSame('Close-order drill', AuditLog::query()->where('action', AuditAction::ScheduleEntryDeleted->value)->sole()->old_values['title']);
    }

    public function test_instructors_see_their_own_week_and_taught_classes_but_do_not_change_schedules(): void
    {
        $this->addEntry(['title' => 'Alpha leads', 'instructor_id' => $this->alpha->id]);
        $this->addEntry(['title' => 'In my subject', 'class_subject_id' => $this->offeringA->id]);
        $this->addEntry(['title' => 'Class A formation']);
        $this->addEntry(['title' => 'Class B lecture', 'class_batch_id' => $this->classB->id, 'class_subject_id' => $this->offeringB->id]);
        $this->exam($this->offeringA, 'Quiz 1', '2026-10-08 01:00:00', null);

        $this->actingAs($this->alpha)->get('/schedule?week=2026-10-07')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('view', 'mine')
            ->where('can.teach', true)
            ->where('can.create', false)
            ->where('days.2.items', fn ($items) => collect($items)->pluck('title')->sort()->values()->all() === ['Alpha leads', 'In my subject'])
            ->where('days.3.items.0.title', 'Quiz 1')
            // Alpha teaches Quiz 1's subject: the item links to the examination; schedule entries do not link (no schedule.manage).
            ->where('days.3.items.0.href', '/examinations/'.Examination::query()->where('title', 'Quiz 1')->value('id'))
            ->where('days.2.items.0.href', null));

        // A class Alpha does not teach is never shown: the list falls back to Class A.
        $this->actingAs($this->alpha)->get('/schedule?view=class&class='.$this->classB->id.'&week=2026-10-07')->assertInertia(fn (Assert $page) => $page
            ->where('view', 'class')
            ->where('filters.class', (string) $this->classA->id)
            ->has('classes', 1)
            ->where('days.2.items', fn ($items) => ! collect($items)->pluck('title')->contains('Class B lecture')));

        $entry = ScheduleEntry::query()->where('title', 'Class A formation')->sole();
        $this->actingAs($this->alpha)->get('/schedule/entries/create')->assertForbidden();
        $this->actingAs($this->alpha)->post('/schedule/entries', $this->entry())->assertForbidden();
        $this->actingAs($this->alpha)->get("/schedule/entries/{$entry->id}/edit")->assertForbidden();
        $this->actingAs($this->alpha)->delete("/schedule/entries/{$entry->id}")->assertForbidden();
    }

    public function test_campus_administrators_cannot_reach_another_campus_schedule(): void
    {
        $entry = $this->addEntry(['title' => 'South drill']);
        $northAdmin = User::factory()->withRole(SystemRole::AcademicAdministrator)->onCampus($this->north)->create();

        $this->actingAs($northAdmin)->get("/schedule/entries/{$entry->id}/edit")->assertNotFound();
        $this->actingAs($northAdmin)->delete("/schedule/entries/{$entry->id}")->assertNotFound();
        $this->actingAs($northAdmin)->post('/schedule/entries', $this->entry())->assertForbidden();
        $this->assertSame(1, ScheduleEntry::query()->count());
    }

    public function test_candidates_see_only_their_class_week_and_today_and_tomorrow_on_home(): void
    {
        $this->addEntry(['title' => 'Lecture today', 'starts_on' => '2026-10-07', 'notes' => 'Bring your notebook.']);
        $this->addEntry(['title' => 'Lecture tomorrow', 'starts_on' => '2026-10-08', 'instructor_id' => $this->alpha->id, 'location' => 'Room 2']);
        $this->addEntry(['title' => 'Next week', 'starts_on' => '2026-10-14']);
        $this->addEntry(['title' => 'Other class', 'class_batch_id' => $this->classB->id, 'starts_on' => '2026-10-07']);
        $exam = $this->exam($this->offeringA, 'Quiz 1', '2026-10-09 01:00:00', null);
        $this->exam($this->offeringA, 'Draft quiz', '2026-10-09 01:00:00', null, 'draft');
        app(AttendanceService::class)->create($this->classA, ['held_on' => '2026-10-06', 'title' => 'Field day', 'hours' => '2', 'notes' => null], $this->admin);

        $this->actingAs($this->inA->user)->get('/portal')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->has('schedule', 2)
            ->where('schedule.0.date', '2026-10-07')
            ->where('schedule.0.items.0.title', 'Lecture today')
            ->where('schedule.0.items.0.notes', 'Bring your notebook.')
            ->where('schedule.1.items.0.title', 'Lecture tomorrow')
            ->where('schedule.1.items.0.instructor', 'Instructor Alpha')
            ->where('schedule.1.items.0.location', 'Room 2')
            ->missing('schedule.0.items.0.id'));

        $this->actingAs($this->inA->user)->get('/portal/schedule')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('portal/schedule')
            ->where('className', 'Class A')
            // No attendance sessions on the portal.
            ->has('days.1.items', 0)
            ->where('days.4.items.0.title', 'Quiz 1')
            ->where('days.4.items.0.href', "/portal/examinations/{$exam->id}")
            ->has('days.4.items', 1));

        $this->actingAs($this->inB->user)->get('/portal/schedule?week=2026-10-07')->assertInertia(fn (Assert $page) => $page
            ->where('days.2.items.0.title', 'Other class')
            ->has('days.4.items', 0));

        // Staff pages stay closed to candidates.
        $this->actingAs($this->inA->user)->get('/schedule')->assertForbidden();
    }

    public function test_weekly_occurrences_follow_the_first_dates_weekday(): void
    {
        $entry = new ScheduleEntry(['starts_on' => '2026-10-07', 'repeats_weekly' => true, 'ends_on' => '2026-11-04']);

        $dates = fn (string $from, string $to): array => array_map(
            fn (CarbonImmutable $date): string => $date->toDateString(),
            $entry->occurrencesBetween(CarbonImmutable::parse($from), CarbonImmutable::parse($to)),
        );

        $this->assertSame(['2026-10-07'], $dates('2026-10-05', '2026-10-11'));
        $this->assertSame(['2026-10-14', '2026-10-21'], $dates('2026-10-12', '2026-10-25'));
        $this->assertSame(['2026-11-04'], $dates('2026-11-02', '2026-11-08'));
        $this->assertSame([], $dates('2026-11-09', '2026-11-15'));
        $this->assertSame([], $dates('2026-09-28', '2026-10-04'));

        $once = new ScheduleEntry(['starts_on' => '2026-10-07', 'repeats_weekly' => false]);
        $this->assertCount(1, $once->occurrencesBetween(CarbonImmutable::parse('2026-10-05'), CarbonImmutable::parse('2026-10-11')));
        $this->assertCount(0, $once->occurrencesBetween(CarbonImmutable::parse('2026-10-12'), CarbonImmutable::parse('2026-10-18')));
    }

    public function test_the_database_requires_a_last_date_for_weekly_entries(): void
    {
        $this->expectException(QueryException::class);

        DB::table('schedule_entries')->insert([
            'class_batch_id' => $this->classA->id, 'campus_id' => $this->south->id, 'title' => 'Broken', 'starts_on' => '2026-10-07',
            'repeats_weekly' => true, 'ends_on' => null, 'start_time' => '08:00', 'end_time' => '09:00',
            'created_by' => $this->admin->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function entry(array $overrides = []): array
    {
        return [
            'class_batch_id' => $this->classA->id,
            'class_subject_id' => '',
            'instructor_id' => '',
            'title' => 'Physical training',
            'location' => '',
            'starts_on' => '2026-10-07',
            'repeats_weekly' => false,
            'ends_on' => '',
            'start_time' => '08:00',
            'end_time' => '09:30',
            'notes' => '',
            ...$overrides,
        ];
    }

    /** @param  array<string, mixed>  $overrides */
    private function addEntry(array $overrides = []): ScheduleEntry
    {
        $this->actingAs($this->admin)->post('/schedule/entries', $this->entry($overrides))->assertSessionHasNoErrors();

        return ScheduleEntry::query()->latest('id')->firstOrFail();
    }

    private function exam(ClassSubject $offering, string $title, string $opensAtUtc, ?string $closesAtUtc, string $status = 'published'): Examination
    {
        $exam = new Examination;
        $exam->class_subject_id = $offering->id;
        $exam->created_by = $this->admin->id;
        $exam->title = $title;
        $exam->status = $status;
        $exam->duration_minutes = 30;
        $exam->opens_at = $opensAtUtc;
        $exam->closes_at = $closesAtUtc;
        $exam->save();

        return $exam;
    }

    /**
     * Titles by date of Class A's week containing $date (days with items only).
     *
     * @return array<string, list<string>>
     */
    private function classWeek(string $date): array
    {
        $days = [];
        $this->actingAs($this->admin)->get('/schedule?view=class&class='.$this->classA->id.'&week='.$date)->assertOk()
            ->assertInertia(function (Assert $page) use (&$days): void {
                $days = $page->toArray()['props']['days'];
            });

        return collect($days)->filter(fn (array $day): bool => $day['items'] !== [])
            ->mapWithKeys(fn (array $day): array => [$day['date'] => array_column($day['items'], 'title')])
            ->all();
    }
}
