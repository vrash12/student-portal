<?php

namespace Tests\Feature\Announcements;

use App\Enums\AuditAction;
use App\Enums\CampusCode;
use App\Enums\SystemRole;
use App\Models\AcademicPeriod;
use App\Models\Announcement;
use App\Models\AuditLog;
use App\Models\Campus;
use App\Models\Candidate;
use App\Models\ClassBatch;
use App\Models\Subject;
use App\Models\User;
use App\Services\ClassBatchService;
use App\Services\InstructorAssignmentService;
use Database\Factories\CampusFactory;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Announcements (owner request, 2026-10-06): notices to every candidate, a
 * campus or a class; who may post where; scheduled, ended and withdrawn
 * notices; the candidate's portal shows only notices meant for them.
 */
class AnnouncementTest extends TestCase
{
    private Campus $south;

    private Campus $north;

    private User $admin;

    private User $southAdmin;

    private User $alpha;

    private User $bravo;

    private ClassBatch $classA;

    private ClassBatch $classB;

    private ClassBatch $northClass;

    private Candidate $inA;

    private Candidate $inB;

    private Candidate $inNorth;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-10-06 08:00:00');
        // Dates are entered in the institution's timezone (UTC+8 here) and stored in UTC.
        config(['institution.timezone' => 'Asia/Manila']);

        $this->south = CampusFactory::fixed(CampusCode::South);
        $this->north = CampusFactory::fixed(CampusCode::North);
        $this->admin = $this->userWithRole(SystemRole::AcademicAdministrator);
        $this->southAdmin = User::factory()->withRole(SystemRole::AcademicAdministrator)->onCampus($this->south)->create();

        $period = AcademicPeriod::factory()->active()->create(['name' => '2026-2027']);
        $this->classA = ClassBatch::factory()->for($period)->onCampus($this->south)->create(['name' => 'Class A']);
        $this->classB = ClassBatch::factory()->for($period)->onCampus($this->south)->create(['name' => 'Class B']);
        $this->northClass = ClassBatch::factory()->for($period)->onCampus($this->north)->create(['name' => 'Class N']);

        $this->inA = Candidate::factory()->create(['class_batch_id' => $this->classA->id]);
        $this->inB = Candidate::factory()->create(['class_batch_id' => $this->classB->id]);
        $this->inNorth = Candidate::factory()->create(['class_batch_id' => $this->northClass->id]);

