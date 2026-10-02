<?php

namespace Tests\Feature\Attendance;

use App\Enums\AuditAction;
use App\Enums\CandidateStatus;
use App\Enums\SystemRole;
use App\Models\AcademicPeriod;
use App\Models\AttendanceRecord;
use App\Models\AttendanceSession;
use App\Models\AuditLog;
use App\Models\Candidate;
use App\Models\ClassBatch;
use App\Models\Subject;
use App\Models\User;
use App\Services\Attendance\AttendanceLedger;
use App\Services\Attendance\AttendanceScope;
use App\Services\ClassBatchService;
use App\Services\InstructorAssignmentService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Attendance (owner request, 2026-10-01): training sessions per class, a
 * roll call with Present / Late / Excused / Absent, audited changes, the
 * rate and hours rule read by performance areas, and the class scope.
 */
class AttendanceTest extends TestCase
{
    private User $admin;

    private User $alpha;

    private User $bravo;

    private ClassBatch $classA;

    private ClassBatch $classB;

    private Candidate $first;

    private Candidate $second;

    private Candidate $third;

    private Candidate $other;

    protected function setUp(): void
    {
        parent::setUp();

        // Session dates may not be in the future; keep "today" fixed.
        $this->travelTo('2026-10-01 10:00:00');

        $this->admin = $this->userWithRole(SystemRole::AcademicAdministrator);
        $period = AcademicPeriod::factory()->active()->create(['name' => 'Period Current']);
        $this->classA = ClassBatch::factory()->for($period)->create(['name' => 'Class A']);
        $this->classB = ClassBatch::factory()->for($period)->create(['name' => 'Class B']);

        $this->first = Candidate::factory()->create(['class_batch_id' => $this->classA->id, 'candidate_number' => 'C-001']);
        $this->second = Candidate::factory()->create(['class_batch_id' => $this->classA->id, 'candidate_number' => 'C-002']);
        $this->third = Candidate::factory()->create(['class_batch_id' => $this->classA->id, 'candidate_number' => 'C-003']);
        $this->other = Candidate::factory()->create(['class_batch_id' => $this->classB->id, 'candidate_number' => 'C-101']);

        // Instructor Alpha teaches Class A; Instructor Bravo teaches Class B.
        $subject = Subject::factory()->create(['code' => 'SUBJ-1', 'name' => 'Subject 1']);
        $this->alpha = $this->userWithRole(SystemRole::Instructor, ['name' => 'Instructor Alpha']);
        $this->bravo = $this->userWithRole(SystemRole::Instructor, ['name' => 'Instructor Bravo']);
        $classes = $this->app->make(ClassBatchService::class);
        $assignments = $this->app->make(InstructorAssignmentService::class);
        $assignments->assign($classes->addSubject($this->classA, $subject), $this->alpha);
        $assignments->assign($classes->addSubject($this->classB, $subject), $this->bravo);
    }

    public function test_a_session_is_created_and_audited(): void
    {
        $this->actingAs($this->admin)->post('/attendance/sessions', [
            'class_batch_id' => $this->classA->id,
            'held_on' => '2026-09-15',
            'title' => '  Morning Formation  ',
            'hours' => '1.5',
            'notes' => '',
        ])->assertSessionHasNoErrors();

        $session = AttendanceSession::query()->sole();
        $this->assertSame('Morning Formation', $session->title);
        $this->assertSame('1.50', $session->hours);
        $this->assertNull($session->notes);
        $this->assertSame($this->admin->id, $session->created_by);

        $audit = AuditLog::query()->where('action', AuditAction::AttendanceSessionCreated->value)->sole();
        $this->assertSame('attendance_session', $audit->auditable_type);
        $this->assertSame([
            'class' => 'Class A', 'held_on' => '2026-09-15', 'title' => 'Morning Formation', 'hours' => '1.50', 'notes' => null,
        ], $audit->new_values);
    }

