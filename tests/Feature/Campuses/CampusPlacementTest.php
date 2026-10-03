<?php

namespace Tests\Feature\Campuses;

use App\Enums\CampusCode;
use App\Enums\SystemRole;
use App\Models\AcademicPeriod;
use App\Models\Campus;
use App\Models\Candidate;
use App\Models\ClassBatch;
use App\Models\ClassSubject;
use App\Models\InstructorAssignment;
use App\Models\Role;
use App\Models\Subject;
use App\Models\User;
use App\Services\InstructorAssignmentService;
use Database\Factories\CampusFactory;
use Illuminate\Database\QueryException;
use Tests\TestCase;

/**
 * Where new records go (owner decisions 2026-10-03): a class is created on
 * one campus and keeps it; a candidate is on the class's campus; an
 * instructor belongs to one campus and teaches only there (also a database
 * rule); only administrators may see every campus.
 */
class CampusPlacementTest extends TestCase
{
    private Campus $main;

    private Campus $north;

    private AcademicPeriod $period;

    private User $institutionAdmin;

    private User $northAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->main = CampusFactory::fixed(CampusCode::South);
        $this->north = CampusFactory::fixed(CampusCode::North);
        $this->period = AcademicPeriod::factory()->active()->create();
        $this->institutionAdmin = $this->userWithRole(SystemRole::SuperAdministrator);
        $this->northAdmin = $this->userWithRole(SystemRole::SuperAdministrator, ['campus_id' => $this->north->id]);
    }

    public function test_a_class_is_created_on_a_chosen_campus_that_never_changes(): void
    {
        $this->actingAs($this->institutionAdmin)
            ->post('/classes', ['academic_period_id' => $this->period->id, 'name' => 'Class A'])
            ->assertSessionHasErrors('campus_id');

        $this->actingAs($this->institutionAdmin)
            ->post('/classes', ['academic_period_id' => $this->period->id, 'campus_id' => $this->north->id, 'name' => 'Class A'])
            ->assertSessionHasNoErrors();
        // The same name on another campus of the same year is allowed; on the same campus it is not.
        $this->actingAs($this->institutionAdmin)
            ->post('/classes', ['academic_period_id' => $this->period->id, 'campus_id' => $this->main->id, 'name' => 'Class A'])
            ->assertSessionHasNoErrors();
        $this->actingAs($this->institutionAdmin)
            ->post('/classes', ['academic_period_id' => $this->period->id, 'campus_id' => $this->north->id, 'name' => 'Class A'])
            ->assertSessionHasErrors(['name' => 'Another class of this campus in this academic period already uses this name.']);

        $class = ClassBatch::query()->where('campus_id', $this->north->id)->sole();
        $this->actingAs($this->institutionAdmin)
            ->put("/classes/{$class->id}", ['name' => 'Class A', 'campus_id' => $this->main->id])
            ->assertSessionHasErrors(['campus_id' => 'The campus of an existing class cannot be changed.']);
        $this->assertSame($this->north->id, $class->fresh()->campus_id);
    }

    public function test_a_campus_administrator_creates_classes_on_their_campus_only(): void
    {
        // Their own campus is filled in when it is the only choice.
        $this->actingAs($this->northAdmin)
            ->post('/classes', ['academic_period_id' => $this->period->id, 'name' => 'Class B'])
            ->assertSessionHasNoErrors();
        $this->assertSame($this->north->id, ClassBatch::query()->where('name', 'Class B')->value('campus_id'));

        $this->actingAs($this->northAdmin)
            ->post('/classes', ['academic_period_id' => $this->period->id, 'campus_id' => $this->main->id, 'name' => 'Class C'])
            ->assertSessionHasErrors(['campus_id' => 'Choose an active campus you manage.']);
        $this->assertFalse(ClassBatch::query()->where('name', 'Class C')->exists());

        // An inactive campus takes no new classes.
        $this->north->forceFill(['is_active' => false])->save();
        $this->actingAs($this->institutionAdmin)
            ->post('/classes', ['academic_period_id' => $this->period->id, 'campus_id' => $this->north->id, 'name' => 'Class D'])
            ->assertSessionHasErrors('campus_id');
    }

    public function test_a_candidate_is_on_the_campus_of_their_class(): void
    {
        $northClass = ClassBatch::factory()->for($this->period)->onCampus($this->north)->create();
        $mainClass = ClassBatch::factory()->for($this->period)->onCampus($this->main)->create();

        // A campus chosen beside a class is ignored: the class decides.
        $this->actingAs($this->institutionAdmin)
            ->post('/candidates', $this->candidatePayload(['class_batch_id' => $northClass->id, 'campus_id' => $this->main->id]))
            ->assertSessionHasNoErrors();
        $candidate = Candidate::query()->where('candidate_number', 'OC-0300')->sole();
        $this->assertSame($this->north->id, $candidate->campus_id);

        // Moving to a class of another campus moves the candidate with it.
        $candidate->classBatch()->associate($mainClass)->save();
        $this->assertSame($this->main->id, $candidate->fresh()->campus_id);

        // Without a class, the campus is required when there is a choice.
        $this->actingAs($this->institutionAdmin)
            ->post('/candidates', $this->candidatePayload(['candidate_number' => 'OC-0301', 'class_batch_id' => '']))
            ->assertSessionHasErrors('campus_id');
        $this->actingAs($this->institutionAdmin)
            ->post('/candidates', $this->candidatePayload(['candidate_number' => 'OC-0301', 'class_batch_id' => '', 'campus_id' => $this->north->id]))
            ->assertSessionHasNoErrors();
        $this->assertSame($this->north->id, Candidate::query()->where('candidate_number', 'OC-0301')->value('campus_id'));
    }

    public function test_a_campus_administrator_cannot_place_candidates_on_another_campus(): void
    {
        $mainClass = ClassBatch::factory()->for($this->period)->onCampus($this->main)->create();

        $this->actingAs($this->northAdmin)
            ->post('/candidates', $this->candidatePayload(['class_batch_id' => $mainClass->id]))
            ->assertSessionHasErrors('class_batch_id');
        $this->actingAs($this->northAdmin)
            ->post('/candidates', $this->candidatePayload(['class_batch_id' => '', 'campus_id' => $this->main->id]))
            ->assertSessionHasErrors('campus_id');

        // With no class, their own campus is used.
        $this->actingAs($this->northAdmin)
            ->post('/candidates', $this->candidatePayload(['class_batch_id' => '']))
            ->assertSessionHasNoErrors();
        $this->assertSame($this->north->id, Candidate::query()->where('candidate_number', 'OC-0300')->value('campus_id'));
    }

    public function test_the_database_keeps_a_candidate_on_the_campus_of_their_class(): void
    {
        $northClass = ClassBatch::factory()->for($this->period)->onCampus($this->north)->create();
        $candidate = Candidate::factory()->create(['class_batch_id' => $northClass->id]);

        $this->expectException(QueryException::class);
        // Bypasses the model's rule on purpose: the composite foreign key refuses it.
        Candidate::query()->whereKey($candidate->id)->update(['campus_id' => $this->main->id]);
    }

    public function test_instructors_teach_only_on_their_own_campus(): void
    {
        $northClass = ClassBatch::factory()->for($this->period)->onCampus($this->north)->create();
        $offering = $this->offering($northClass);
        $mainInstructor = User::factory()->withRole(SystemRole::Instructor)->onCampus($this->main)->create(['name' => 'Main Instructor']);
        $northInstructor = User::factory()->withRole(SystemRole::Instructor)->onCampus($this->north)->create();

        $this->actingAs($this->institutionAdmin)
            ->post('/instructor-assignments', ['class_subject_id' => $offering->id, 'instructor_id' => $mainInstructor->id])
            ->assertSessionHasErrors(['instructor_id' => 'Main Instructor teaches at South Campus, not at this class\'s campus.']);
        $this->actingAs($this->institutionAdmin)
            ->post('/instructor-assignments', ['class_subject_id' => $offering->id, 'instructor_id' => $northInstructor->id])
            ->assertSessionHasNoErrors();
        $this->assertSame($this->north->id, InstructorAssignment::query()->where('instructor_id', $northInstructor->id)->value('campus_id'));

        // The class page offers only instructors of the class's campus.
        $this->actingAs($this->institutionAdmin)->get("/classes/{$northClass->id}")
            ->assertInertia(fn ($page) => $page->where('instructorOptions', fn ($options): bool => collect($options)->pluck('id')->all() === [$northInstructor->id]));
    }

    public function test_the_database_refuses_an_assignment_across_campuses(): void
    {
        $offering = $this->offering(ClassBatch::factory()->for($this->period)->onCampus($this->north)->create());
        $mainInstructor = User::factory()->withRole(SystemRole::Instructor)->onCampus($this->main)->create();

        $this->expectException(QueryException::class);
        // The service copies the class's campus; the instructor's campus differs.
        app(InstructorAssignmentService::class)->assign($offering, $mainInstructor);
    }

    public function test_staff_account_campus_rules(): void
    {
        $instructorRole = Role::query()->where('code', SystemRole::Instructor->value)->sole();
        $adminRole = Role::query()->where('code', SystemRole::AcademicAdministrator->value)->sole();

        // Instructors always belong to one campus.
        $this->actingAs($this->institutionAdmin)
            ->post('/users', $this->userPayload(['role_id' => $instructorRole->id, 'campus_id' => '']))
            ->assertSessionHasErrors(['campus_id' => 'Instructors belong to one campus. Choose the campus they teach at.']);
        $this->actingAs($this->institutionAdmin)
            ->post('/users', $this->userPayload(['role_id' => $instructorRole->id, 'campus_id' => $this->north->id]))
            ->assertSessionHasNoErrors();
        $this->assertSame($this->north->id, User::query()->where('username', 'new.staff')->value('campus_id'));

        // An institution-wide Admin may create another administrator of every campus.
        $this->actingAs($this->institutionAdmin)
            ->post('/users', $this->userPayload(['username' => 'every.campus', 'role_id' => $adminRole->id, 'campus_id' => '']))
            ->assertSessionHasNoErrors();
        $this->assertNull(User::query()->where('username', 'every.campus')->value('campus_id'));

        // A campus administrator's accounts are always on their campus.
        $this->actingAs($this->northAdmin)
            ->post('/users', $this->userPayload(['username' => 'north.staff', 'role_id' => $adminRole->id, 'campus_id' => '']))
            ->assertSessionHasNoErrors();
        $this->assertSame($this->north->id, User::query()->where('username', 'north.staff')->value('campus_id'));
        $this->actingAs($this->northAdmin)
            ->post('/users', $this->userPayload(['username' => 'main.staff', 'role_id' => $adminRole->id, 'campus_id' => $this->main->id]))
            ->assertSessionHasErrors('campus_id');
        $this->assertFalse(User::query()->where('username', 'main.staff')->exists());
    }

    public function test_moving_an_account_between_campuses(): void
    {
        $northStaff = User::factory()->withRole(SystemRole::AcademicAdministrator)->onCampus($this->north)->create();
        $payload = fn (User $user, ?int $campusId): array => [
            'name' => $user->name, 'username' => $user->username, 'email' => '', 'is_active' => true,
            'password' => '', 'password_confirmation' => '', 'campus_id' => $campusId ?? '',
        ];

        // Nobody changes their own campus.
        $this->actingAs($this->northAdmin)
            ->put("/users/{$this->northAdmin->id}", $payload($this->northAdmin, null))
            ->assertSessionHasErrors(['campus_id' => 'You cannot change your own campus.']);
        // Campus administrators do not move accounts.
        $this->actingAs($this->northAdmin)
            ->put("/users/{$northStaff->id}", $payload($northStaff, $this->main->id))
            ->assertSessionHasErrors('campus_id');
        $this->assertSame($this->north->id, $northStaff->fresh()->campus_id);

        // The institution-wide Admin does; an edit without the field keeps the campus.
        $this->actingAs($this->institutionAdmin)
            ->put("/users/{$northStaff->id}", $payload($northStaff, $this->main->id))
            ->assertSessionHasNoErrors();
        $this->assertSame($this->main->id, $northStaff->fresh()->campus_id);
        $withoutCampus = $payload($northStaff, null);
        unset($withoutCampus['campus_id']);
        $this->actingAs($this->institutionAdmin)->put("/users/{$northStaff->id}", $withoutCampus)->assertSessionHasNoErrors();
        $this->assertSame($this->main->id, $northStaff->fresh()->campus_id);

        // An instructor with assignments stays where they teach.
        $instructor = User::factory()->withRole(SystemRole::Instructor)->onCampus($this->north)->create();
        app(InstructorAssignmentService::class)->assign($this->offering(ClassBatch::factory()->for($this->period)->onCampus($this->north)->create()), $instructor);
        $this->actingAs($this->institutionAdmin)
            ->put("/users/{$instructor->id}", $payload($instructor, $this->main->id))
            ->assertSessionHasErrors('campus_id');
        $this->assertSame($this->north->id, $instructor->fresh()->campus_id);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function candidatePayload(array $overrides = []): array
    {
        return [
            'candidate_number' => 'OC-0300',
            'first_name' => 'Juana',
            'last_name' => 'Example',
            'password' => 'tablet-password-1',
            'password_confirmation' => 'tablet-password-1',
            ...$overrides,
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function userPayload(array $overrides = []): array
    {
        return [
            'name' => 'New Staff',
            'username' => 'new.staff',
            'email' => '',
            'is_active' => true,
            'password' => 'correct-horse-battery',
            'password_confirmation' => 'correct-horse-battery',
            ...$overrides,
        ];
    }

    private function offering(ClassBatch $class): ClassSubject
    {
        $offering = new ClassSubject;
        $offering->classBatch()->associate($class);
        $offering->subject()->associate(Subject::factory()->create());
        $offering->save();

        return $offering;
    }
}