        // Instructor Alpha teaches Class A; Instructor Bravo teaches Class B.
        $subject = Subject::factory()->create(['code' => 'SUBJ-1', 'name' => 'Subject 1']);
        $this->alpha = User::factory()->withRole(SystemRole::Instructor)->onCampus($this->south)->create(['name' => 'Instructor Alpha']);
        $this->bravo = User::factory()->withRole(SystemRole::Instructor)->onCampus($this->south)->create(['name' => 'Instructor Bravo']);
        $classes = $this->app->make(ClassBatchService::class);
        $assignments = $this->app->make(InstructorAssignmentService::class);
        $assignments->assign($classes->addSubject($this->classA, $subject), $this->alpha);
        $assignments->assign($classes->addSubject($this->classB, $subject), $this->bravo);
    }

    public function test_a_class_notice_is_posted_audited_and_seen_only_by_that_class(): void
    {
        $this->actingAs($this->alpha)->post('/announcements', $this->notice([
            'audience' => 'class',
            'class_batch_id' => $this->classA->id,
            'title' => '  Examination moved to Friday  ',
        ]))->assertRedirect('/announcements')->assertSessionHasNoErrors();

        $notice = Announcement::query()->sole();
        $this->assertSame('Examination moved to Friday', $notice->title);
        $this->assertSame($this->south->id, $notice->campus_id);
        $this->assertSame($this->alpha->id, $notice->created_by);
        $this->assertSame('2026-10-06 08:00:00', $notice->publishes_at->toDateTimeString());

        $audit = AuditLog::query()->where('action', AuditAction::AnnouncementPosted->value)->sole();
        $this->assertSame('announcement', $audit->auditable_type);
        $this->assertSame($this->south->id, $audit->campus_id);
        $this->assertSame('Class A', $audit->new_values['class']);

        $this->assertSame(['Examination moved to Friday'], $this->portalTitles($this->inA));
        $this->assertSame([], $this->portalTitles($this->inB));
        $this->assertSame([], $this->portalTitles($this->inNorth));
    }

    public function test_campus_and_everyone_notices_reach_the_right_candidates_important_first(): void
    {
        $this->actingAs($this->admin)->post('/announcements', $this->notice(['audience' => 'campus', 'campus_id' => $this->north->id, 'title' => 'North formation']))->assertSessionHasNoErrors();
        $this->travel(1)->minutes();
        $this->actingAs($this->admin)->post('/announcements', $this->notice(['audience' => 'everyone', 'title' => 'Holiday schedule']))->assertSessionHasNoErrors();
        $this->travel(1)->minutes();
        $this->actingAs($this->admin)->post('/announcements', $this->notice(['audience' => 'everyone', 'title' => 'Read this first', 'is_important' => true]))->assertSessionHasNoErrors();

        $this->assertSame(['Read this first', 'Holiday schedule', 'North formation'], $this->portalTitles($this->inNorth));
        $this->assertSame(['Read this first', 'Holiday schedule'], $this->portalTitles($this->inA));
    }

    public function test_instructors_post_only_to_the_classes_they_teach(): void
    {
        $this->actingAs($this->alpha)->post('/announcements', $this->notice(['audience' => 'class', 'class_batch_id' => $this->classB->id]))
            ->assertSessionHasErrors(['class_batch_id' => 'You cannot post a notice to these candidates.']);
        $this->actingAs($this->alpha)->post('/announcements', $this->notice(['audience' => 'campus', 'campus_id' => $this->south->id]))
            ->assertSessionHasErrors('campus_id');
        $this->actingAs($this->alpha)->post('/announcements', $this->notice(['audience' => 'everyone']))
            ->assertSessionHasErrors('audience');

        $this->assertSame(0, Announcement::query()->count());

        $this->actingAs($this->alpha)->get('/announcements/create')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('staff/announcements/create')
            ->has('audiences', 1)
            ->where('audiences.0.value', 'class')
            ->has('classOptions.0.classes', 1)
            ->where('classOptions.0.classes.0.name', 'Class A')
            ->has('campusOptions', 0));
    }

    public function test_campus_administrators_post_only_on_their_campus(): void
    {
        $this->actingAs($this->southAdmin)->post('/announcements', $this->notice(['audience' => 'everyone']))->assertSessionHasErrors('audience');
        $this->actingAs($this->southAdmin)->post('/announcements', $this->notice(['audience' => 'campus', 'campus_id' => $this->north->id]))->assertSessionHasErrors('campus_id');
        $this->actingAs($this->southAdmin)->post('/announcements', $this->notice(['audience' => 'class', 'class_batch_id' => $this->northClass->id]))->assertSessionHasErrors('class_batch_id');
        $this->assertSame(0, Announcement::query()->count());

        $this->actingAs($this->southAdmin)->post('/announcements', $this->notice(['audience' => 'campus', 'campus_id' => $this->south->id]))->assertSessionHasNoErrors();
        $this->actingAs($this->southAdmin)->post('/announcements', $this->notice(['audience' => 'class', 'class_batch_id' => $this->classB->id]))->assertSessionHasNoErrors();
        $this->assertSame(2, Announcement::query()->count());

        // A notice to every candidate, by an institution-wide administrator: read only for the campus administrator.
        $this->actingAs($this->admin)->post('/announcements', $this->notice(['audience' => 'everyone', 'title' => 'For everyone']))->assertSessionHasNoErrors();
        $everyone = Announcement::query()->where('title', 'For everyone')->sole();
        $this->actingAs($this->southAdmin)->get("/announcements/{$everyone->id}/edit")->assertNotFound();
        $this->actingAs($this->southAdmin)->post("/announcements/{$everyone->id}/withdraw")->assertNotFound();
        $this->actingAs($this->southAdmin)->get('/announcements')->assertInertia(fn (Assert $page) => $page
            ->has('announcements.data', 3)
            ->where('announcements.data.0.title', 'For everyone')
            ->where('announcements.data.0.canManage', false)
            ->where('announcements.data.1.canManage', true));
    }

    public function test_instructors_change_only_their_own_notices_and_administrators_any_in_scope(): void
    {
        $notice = $this->post_(['audience' => 'class', 'class_batch_id' => $this->classA->id, 'title' => 'Bring your notebook'], $this->alpha);

        $this->actingAs($this->bravo)->get("/announcements/{$notice->id}/edit")->assertForbidden();
        $this->actingAs($this->bravo)->put("/announcements/{$notice->id}", $this->changes())->assertForbidden();
        // Bravo does not teach Class A, so its notices are not in Bravo's list.
        $this->actingAs($this->bravo)->get('/announcements')->assertInertia(fn (Assert $page) => $page->has('announcements.data', 0)->where('scope', 'own'));

        $this->actingAs($this->alpha)->put("/announcements/{$notice->id}", $this->changes(['title' => 'Bring your notebook and pen']))
            ->assertRedirect('/announcements')->assertSessionHasNoErrors();
        $this->assertSame('Bring your notebook and pen', $notice->refresh()->title);
        $this->assertSame($this->alpha->id, $notice->updated_by);

        $audit = AuditLog::query()->where('action', AuditAction::AnnouncementUpdated->value)->sole();
        $this->assertSame(['title' => 'Bring your notebook'], $audit->old_values);
        $this->assertSame(['title' => 'Bring your notebook and pen'], $audit->new_values);

        $this->actingAs($this->southAdmin)->put("/announcements/{$notice->id}", $this->changes(['is_important' => true]))->assertSessionHasNoErrors();
        $this->assertTrue($notice->refresh()->is_important);

        // The audience never changes.
        $this->actingAs($this->alpha)->put("/announcements/{$notice->id}", [...$this->changes(), 'audience' => 'everyone'])->assertSessionHasErrors('audience');
    }

    public function test_scheduled_ended_and_withdrawn_notices_are_not_shown(): void
    {
        $this->actingAs($this->admin)->post('/announcements', $this->notice([
            'audience' => 'everyone', 'title' => 'Later', 'publishes_at' => '2026-10-07T07:00', 'expires_at' => '2026-10-08T07:00',
        ]))->assertSessionHasNoErrors();
        $notice = Announcement::query()->sole();
        // Entered in the institution's timezone, stored in UTC.
        $this->assertSame('2026-10-06 23:00:00', $notice->publishes_at->toDateTimeString());

        $this->assertSame([], $this->portalTitles($this->inA));
        $this->travelTo('2026-10-07 00:00:00');
        $this->assertSame(['Later'], $this->portalTitles($this->inA));
        $this->travelTo('2026-10-08 00:00:00');
        $this->assertSame([], $this->portalTitles($this->inA));

        $current = $this->post_(['audience' => 'everyone', 'title' => 'Now'], $this->admin);
        $this->assertSame(['Now'], $this->portalTitles($this->inA));
        $this->actingAs($this->admin)->post("/announcements/{$current->id}/withdraw")->assertRedirect();
        $this->assertSame([], $this->portalTitles($this->inA));
        $this->assertNotNull($current->refresh()->withdrawn_at);
        $this->assertSame($this->admin->id, $current->withdrawn_by);
        $this->assertSame(1, AuditLog::query()->where('action', AuditAction::AnnouncementWithdrawn->value)->count());

        // A withdrawn notice is history: no more changes.
        $this->actingAs($this->admin)->put("/announcements/{$current->id}", $this->changes())->assertForbidden();
        $this->actingAs($this->admin)->post("/announcements/{$current->id}/withdraw")->assertForbidden();

        $this->actingAs($this->admin)->get('/announcements?status=withdrawn')->assertInertia(fn (Assert $page) => $page
            ->has('announcements.data', 1)
            ->where('announcements.data.0.status.value', 'withdrawn'));
        $this->actingAs($this->admin)->get('/announcements?status=expired')->assertInertia(fn (Assert $page) => $page
            ->has('announcements.data', 1)
            ->where('announcements.data.0.title', 'Later'));
    }

    public function test_notice_details_are_validated(): void
    {
        $valid = $this->notice(['audience' => 'class', 'class_batch_id' => $this->classA->id]);

        $this->actingAs($this->alpha)->post('/announcements', [...$valid, 'title' => ' '])->assertSessionHasErrors(['title' => 'Enter a title.']);
        $this->actingAs($this->alpha)->post('/announcements', [...$valid, 'body' => ''])->assertSessionHasErrors(['body' => 'Enter the message.']);
        $this->actingAs($this->alpha)->post('/announcements', [...$valid, 'body' => str_repeat('x', 5001)])->assertSessionHasErrors('body');
        $this->actingAs($this->alpha)->post('/announcements', [...$valid, 'class_batch_id' => ''])->assertSessionHasErrors(['class_batch_id' => 'Choose the class.']);
        $this->actingAs($this->alpha)->post('/announcements', [...$valid, 'audience' => 'school'])->assertSessionHasErrors('audience');
        $this->actingAs($this->alpha)->post('/announcements', [...$valid, 'publishes_at' => 'tomorrow'])->assertSessionHasErrors('publishes_at');
        $this->actingAs($this->alpha)->post('/announcements', [...$valid, 'publishes_at' => '2026-10-09T08:00', 'expires_at' => '2026-10-09T08:00'])
            ->assertSessionHasErrors(['expires_at' => 'The notice must end after it starts showing.']);
        $this->actingAs($this->alpha)->post('/announcements', [...$valid, 'expires_at' => '2026-10-05T08:00'])->assertSessionHasErrors('expires_at');
        // A campus is not sent with a class notice.
        $this->actingAs($this->alpha)->post('/announcements', [...$valid, 'campus_id' => $this->south->id])->assertSessionHasErrors('campus_id');

        $this->assertSame(0, Announcement::query()->count());
    }

    public function test_the_database_keeps_the_audience_consistent(): void
    {
        $this->expectException(QueryException::class);

        DB::table('announcements')->insert([
            'title' => 'Broken', 'body' => 'x', 'audience' => 'everyone', 'campus_id' => $this->south->id,
            'publishes_at' => now(), 'created_by' => $this->admin->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_candidates_and_staff_without_the_permission_cannot_open_announcements(): void
    {
        $this->actingAs($this->inA->user)->get('/announcements')->assertForbidden();
        $dietitian = User::factory()->withRole(SystemRole::Dietitian)->onCampus($this->south)->create();
        $this->actingAs($dietitian)->get('/announcements')->assertForbidden();
        $this->actingAs($dietitian)->post('/announcements', $this->notice(['audience' => 'campus', 'campus_id' => $this->south->id]))->assertForbidden();
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function notice(array $overrides = []): array
    {
        return [
            'title' => 'Report for medical check-up',
            'body' => "Report to the clinic at 0800.\nBring your ID.",
            'is_important' => false,
            'publishes_at' => '',
            'expires_at' => '',
            ...$overrides,
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function changes(array $overrides = []): array
    {
        return [
            'title' => 'Bring your notebook',
            'body' => "Report to the clinic at 0800.\nBring your ID.",
            'is_important' => false,
            'publishes_at' => '2026-10-06T16:00',
            'expires_at' => '',
            ...$overrides,
        ];
    }

    /** @param  array<string, mixed>  $overrides */
    private function post_(array $overrides, User $actor): Announcement
    {
        $this->actingAs($actor)->post('/announcements', $this->notice($overrides))->assertSessionHasNoErrors();

        return Announcement::query()->latest('id')->firstOrFail();
    }

    /** @return list<string> */
    private function portalTitles(Candidate $candidate): array
    {
        $titles = [];
        $this->actingAs($candidate->user)->get('/portal')->assertOk()->assertInertia(function (Assert $page) use (&$titles): void {
            $page->component('portal/home');
            $titles = array_column($page->toArray()['props']['notices'], 'title');
        });

        return $titles;
    }
}