    public function test_session_details_are_validated(): void
    {
        $valid = ['class_batch_id' => $this->classA->id, 'held_on' => '2026-09-15', 'title' => 'Drill', 'hours' => '2'];

        $this->actingAs($this->admin)->post('/attendance/sessions', [...$valid, 'hours' => '0'])
            ->assertSessionHasErrors(['hours' => 'A session must last more than 0 hours.']);
        $this->actingAs($this->admin)->post('/attendance/sessions', [...$valid, 'hours' => '24.5'])
            ->assertSessionHasErrors(['hours' => 'A session cannot be longer than 24 hours.']);
        $this->actingAs($this->admin)->post('/attendance/sessions', [...$valid, 'hours' => '1.255'])->assertSessionHasErrors('hours');
        $this->actingAs($this->admin)->post('/attendance/sessions', [...$valid, 'hours' => 'two'])->assertSessionHasErrors('hours');
        $this->actingAs($this->admin)->post('/attendance/sessions', [...$valid, 'held_on' => '2026-10-02'])
            ->assertSessionHasErrors(['held_on' => 'The session date cannot be in the future. Create the session on or after the day it is held.']);
        $this->actingAs($this->admin)->post('/attendance/sessions', [...$valid, 'title' => ' '])->assertSessionHasErrors('title');
        $this->actingAs($this->admin)->post('/attendance/sessions', [...$valid, 'class_batch_id' => ''])->assertSessionHasErrors('class_batch_id');
        $this->actingAs($this->admin)->post('/attendance/sessions', [...$valid, 'notes' => str_repeat('x', 501)])->assertSessionHasErrors('notes');

        // Today is allowed.
        $this->actingAs($this->admin)->post('/attendance/sessions', [...$valid, 'held_on' => '2026-10-01', 'hours' => '24'])->assertSessionHasNoErrors();
        $this->assertSame(1, AttendanceSession::query()->count());
    }

    public function test_session_details_can_be_changed_but_not_the_class(): void
    {
        $session = $this->createSession();

        $this->actingAs($this->admin)->put("/attendance/sessions/{$session->id}", [
            'held_on' => '2026-09-15', 'title' => 'Morning Formation', 'hours' => '2', 'class_batch_id' => $this->classB->id,
        ])->assertSessionHasErrors('class_batch_id');

        $this->actingAs($this->admin)->put("/attendance/sessions/{$session->id}", [
            'held_on' => '2026-09-16', 'title' => 'Morning Formation', 'hours' => '2', 'notes' => 'Parade ground',
        ])->assertRedirect("/attendance/sessions/{$session->id}");

        $session->refresh();
        $this->assertSame($this->classA->id, $session->class_batch_id);
        $this->assertSame('2.00', $session->hours);

        $audit = AuditLog::query()->where('action', AuditAction::AttendanceSessionUpdated->value)->sole();
        $this->assertSame(['held_on' => '2026-09-15', 'hours' => '1.50', 'notes' => null], $audit->old_values);
        $this->assertSame(['held_on' => '2026-09-16', 'hours' => '2.00', 'notes' => 'Parade ground'], $audit->new_values);
    }

    public function test_attendance_is_recorded_and_every_change_is_audited_by_candidate_number(): void
    {
        $session = $this->createSession();

        $this->actingAs($this->alpha)->put("/attendance/sessions/{$session->id}/records", ['entries' => [
            $this->first->id => ['status' => 'present', 'remarks' => ''],
            $this->second->id => ['status' => 'late', 'remarks' => ' Arrived 0610 '],
        ]])->assertRedirect("/attendance/sessions/{$session->id}")->assertInertiaFlash('toast.message', 'Attendance of 2 candidates saved.');

        $this->actingAs($this->alpha)->put("/attendance/sessions/{$session->id}/records", ['entries' => [
            $this->first->id => ['status' => 'absent', 'remarks' => null],
            // Unchanged rows are skipped.
            $this->second->id => ['status' => 'late', 'remarks' => 'Arrived 0610'],
        ]])->assertInertiaFlash('toast.message', 'Attendance of 1 candidate saved.');

        $this->actingAs($this->alpha)->put("/attendance/sessions/{$session->id}/records", ['entries' => [
            $this->second->id => ['status' => 'late', 'remarks' => 'Arrived 0610'],
        ]])->assertInertiaFlash('toast.message', 'No attendance changed.');

        $records = AttendanceRecord::query()->orderBy('candidate_id')->get();
        $this->assertCount(2, $records);
        $this->assertSame('absent', $records[0]->status->value);
        $this->assertNull($records[0]->remarks);
        $this->assertSame('Arrived 0610', $records[1]->remarks);
        $this->assertSame($this->alpha->id, $records[0]->recorded_by);

        $entries = AuditLog::query()->where('action', AuditAction::AttendanceRecorded->value)->orderBy('id')->get();
        $this->assertCount(2, $entries);
        $this->assertSame(['C-001' => null, 'C-002' => null], $entries[0]->old_values);
        $this->assertSame([
            'C-001' => ['status' => 'Present', 'remarks' => null],
            'C-002' => ['status' => 'Late', 'remarks' => 'Arrived 0610'],
        ], $entries[0]->new_values);
        $this->assertSame(['C-001' => ['status' => 'Present', 'remarks' => null]], $entries[1]->old_values);
        $this->assertSame(['C-001' => ['status' => 'Absent', 'remarks' => null]], $entries[1]->new_values);
        $this->assertSame($this->alpha->id, $entries[1]->actor_id);
        $this->assertSame($session->id, $entries[1]->auditable_id);
    }

