<?php

namespace Tests\Feature\Users;

use App\Enums\AuditAction;
use App\Enums\SystemRole;
use App\Models\AuditLog;
use App\Models\Role;
use App\Models\User;
use Database\Factories\CampusFactory;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class UserManagementTest extends TestCase
{
    private int $campusId;

    protected function setUp(): void
    {
        parent::setUp();

        // The campus new instructors are placed on (one of the four fixed campuses).
        $this->campusId = CampusFactory::defaultCampusId();
    }

    public function test_index_lists_staff_accounts_only(): void
    {
        $admin = $this->userWithRole(SystemRole::SuperAdministrator, ['name' => 'Admin Person']);
        $this->userWithRole(SystemRole::Instructor, ['name' => 'Instructor Person']);
        $this->userWithRole(SystemRole::Candidate, ['name' => 'Candidate Person']);

        $this->actingAs($admin)
            ->get('/users')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('staff/users/index')
                ->has('users.data', 2)
                ->where('users.data.0.name', 'Admin Person')
                ->where('users.data.1.name', 'Instructor Person')
                ->where('canCreate', true));
    }

    public function test_index_filters_by_search_role_and_status(): void
    {
        $admin = $this->userWithRole(SystemRole::SuperAdministrator, ['name' => 'Admin Person']);
        $this->userWithRole(SystemRole::Instructor, ['name' => 'Instructor Alpha', 'username' => 'instructor.alpha']);
        User::factory()->withRole(SystemRole::Instructor)->inactive()->create(['name' => 'Instructor Bravo']);

        $this->actingAs($admin)
            ->get('/users?search=alph')
            ->assertInertia(fn (Assert $page) => $page->has('users.data', 1)->where('users.data.0.username', 'instructor.alpha'));

        $this->actingAs($admin)
            ->get('/users?role=instructor&status=inactive')
            ->assertInertia(fn (Assert $page) => $page->has('users.data', 1)->where('users.data.0.name', 'Instructor Bravo'));
    }

    public function test_index_ignores_unknown_filter_values(): void
    {
        $admin = $this->userWithRole(SystemRole::SuperAdministrator);

        $this->actingAs($admin)
            ->get('/users?role=candidate&status=everything')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('filters.role', '')
                ->where('filters.status', '')
                ->has('users.data', 1));
    }

    public function test_search_treats_wildcard_characters_literally(): void
    {
        $admin = $this->userWithRole(SystemRole::SuperAdministrator, ['name' => 'Admin Person']);

        $this->actingAs($admin)
            ->get('/users?search=%25')
            ->assertInertia(fn (Assert $page) => $page->has('users.data', 0));
    }

    public function test_administrator_creates_a_staff_account(): void
    {
        $admin = $this->userWithRole(SystemRole::SuperAdministrator);
        $instructorRole = $this->role(SystemRole::Instructor);

        $this->actingAs($admin)
            ->post('/users', $this->validPayload(['role_id' => $instructorRole->id]))
            ->assertRedirect(route('users.index'))
            ->assertInertiaFlash('toast.type', 'success');

        $created = User::query()->where('username', 'new.instructor')->sole();
        $this->assertSame('New Instructor', $created->name);
        $this->assertSame($instructorRole->id, $created->role_id);
        $this->assertSame($this->campusId, $created->campus_id);
        $this->assertTrue($created->is_active);
        $this->assertTrue(Hash::check('correct-horse-battery', $created->password));

        $entry = AuditLog::query()->where('action', AuditAction::UserCreated->value)->sole();
        $this->assertSame($admin->id, $entry->actor_id);
        $this->assertSame($created->id, $entry->auditable_id);
        $this->assertSame('Instructor', $entry->new_values['role']);
        $this->assertArrayNotHasKey('password', $entry->new_values);
    }

    public function test_username_and_email_are_normalized(): void
    {
        $admin = $this->userWithRole(SystemRole::SuperAdministrator);

        $this->actingAs($admin)->post('/users', $this->validPayload([
            'username' => '  New.Instructor ',
            'email' => ' New.Instructor@Example.TEST ',
        ]));

        $created = User::query()->where('username', 'new.instructor')->sole();
        $this->assertSame('new.instructor@example.test', $created->email);
    }

    public function test_create_rejects_invalid_input_with_helpful_messages(): void
    {
        $admin = $this->userWithRole(SystemRole::SuperAdministrator, ['username' => 'taken.name']);

        $this->actingAs($admin)
            ->post('/users', $this->validPayload([
                'name' => '',
                'username' => 'Taken.Name',
                'password' => 'short',
                'password_confirmation' => 'different',
            ]))
            ->assertSessionHasErrors([
                'name',
                'username' => 'This username is already assigned to another account.',
                'password',
            ]);

        $this->actingAs($admin)
            ->post('/users', $this->validPayload(['username' => 'has spaces!']))
            ->assertSessionHasErrors([
                'username' => 'Use only lowercase letters, numbers, periods, hyphens, and underscores.',
            ]);

        $this->assertDatabaseMissing('users', ['name' => 'New Instructor']);
    }

    public function test_candidate_accounts_cannot_be_created_here(): void
    {
        $admin = $this->userWithRole(SystemRole::SuperAdministrator);

        $this->actingAs($admin)
            ->post('/users', $this->validPayload(['role_id' => $this->role(SystemRole::Candidate)->id]))
            ->assertSessionHasErrors(['role_id' => 'Select a valid staff role.']);
    }

    public function test_academic_administrator_cannot_grant_the_super_administrator_role(): void
    {
        $academicAdmin = $this->userWithRole(SystemRole::AcademicAdministrator);
        $superRole = $this->role(SystemRole::SuperAdministrator);

        $this->actingAs($academicAdmin)
            ->get('/users/create')
            ->assertInertia(fn (Assert $page) => $page
                ->where('roles', fn ($roles) => collect($roles)->pluck('id')->doesntContain($superRole->id)));

        $this->actingAs($academicAdmin)
            ->post('/users', $this->validPayload(['role_id' => $superRole->id]))
            ->assertSessionHasErrors(['role_id' => 'You can only assign roles ranked below your own.']);

        $this->assertDatabaseMissing('users', ['username' => 'new.instructor']);
    }

    public function test_academic_administrator_can_create_instructor_accounts(): void
    {
        $academicAdmin = $this->userWithRole(SystemRole::AcademicAdministrator);

        $this->actingAs($academicAdmin)
            ->post('/users', $this->validPayload(['role_id' => $this->role(SystemRole::Instructor)->id]))
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('users.index'));

        $this->assertDatabaseHas('users', ['username' => 'new.instructor']);
    }

    public function test_academic_administrator_cannot_create_or_edit_peers(): void
    {
        $academicAdmin = $this->userWithRole(SystemRole::AcademicAdministrator);
        $peer = $this->userWithRole(SystemRole::AcademicAdministrator);

        $this->actingAs($academicAdmin)
            ->post('/users', $this->validPayload(['role_id' => $this->role(SystemRole::AcademicAdministrator)->id]))
            ->assertSessionHasErrors(['role_id' => 'You can only assign roles ranked below your own.']);

        $this->actingAs($academicAdmin)->get("/users/{$peer->id}/edit")->assertForbidden();
    }

    public function test_edit_form_shows_only_the_accounts_fixed_role(): void
    {
        $admin = $this->userWithRole(SystemRole::SuperAdministrator);
        $instructor = $this->userWithRole(SystemRole::Instructor);

        $this->actingAs($admin)
            ->get("/users/{$instructor->id}/edit")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('isOwnAccount', false)
                ->has('roles', 1)
                ->where('roles.0.id', $instructor->role_id));
    }

    public function test_academic_administrator_cannot_edit_a_super_administrator(): void
    {
        $academicAdmin = $this->userWithRole(SystemRole::AcademicAdministrator);
        $superAdmin = $this->userWithRole(SystemRole::SuperAdministrator);

        $this->actingAs($academicAdmin)->get("/users/{$superAdmin->id}/edit")->assertForbidden();
        $this->actingAs($academicAdmin)
            ->put("/users/{$superAdmin->id}", $this->updatePayload($superAdmin, ['name' => 'Changed']))
            ->assertForbidden();

        $this->assertSame($superAdmin->name, $superAdmin->fresh()->name);
    }

    public function test_instructor_cannot_create_or_update_accounts(): void
    {
        $instructor = $this->userWithRole(SystemRole::Instructor);
        $colleague = $this->userWithRole(SystemRole::Instructor);

        $this->actingAs($instructor)->post('/users', $this->validPayload())->assertForbidden();
        $this->actingAs($instructor)
            ->put("/users/{$colleague->id}", $this->updatePayload($colleague, ['name' => 'Changed']))
            ->assertForbidden();
    }

    public function test_candidate_accounts_cannot_be_edited_here(): void
    {
        $admin = $this->userWithRole(SystemRole::SuperAdministrator);
        $candidate = $this->userWithRole(SystemRole::Candidate);

        $this->actingAs($admin)->get("/users/{$candidate->id}/edit")->assertForbidden();
    }

    public function test_profile_changes_are_audited_with_previous_and_new_values(): void
    {
        $admin = $this->userWithRole(SystemRole::SuperAdministrator);
        $target = $this->userWithRole(SystemRole::Instructor, ['name' => 'Old Name', 'username' => 'old.username']);

        $this->actingAs($admin)
            ->put("/users/{$target->id}", $this->updatePayload($target, ['name' => 'New Name']))
            ->assertRedirect(route('users.index'));

        $entry = AuditLog::query()->where('action', AuditAction::UserUpdated->value)->sole();
        $this->assertSame(['name' => 'Old Name'], $entry->old_values);
        $this->assertSame(['name' => 'New Name'], $entry->new_values);
    }

    public function test_saving_without_changes_records_nothing(): void
    {
        $admin = $this->userWithRole(SystemRole::SuperAdministrator);
        $target = $this->userWithRole(SystemRole::Instructor);

        $this->actingAs($admin)->put("/users/{$target->id}", $this->updatePayload($target));

        $this->assertSame(0, AuditLog::query()->where('auditable_id', $target->id)->count());
    }

    public function test_the_role_of_an_existing_account_cannot_be_changed(): void
    {
        $admin = $this->userWithRole(SystemRole::SuperAdministrator);
        $target = $this->userWithRole(SystemRole::Instructor);
        $this->storeSession($target);

        $this->actingAs($admin)->put("/users/{$target->id}", $this->updatePayload($target, [
            'name' => 'Renamed Instructor',
            'role_id' => $this->role(SystemRole::AcademicAdministrator)->id,
        ]))->assertSessionHasErrors(['role_id' => 'The role of an existing account cannot be changed.']);

        $fresh = $target->fresh();
        $this->assertSame($this->role(SystemRole::Instructor)->id, $fresh->role_id);
        $this->assertNotSame('Renamed Instructor', $fresh->name);
        $this->assertSame(0, AuditLog::query()->where('action', AuditAction::UserRoleChanged->value)->count());
        $this->assertSame(1, DB::table('sessions')->where('user_id', $target->id)->count());

        // Without a role in the request, other details still save and the role stays.
        $payload = $this->updatePayload($target, ['name' => 'Renamed Instructor']);
        unset($payload['role_id']);
        $this->actingAs($admin)->put("/users/{$target->id}", $payload)->assertSessionHasNoErrors();
        $this->assertSame('Renamed Instructor', $target->fresh()->name);
        $this->assertSame($this->role(SystemRole::Instructor)->id, $target->fresh()->role_id);
    }

    public function test_deactivation_is_audited_and_ends_the_users_sessions(): void
    {
        $admin = $this->userWithRole(SystemRole::SuperAdministrator);
        $target = $this->userWithRole(SystemRole::Instructor);
        $this->storeSession($target);

        $this->actingAs($admin)->put("/users/{$target->id}", $this->updatePayload($target, ['is_active' => false]));

        $this->assertFalse($target->fresh()->is_active);
        $this->assertDatabaseHas('audit_logs', ['action' => AuditAction::UserDeactivated->value, 'auditable_id' => $target->id]);
        $this->assertSame(0, DB::table('sessions')->where('user_id', $target->id)->count());
    }

    public function test_reactivation_is_audited(): void
    {
        $admin = $this->userWithRole(SystemRole::SuperAdministrator);
        $target = User::factory()->withRole(SystemRole::Instructor)->inactive()->create();

        $this->actingAs($admin)->put("/users/{$target->id}", $this->updatePayload($target, ['is_active' => true]));

        $this->assertTrue($target->fresh()->is_active);
        $this->assertDatabaseHas('audit_logs', ['action' => AuditAction::UserReactivated->value, 'auditable_id' => $target->id]);
    }

    public function test_password_reset_is_audited_without_the_password_and_ends_sessions(): void
    {
        $admin = $this->userWithRole(SystemRole::SuperAdministrator);
        $target = $this->userWithRole(SystemRole::Instructor);
        $this->storeSession($target);

        $this->actingAs($admin)->put("/users/{$target->id}", $this->updatePayload($target, [
            'password' => 'a-brand-new-password',
            'password_confirmation' => 'a-brand-new-password',
        ]));

        $this->assertTrue(Hash::check('a-brand-new-password', $target->fresh()->password));
        $entry = AuditLog::query()->where('action', AuditAction::UserPasswordReset->value)->sole();
        $this->assertNull($entry->old_values);
        $this->assertNull($entry->new_values);
        $this->assertSame(0, DB::table('sessions')->where('user_id', $target->id)->count());
    }

    public function test_administrator_cannot_lock_themselves_out(): void
    {
        $admin = $this->userWithRole(SystemRole::SuperAdministrator);

        $this->actingAs($admin)
            ->put("/users/{$admin->id}", $this->updatePayload($admin, [
                'role_id' => $this->role(SystemRole::Instructor)->id,
                'is_active' => false,
                'password' => 'a-brand-new-password',
                'password_confirmation' => 'a-brand-new-password',
            ]))
            ->assertSessionHasErrors([
                'role_id' => 'The role of an existing account cannot be changed.',
                'is_active' => 'You cannot deactivate your own account.',
                'password' => 'Change your own password from the Account page.',
            ]);

        $fresh = $admin->fresh();
        $this->assertTrue($fresh->is_active);
        $this->assertSame($admin->role_id, $fresh->role_id);
    }

    public function test_administrator_can_update_their_own_profile(): void
    {
        $admin = $this->userWithRole(SystemRole::SuperAdministrator);

        $this->actingAs($admin)
            ->put("/users/{$admin->id}", $this->updatePayload($admin, ['name' => 'Renamed Admin']))
            ->assertSessionHasNoErrors();

        $this->assertSame('Renamed Admin', $admin->fresh()->name);
    }

    public function test_role_and_status_cannot_be_mass_assigned(): void
    {
        $user = new User(['name' => 'Someone', 'username' => 'someone', 'password' => 'secret-secret']);

        $this->expectException(MassAssignmentException::class);

        $user->fill(['role_id' => 1, 'is_active' => false]);
    }

    private function role(SystemRole $role): Role
    {
        return Role::query()->where('code', $role->value)->sole();
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function validPayload(array $overrides = []): array
    {
        $payload = [
            'name' => 'New Instructor',
            'username' => 'new.instructor',
            'email' => '',
            'role_id' => $this->role(SystemRole::Instructor)->id,
            'is_active' => true,
            'password' => 'correct-horse-battery',
            'password_confirmation' => 'correct-horse-battery',
            ...$overrides,
        ];

        // Instructors teach at one of the four campuses, chosen on the form.
        if ($payload['role_id'] === $this->role(SystemRole::Instructor)->id && ! array_key_exists('campus_id', $overrides)) {
            $payload['campus_id'] = $this->campusId;
        }

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function updatePayload(User $user, array $overrides = []): array
    {
        return [
            'name' => $user->name,
            'username' => $user->username,
            'email' => $user->email ?? '',
            'role_id' => $user->role_id,
            'is_active' => $user->is_active,
            'password' => '',
            'password_confirmation' => '',
            ...$overrides,
        ];
    }

    private function storeSession(User $user): void
    {
        DB::table('sessions')->insert([
            'id' => 'session-'.$user->id,
            'user_id' => $user->id,
            'ip_address' => '10.0.0.10',
            'user_agent' => 'Test Browser',
            'payload' => '',
            'last_activity' => now()->timestamp,
        ]);
    }
}
