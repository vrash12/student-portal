<?php

namespace Tests\Feature\Campuses;

use App\Enums\AuditAction;
use App\Enums\CampusCode;
use App\Enums\SystemRole;
use App\Models\AcademicPeriod;
use App\Models\AuditLog;
use App\Models\Campus;
use App\Models\Candidate;
use App\Models\ClassBatch;
use App\Models\ClassSubject;
use App\Models\Role;
use App\Models\Subject;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\InstructorAssignmentService;
use App\Support\CampusScope;
use Database\Factories\CampusFactory;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * The campus rule (CampusScope, owner decision 2026-10-03): an account
 * limited to a campus sees only that campus's classes, candidates, staff and
 * records; an account that sees every campus may narrow lists, dashboards
 * and reports to one campus with the Campus filter.
 */
class CampusScopingTest extends TestCase
{
    private Campus $main;

    private Campus $north;

    private User $institutionAdmin;

    private User $northAdmin;

    private ClassBatch $mainClass;

    private ClassBatch $northClass;

    private Candidate $mainCandidate;

    private Candidate $northCandidate;

    private User $mainInstructor;

    private User $northInstructor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->main = CampusFactory::fixed(CampusCode::South);
        $this->north = CampusFactory::fixed(CampusCode::North);
        $period = AcademicPeriod::factory()->active()->create(['name' => '2026-2027']);

        // The same class name on both campuses (allowed since 2026-10-03).
        $this->mainClass = ClassBatch::factory()->for($period)->onCampus($this->main)->create(['name' => 'Class A']);
        $this->northClass = ClassBatch::factory()->for($period)->onCampus($this->north)->create(['name' => 'Class A']);
        $this->mainCandidate = Candidate::factory()->create(['candidate_number' => 'MAIN-001', 'class_batch_id' => $this->mainClass->id]);
        $this->northCandidate = Candidate::factory()->create(['candidate_number' => 'NORTH-001', 'class_batch_id' => $this->northClass->id]);

        $this->mainInstructor = User::factory()->withRole(SystemRole::Instructor)->onCampus($this->main)->create(['username' => 'main.instructor']);
        $this->northInstructor = User::factory()->withRole(SystemRole::Instructor)->onCampus($this->north)->create(['username' => 'north.instructor']);
        $subject = Subject::factory()->create();
        app(InstructorAssignmentService::class)->assign($this->offering($this->mainClass, $subject), $this->mainInstructor);
        app(InstructorAssignmentService::class)->assign($this->offering($this->northClass, $subject), $this->northInstructor);