    public function test_withdrawn_and_other_class_candidates_are_refused(): void
    {
        $session = $this->createSession();

        $this->actingAs($this->admin)->put("/attendance/sessions/{$session->id}/records", ['entries' => [
            $this->first->id => ['status' => 'present'],
            $this->other->id => ['status' => 'present'],
        ]])->assertSessionHasErrors(['entries' => 'Attendance can only be recorded for candidates of this class who are not withdrawn.']);

        $this->third->status = CandidateStatus::Withdrawn;
        $this->third->save();
        $this->actingAs($this->admin)->put("/attendance/sessions/{$session->id}/records", ['entries' => [
            $this->third->id => ['status' => 'absent'],
        ]])->assertSessionHasErrors('entries');

        $this->actingAs($this->admin)->put("/attendance/sessions/{$session->id}/records", ['entries' => [
            999999 => ['status' => 'absent'],
        ]])->assertSessionHasErrors('entries');

        // Nothing of a refused save is kept, including the valid rows sent with it.
        $this->assertSame(0, AttendanceRecord::query()->count());
        $this->assertSame(0, AuditLog::query()->where('action', AuditAction::AttendanceRecorded->value)->count());
    }

    public function test_invalid_entries_are_reported_on_their_row(): void
    {
        $session = $this->createSession();

        $this->actingAs($this->admin)->put("/attendance/sessions/{$session->id}/records", ['entries' => [
            $this->first->id => ['status' => 'sick'],
            $this->second->id => ['status' => null, 'remarks' => 'No status chosen'],
            $this->third->id => ['status' => 'present', 'remarks' => str_repeat('x', 256)],
        ]])->assertSessionHasErrors([
            "entries.{$this->first->id}.status" => 'Choose Present, Late, Excused or Absent.',
            "entries.{$this->second->id}.status" => 'Choose Present, Late, Excused or Absent.',
            "entries.{$this->third->id}.remarks" => 'Remarks may be up to 255 characters.',
        ]);

        $this->actingAs($this->admin)->put("/attendance/sessions/{$session->id}/records", ['entries' => ['abc' => ['status' => 'present']]])
            ->assertSessionHasErrors('entries');
        $this->actingAs($this->admin)->put("/attendance/sessions/{$session->id}/records", ['entries' => []])
            ->assertSessionHasErrors('entries');

        $this->assertSame(0, AttendanceRecord::query()->count());
    }

    public function test_the_roll_call_lists_the_class_with_saved_counts(): void
    {
        $session = $this->createSession();
        $this->record($session, [$this->first->id => 'present', $this->second->id => 'excused']);
        // Withdrawn after being recorded: kept on the roll, read-only.
        $this->second->status = CandidateStatus::Withdrawn;
        $this->second->save();

        $this->actingAs($this->alpha)->get("/attendance/sessions/{$session->id}")->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('staff/attendance/sessions/show')
                ->where('session.title', 'Morning Formation')
                ->where('session.hours', 1.5)
                ->where('session.classBatch.name', 'Class A')
                ->has('rows', 3)
                ->where('rows.0.candidate.candidateNumber', 'C-001')
                ->where('rows.0.record.status.value', 'present')
                ->where('rows.0.record.recordedBy', $this->admin->name)
                ->where('rows.1.recordable', false)
                ->where('rows.1.record.status.label', 'Excused')
                ->where('rows.2.record', null)
                ->where('counts', ['present' => 1, 'late' => 0, 'excused' => 1, 'absent' => 0, 'unrecorded' => 1])
                ->has('statusOptions', 4)
                ->where('can.delete', false)
                ->where('can.viewAllCandidates', false));
    }

    public function test_the_session_list_shows_counts_and_the_recorded_share_of_the_roster(): void
    {
        $session = $this->createSession();
        $this->createSession($this->classB, 'Class B Drill');
        $this->record($session, [$this->first->id => 'present', $this->second->id => 'absent']);

        $this->actingAs($this->admin)->get('/attendance')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('staff/attendance/index')
                ->has('sessions.data', 2)
                ->has('classes', 2)
                ->where('scope', 'all')
                ->where('can.create', true));

        $this->actingAs($this->admin)->get("/attendance?class={$this->classA->id}")->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('sessions.data', 1)
                ->where('sessions.data.0.title', 'Morning Formation')
                ->where('sessions.data.0.rosterCount', 3)
                ->where('sessions.data.0.counts', ['present' => 1, 'late' => 0, 'excused' => 0, 'absent' => 1, 'recorded' => 2]));
    }

    public function test_only_sessions_without_attendance_can_be_deleted(): void
    {
        $recorded = $this->createSession();
        $this->record($recorded, [$this->first->id => 'present']);

        $this->actingAs($this->admin)->delete("/attendance/sessions/{$recorded->id}")
            ->assertSessionHasErrors(['session' => 'This session has recorded attendance and cannot be deleted.']);
        $this->assertModelExists($recorded);

        $empty = $this->createSession(title: 'Evening Study');
        $this->actingAs($this->admin)->delete("/attendance/sessions/{$empty->id}")->assertRedirect('/attendance');
        $this->assertModelMissing($empty);

        $audit = AuditLog::query()->where('action', AuditAction::AttendanceSessionDeleted->value)->sole();
        $this->assertSame($empty->id, $audit->auditable_id);
        $this->assertSame('Evening Study', $audit->old_values['title']);
    }

    public function test_instructors_reach_only_the_classes_they_teach(): void
    {
        $sessionA = $this->createSession();
        $sessionB = $this->createSession($this->classB, 'Class B Drill');

        $this->actingAs($this->alpha)->get('/attendance')->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('sessions.data', 1)
                ->where('sessions.data.0.id', $sessionA->id)
                ->has('classes', 1)
                ->where('classes.0.name', 'Class A')
                ->where('scope', 'taught'));

        // Filtering by a class the instructor does not teach shows only their own classes.
        $this->actingAs($this->alpha)->get("/attendance?class={$this->classB->id}")->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('sessions.data', 1)->where('filters.class', ''));

        $this->actingAs($this->alpha)->get('/attendance/sessions/create')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('staff/attendance/sessions/create')
                ->has('classOptions', 1)
                ->has('classOptions.0.classes', 1)
                ->where('classOptions.0.classes.0.id', $this->classA->id)
                ->where('selectedClassId', null));
        $this->actingAs($this->alpha)->get("/attendance/sessions/create?class={$this->classA->id}")->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('selectedClassId', $this->classA->id));
        $this->actingAs($this->alpha)->get("/attendance/sessions/create?class={$this->classB->id}")->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('selectedClassId', null));

        $this->actingAs($this->alpha)->get("/attendance/sessions/{$sessionB->id}")->assertForbidden();
        $this->actingAs($this->alpha)->get("/attendance/sessions/{$sessionB->id}/edit")->assertForbidden();
        $this->actingAs($this->alpha)->put("/attendance/sessions/{$sessionB->id}", ['held_on' => '2026-09-15', 'title' => 'X', 'hours' => '1'])->assertForbidden();
        $this->actingAs($this->alpha)->delete("/attendance/sessions/{$sessionB->id}")->assertForbidden();
        $this->actingAs($this->alpha)->put("/attendance/sessions/{$sessionB->id}/records", ['entries' => [$this->other->id => ['status' => 'present']]])->assertForbidden();
        $this->actingAs($this->alpha)->post('/attendance/sessions', [
            'class_batch_id' => $this->classB->id, 'held_on' => '2026-09-15', 'title' => 'Not mine', 'hours' => '1',
        ])->assertForbidden();

        // Their own class works.
        $this->actingAs($this->alpha)->get("/attendance/sessions/{$sessionA->id}")->assertOk();
        $this->actingAs($this->alpha)->post('/attendance/sessions', [
            'class_batch_id' => $this->classA->id, 'held_on' => '2026-09-16', 'title' => 'Mine', 'hours' => '1',
        ])->assertSessionHasNoErrors()->assertRedirect();

        $this->assertSame(['Morning Formation', 'Class B Drill', 'Mine'], AttendanceSession::query()->orderBy('id')->pluck('title')->all());
        $this->assertSame(0, AttendanceRecord::query()->count());
        $this->assertSame('Class B Drill', $sessionB->fresh()->title);
    }

    public function test_an_instructor_loses_access_when_no_longer_teaching_the_class(): void
    {
        $session = $this->createSession();
        $this->alpha->teachingAssignments()->delete();

        $this->actingAs($this->alpha)->get("/attendance/sessions/{$session->id}")->assertForbidden();
        $this->actingAs($this->alpha)->get('/attendance')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('sessions.data', 0)->has('periods', 0)->where('can.create', false));
    }

    public function test_roles_without_the_attendance_permission_are_forbidden(): void
    {
        $session = $this->createSession();

        foreach ([$this->first->user] as $user) {
            $this->actingAs($user)->get('/attendance')->assertForbidden();
            $this->actingAs($user)->get('/attendance/sessions/create')->assertForbidden();
            $this->actingAs($user)->get("/attendance/sessions/{$session->id}")->assertForbidden();
            $this->actingAs($user)->post('/attendance/sessions', ['class_batch_id' => $this->classA->id])->assertForbidden();
            $this->actingAs($user)->put("/attendance/sessions/{$session->id}/records", ['entries' => [$this->first->id => ['status' => 'present']]])->assertForbidden();
            $this->actingAs($user)->delete("/attendance/sessions/{$session->id}")->assertForbidden();
        }

        $this->assertSame(0, AttendanceRecord::query()->count());
        $this->assertModelExists($session);
    }

    public function test_summaries_follow_the_rate_and_hours_rule(): void
    {
        $sessions = [
            $this->createSession(title: 'Session 1', hours: '1.5', date: '2026-09-01'),
            $this->createSession(title: 'Session 2', hours: '2', date: '2026-09-02'),
            $this->createSession(title: 'Session 3', hours: '1.25', date: '2026-09-03'),
            $this->createSession(title: 'Session 4', hours: '3', date: '2026-09-04'),
        ];
        $this->record($sessions[0], [$this->first->id => 'present', $this->second->id => 'present']);
        $this->record($sessions[1], [$this->first->id => 'late']);
        $this->record($sessions[2], [$this->first->id => 'excused']);
        $this->record($sessions[3], [$this->first->id => 'absent']);
        // Sessions of another class never count.
        $sessionB = $this->createSession($this->classB, 'Class B Drill');
        $this->record($sessionB, [$this->other->id => 'present']);

        $summaries = $this->app->make(AttendanceLedger::class)->summariesFor($this->classA->id, [$this->first->id, $this->second->id, $this->third->id, $this->other->id]);

        $this->assertSame([$this->first->id, $this->second->id, $this->third->id, $this->other->id], array_keys($summaries));
        // (1 present + 1 late) / (1 + 1 + 1 absent) = 66.666… → 66.67; hours 1.5 + 2 attended.
        $this->assertSame(['sessions' => 4, 'present' => 1, 'late' => 1, 'excused' => 1, 'absent' => 1, 'unrecorded' => 0, 'hours' => 3.5, 'rate' => 66.67], $summaries[$this->first->id]);
        $this->assertSame(['sessions' => 4, 'present' => 1, 'late' => 0, 'excused' => 0, 'absent' => 0, 'unrecorded' => 3, 'hours' => 1.5, 'rate' => 100.0], $summaries[$this->second->id]);
        $this->assertSame(['sessions' => 4, 'present' => 0, 'late' => 0, 'excused' => 0, 'absent' => 0, 'unrecorded' => 4, 'hours' => 0.0, 'rate' => null], $summaries[$this->third->id]);
        $this->assertSame(['sessions' => 4, 'present' => 0, 'late' => 0, 'excused' => 0, 'absent' => 0, 'unrecorded' => 4, 'hours' => 0.0, 'rate' => null], $summaries[$this->other->id]);

        $this->assertSame([], $this->app->make(AttendanceLedger::class)->summariesFor($this->classA->id, []));
    }

    public function test_candidate_history_lists_the_sessions_of_the_current_class_newest_first(): void
    {
        $older = $this->createSession(title: 'Older', date: '2026-09-01');
        $newer = $this->createSession(title: 'Newer', hours: '2', date: '2026-09-10');
        $this->createSession($this->classB, 'Class B Drill');
        $this->record($older, [$this->first->id => 'late'], ['remarks' => 'Traffic']);

        $ledger = $this->app->make(AttendanceLedger::class);
        $history = $ledger->candidateHistory($this->first);

        $this->assertCount(2, $history);
        $this->assertSame(['id' => $newer->id, 'title' => 'Newer', 'heldOn' => '2026-09-10', 'hours' => 2.0, 'status' => null, 'remarks' => null], $history[0]);
        $this->assertSame(['value' => 'late', 'label' => 'Late', 'tone' => 'warning'], $history[1]['status']);
        $this->assertSame('Traffic', $history[1]['remarks']);
        $this->assertCount(1, $ledger->candidateHistory($this->first, 1));

        $unassigned = Candidate::factory()->create();
        $this->assertSame([], $ledger->candidateHistory($unassigned));
    }

    public function test_the_scope_allows_candidates_by_their_current_class(): void
    {
        $this->assertTrue(AttendanceScope::for($this->admin)->allowsCandidate($this->other));
        $this->assertTrue(AttendanceScope::for($this->alpha)->allowsCandidate($this->first));
        $this->assertFalse(AttendanceScope::for($this->alpha)->allowsCandidate($this->other));
        $this->assertFalse(AttendanceScope::for($this->first->user)->allowsCandidate($this->first));
        $this->assertFalse(AttendanceScope::for($this->alpha)->allowsCandidate(Candidate::factory()->create()));
    }

    public function test_the_database_rejects_invalid_values(): void
    {
        $session = $this->createSession();

        $attempts = [
            fn () => DB::table('attendance_sessions')->where('id', $session->id)->update(['hours' => 0]),
            fn () => DB::table('attendance_sessions')->where('id', $session->id)->update(['hours' => 24.5]),
            fn () => DB::table('attendance_records')->insert([
                'attendance_session_id' => $session->id, 'candidate_id' => $this->first->id, 'status' => 'sick', 'recorded_by' => $this->admin->id,
            ]),
        ];
        foreach ($attempts as $index => $attempt) {
            try {
                $attempt();
                $this->fail("The database accepted invalid value #{$index}.");
            } catch (QueryException) {
                $this->addToAssertionCount(1);
            }
        }

        // One status per candidate and session.
        $this->record($session, [$this->first->id => 'present']);
        $this->expectException(QueryException::class);
        DB::table('attendance_records')->insert([
            'attendance_session_id' => $session->id, 'candidate_id' => $this->first->id, 'status' => 'absent', 'recorded_by' => $this->admin->id,
        ]);
    }

    public function test_attendance_charts_cover_every_session_of_the_filtered_classes(): void
    {
        $this->recordChartSessions();

        // Every class: 14 Sep present, late, absent (2 of 3); 15 Sep two present, one excused, one absent (2 of 3).
        $trend = $this->actingAs($this->admin)->get('/attendance')->assertOk()->inertiaProps()['trend'];
        $this->assertEquals([
            ['date' => '2026-09-14', 'present' => 1, 'late' => 1, 'excused' => 0, 'absent' => 1, 'rate' => 66.67],
            ['date' => '2026-09-15', 'present' => 2, 'late' => 0, 'excused' => 1, 'absent' => 1, 'rate' => 66.67],
        ], $trend['days']);
        $this->assertSame(['present' => 3, 'late' => 1, 'excused' => 1, 'absent' => 2], $trend['totals']);

        // One class: only its sessions.
        $classB = $this->actingAs($this->admin)->get('/attendance?class='.$this->classB->id)->assertOk()->inertiaProps()['trend'];
        $this->assertEquals([['date' => '2026-09-15', 'present' => 0, 'late' => 0, 'excused' => 0, 'absent' => 1, 'rate' => 0]], $classB['days']);

        // An instructor: only the class they teach, whatever the filter says.
        $alpha = $this->actingAs($this->alpha)->get('/attendance?class='.$this->classB->id)->assertOk()->inertiaProps()['trend'];
        $this->assertSame(['2026-09-14', '2026-09-15'], array_column($alpha['days'], 'date'));
        $this->assertEquals([66.67, 100], array_column($alpha['days'], 'rate'));
        $this->assertSame(['present' => 3, 'late' => 1, 'excused' => 1, 'absent' => 1], $alpha['totals']);
    }

    public function test_attendance_chart_days_are_the_latest_ones_oldest_first(): void
    {
        $sessions = [];
        foreach (range(1, 3) as $day) {
            $sessions[] = $this->createSession(title: "Drill {$day}", date: sprintf('2026-09-%02d', $day));
        }
        foreach ($sessions as $session) {
            $this->record($session, [$this->first->id => 'present']);
        }

        $days = app(AttendanceLedger::class)->dailyRates([$this->classA->id], 2);

        $this->assertSame(['2026-09-02', '2026-09-03'], array_column($days, 'date'));
        $this->assertSame([], app(AttendanceLedger::class)->dailyRates([]));
        $this->assertSame(['present' => 0, 'late' => 0, 'excused' => 0, 'absent' => 0], app(AttendanceLedger::class)->statusTotals([$this->classB->id]));
    }

    public function test_dashboard_attendance_follows_the_attendance_scope_and_appears_once_recorded(): void
    {
        $this->assertNull($this->actingAs($this->admin)->get('/dashboard')->assertOk()->inertiaProps()['attendanceTrend']);

        $this->recordChartSessions();

        $admin = $this->actingAs($this->admin)->get('/dashboard')->assertOk()->inertiaProps()['attendanceTrend'];
        $this->assertSame('all', $admin['scope']);
        $this->assertSame('Period Current', $admin['period']['name']);
        $this->assertSame(['present' => 3, 'late' => 1, 'excused' => 1, 'absent' => 2], $admin['totals']);

        $bravo = $this->actingAs($this->bravo)->get('/dashboard')->assertOk()->inertiaProps()['attendanceTrend'];
        $this->assertSame('taught', $bravo['scope']);
        $this->assertSame(['2026-09-15'], array_column($bravo['days'], 'date'));
        $this->assertSame(['present' => 0, 'late' => 0, 'excused' => 0, 'absent' => 1], $bravo['totals']);
    }

    /**
     * Class A: 14 Sep first present, second late, third absent; 15 Sep first
     * and third present, second excused. Class B: 15 Sep the other absent.
     */
    private function recordChartSessions(): void
    {
        $this->record($this->createSession(title: 'Drill 14', date: '2026-09-14'), [$this->first->id => 'present', $this->second->id => 'late', $this->third->id => 'absent']);
        $this->record($this->createSession(title: 'Drill 15', date: '2026-09-15'), [$this->first->id => 'present', $this->second->id => 'excused', $this->third->id => 'present']);
        $this->record($this->createSession($this->classB, 'Class B Drill', date: '2026-09-15'), [$this->other->id => 'absent']);
    }

    private function createSession(?ClassBatch $classBatch = null, string $title = 'Morning Formation', string $hours = '1.5', string $date = '2026-09-15'): AttendanceSession
    {
        $this->actingAs($this->admin)->post('/attendance/sessions', [
            'class_batch_id' => ($classBatch ?? $this->classA)->id,
            'held_on' => $date,
            'title' => $title,
            'hours' => $hours,
        ])->assertSessionHasNoErrors()->assertRedirect();

        return AttendanceSession::query()->where('title', $title)->latest('id')->firstOrFail();
    }

    /**
     * @param  array<int, string>  $statuses  candidate id => status
     * @param  array{remarks?: string}  $extra
     */
    private function record(AttendanceSession $session, array $statuses, array $extra = []): void
    {
        $entries = array_map(fn (string $status): array => ['status' => $status, ...$extra], $statuses);

        $this->actingAs($this->admin)->put("/attendance/sessions/{$session->id}/records", ['entries' => $entries])
            ->assertSessionHasNoErrors()
            ->assertRedirect("/attendance/sessions/{$session->id}");
    }
}