        $this->institutionAdmin = $this->userWithRole(SystemRole::SuperAdministrator, ['username' => 'institution.admin']);
        $this->northAdmin = $this->userWithRole(SystemRole::SuperAdministrator, ['username' => 'north.admin', 'campus_id' => $this->north->id]);
    }

    public function test_candidate_lists_show_only_the_viewers_campus(): void
    {
        $this->actingAs($this->northAdmin)->get('/candidates')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('candidates.data', 1)
                ->where('candidates.data.0.candidateNumber', 'NORTH-001')
                ->where('candidates.data.0.campus.code', 'NORTH')
                // No campus filter for an account limited to one campus.
                ->where('campusOptions', []));

        // The filter of another campus is ignored: the campus never widens or moves.
        $this->actingAs($this->northAdmin)->get('/candidates?campus='.$this->main->id)
            ->assertInertia(fn (Assert $page) => $page
                ->has('candidates.data', 1)
                ->where('candidates.data.0.candidateNumber', 'NORTH-001')
                ->where('filters.campus', ''));

        $this->actingAs($this->institutionAdmin)->get('/candidates')
            ->assertInertia(fn (Assert $page) => $page->has('candidates.data', 2)->has('campusOptions', 4));

        $this->actingAs($this->institutionAdmin)->get('/candidates?campus='.$this->north->id)
            ->assertInertia(fn (Assert $page) => $page
                ->has('candidates.data', 1)
                ->where('candidates.data.0.candidateNumber', 'NORTH-001')
                ->where('filters.campus', (string) $this->north->id));
    }

    public function test_class_instructor_and_user_lists_show_only_the_viewers_campus(): void
    {
        $this->actingAs($this->northAdmin)->get('/classes')
            ->assertInertia(fn (Assert $page) => $page->has('classes.data', 1)->where('classes.data.0.id', $this->northClass->id));
        $this->actingAs($this->institutionAdmin)->get('/classes?campus='.$this->main->id)
            ->assertInertia(fn (Assert $page) => $page->has('classes.data', 1)->where('classes.data.0.id', $this->mainClass->id));

        $this->actingAs($this->northAdmin)->get('/instructors')
            ->assertInertia(fn (Assert $page) => $page->has('instructors.data', 1)->where('instructors.data.0.username', 'north.instructor'));

        // Staff of the campus only: not the other campus, not the institution-wide accounts.
        $this->actingAs($this->northAdmin)->get('/users')
            ->assertInertia(fn (Assert $page) => $page->where(
                'users.data',
                fn ($rows): bool => collect($rows)->pluck('username')->sort()->values()->all() === ['north.admin', 'north.instructor'],
            ));
        $this->actingAs($this->institutionAdmin)->get('/users')
            ->assertInertia(fn (Assert $page) => $page->has('users.data', 4));
    }

    public function test_records_of_another_campus_cannot_be_opened_by_url(): void
    {
        foreach ([
            "/candidates/{$this->mainCandidate->id}",
            "/candidates/{$this->mainCandidate->id}/edit",
            "/classes/{$this->mainClass->id}",
            "/instructors/{$this->mainInstructor->id}",
            "/users/{$this->mainInstructor->id}/edit",
            "/users/{$this->institutionAdmin->id}/edit",
            "/medical-records/{$this->mainCandidate->id}/edit",
            "/conduct/candidates/{$this->mainCandidate->id}",
        ] as $url) {
            $status = $this->actingAs($this->northAdmin)->get($url)->getStatusCode();
            $this->assertContains($status, [403, 404], "North Admin opened {$url} (status {$status}).");
        }

        $this->actingAs($this->northAdmin)
            ->put("/candidates/{$this->mainCandidate->id}", ['candidate_number' => 'MAIN-001', 'first_name' => 'Changed', 'last_name' => 'Name', 'class_batch_id' => $this->mainClass->id, 'status' => 'enrolled', 'account_active' => true])
            ->assertNotFound();
        $this->assertNotSame('Changed', $this->mainCandidate->fresh()->first_name);

        // The same pages of the admin's own campus open.
        $this->actingAs($this->northAdmin)->get("/candidates/{$this->northCandidate->id}")->assertOk();
        $this->actingAs($this->northAdmin)->get("/classes/{$this->northClass->id}")->assertOk();
    }

    public function test_the_dashboard_counts_only_the_viewers_campus_and_can_be_narrowed(): void
    {
        $this->actingAs($this->northAdmin)->get('/dashboard')
            ->assertInertia(fn (Assert $page) => $page
                ->where('campusFilter.options', [])
                ->where('administratorOverview.totalCandidates', 1));

        $this->actingAs($this->institutionAdmin)->get('/dashboard')
            ->assertInertia(fn (Assert $page) => $page
                ->has('campusFilter.options', 4)
                ->where('administratorOverview.totalCandidates', 2));

        $this->actingAs($this->institutionAdmin)->get('/dashboard?campus='.$this->main->id)
            ->assertInertia(fn (Assert $page) => $page
                ->where('campusFilter.value', (string) $this->main->id)
                ->where('administratorOverview.totalCandidates', 1));
    }

    public function test_the_dashboard_names_the_campus_of_each_class_when_it_shows_several(): void
    {
        // Retired roles nobody has any more are not counted on the dashboard.
        Role::query()->create(['code' => 'finance_officer', 'name' => 'Finance Officer (removed)']);
        $classesOf = fn (array $instructors): array => collect($instructors)->pluck('classes')->flatten()->sort()->values()->all();

        $this->actingAs($this->institutionAdmin)->get('/dashboard')
            ->assertInertia(fn (Assert $page) => $page
                ->where('administratorOverview.instructors', fn ($instructors): bool => $classesOf($instructors->all()) === ['Class A · NORTH', 'Class A · SOUTH'])
                ->where('accountSummary', fn ($roles): bool => ! collect($roles)->pluck('code')->contains('finance_officer')));

        // One campus at a time (narrowed, or a campus administrator): plain names.
        $this->actingAs($this->institutionAdmin)->get('/dashboard?campus='.$this->main->id)
            ->assertInertia(fn (Assert $page) => $page
                ->where('administratorOverview.instructors', fn ($instructors): bool => $classesOf($instructors->all()) === ['Class A']));
        $this->actingAs($this->northAdmin)->get('/dashboard')
            ->assertInertia(fn (Assert $page) => $page
                ->where('administratorOverview.instructors', fn ($instructors): bool => $classesOf($instructors->all()) === ['Class A']));
    }

    public function test_reports_cover_the_viewers_campus_and_name_it(): void
    {
        $this->actingAs($this->northAdmin)->get('/reports?type=candidate')
            ->assertInertia(fn (Assert $page) => $page
                ->has('rows.data', 1)
                ->where('rows.data.0.number', 'NORTH-001')
                ->where('campusLabel', 'North Campus'));

        $this->actingAs($this->institutionAdmin)->get('/reports?type=candidate')
            ->assertInertia(fn (Assert $page) => $page->has('rows.data', 2)->where('campusLabel', 'All campuses'));

        $this->actingAs($this->institutionAdmin)->get('/reports?type=candidate&campus='.$this->main->id)
            ->assertInertia(fn (Assert $page) => $page
                ->has('rows.data', 1)
                ->where('rows.data.0.number', 'MAIN-001')
                ->where('campusLabel', 'South Campus'));
    }

    public function test_audit_history_shows_entries_about_the_viewers_campus(): void
    {
        $logger = app(AuditLogger::class);
        $logger->record(AuditAction::CandidateUpdated, $this->mainCandidate, newValues: ['note' => 'main'], actor: $this->institutionAdmin);
        $logger->record(AuditAction::CandidateUpdated, $this->northCandidate, newValues: ['note' => 'north'], actor: $this->institutionAdmin);

        $this->assertSame($this->main->id, AuditLog::query()->where('auditable_id', $this->mainCandidate->id)->where('auditable_type', 'candidate')->latest('id')->value('campus_id'));

        $northEntries = AuditLog::query()->where('campus_id', $this->north->id)->count();
        $this->actingAs($this->northAdmin)->get('/audit-history')
            ->assertInertia(fn (Assert $page) => $page
                ->where('entries.total', $northEntries)
                ->where('entries.data', fn ($rows): bool => collect($rows)->every(fn (array $row): bool => ! ($row['entity'] === 'candidate' && $row['entityId'] === $this->mainCandidate->id))));

        $this->actingAs($this->institutionAdmin)->get('/audit-history?campus='.$this->main->id)
            ->assertInertia(fn (Assert $page) => $page->where('entries.total', AuditLog::query()->where('campus_id', $this->main->id)->count()));
    }

    public function test_medical_and_monitoring_lists_are_scoped(): void
    {
        $this->actingAs($this->northAdmin)->get('/medical-records')
            ->assertInertia(fn (Assert $page) => $page->has('candidates.data', 1)->where('candidates.data.0.number', 'NORTH-001'));

        $this->actingAs($this->northAdmin)->get('/monitoring')
            ->assertInertia(fn (Assert $page) => $page->where(
                'candidates.data',
                fn ($rows): bool => collect($rows)->pluck('candidate.candidateNumber')->all() === ['NORTH-001'],
            ));

        // An instructor sees only their own campus's subjects as well.
        $this->assertTrue($this->northInstructor->campusScope()->allows($this->north->id));
        $this->assertFalse($this->northInstructor->campusScope()->allows($this->main->id));
        $this->assertFalse(CampusScope::for($this->northAdmin)->isInstitutionWide());
    }

    private function offering(ClassBatch $class, Subject $subject): ClassSubject
    {
        $offering = new ClassSubject;
        $offering->classBatch()->associate($class);
        $offering->subject()->associate($subject);
        $offering->save();

        return $offering;
    }
}
