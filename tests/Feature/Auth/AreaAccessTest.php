<?php

namespace Tests\Feature\Auth;

use App\Enums\SystemRole;
use App\Models\Candidate;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Route protection is enforced on the server for every area and role.
 */
class AreaAccessTest extends TestCase
{
    /**
     * @return array<string, array{string}>
     */
    public static function protectedPages(): array
    {
        return [
            'home' => ['/'],
            'dashboard' => ['/dashboard'],
            'users' => ['/users'],
            'account password' => ['/account/password'],
            'my classes' => ['/my-classes'],
            'examination portal' => ['/portal'],
        ];
    }

    /**
     * @return array<string, array{string}>
     */
    public static function staffPages(): array
    {
        return [
            'dashboard' => ['/dashboard'],
            'users' => ['/users'],
            'create user' => ['/users/create'],
            'account password' => ['/account/password'],
        ];
    }

    /**
     * @return array<string, array{string}>
     */
    public static function administrationPages(): array
    {
        return [
            'users' => ['/users'],
            'create user' => ['/users/create'],
        ];
    }

    /**
     * @return array<string, array{SystemRole}>
     */
    public static function staffRoles(): array
    {
        return [
            'super administrator' => [SystemRole::SuperAdministrator],
            'academic administrator' => [SystemRole::AcademicAdministrator],
            'instructor' => [SystemRole::Instructor],
        ];
    }

    #[DataProvider('protectedPages')]
    public function test_guests_are_redirected_to_sign_in(string $url): void
    {
        $this->get($url)->assertRedirect(route('login'));
    }

    #[DataProvider('staffPages')]
    public function test_candidates_cannot_open_staff_pages(string $url): void
    {
        $this->actingAs($this->userWithRole(SystemRole::Candidate))
            ->get($url)
            ->assertForbidden()
            ->assertInertia(fn (Assert $page) => $page->component('errors/error')->where('status', 403));
    }

    #[DataProvider('staffRoles')]
    public function test_staff_cannot_open_the_examination_portal(SystemRole $role): void
    {
        $this->actingAs($this->userWithRole($role))
            ->get('/portal')
            ->assertForbidden();
    }

    #[DataProvider('administrationPages')]
    public function test_instructors_cannot_open_administration_pages(string $url): void
    {
        $this->actingAs($this->userWithRole(SystemRole::Instructor))
            ->get($url)
            ->assertForbidden();
    }

    #[DataProvider('staffRoles')]
    public function test_every_staff_role_can_open_the_dashboard(SystemRole $role): void
    {
        $this->actingAs($this->userWithRole($role))
            ->get('/dashboard')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('staff/dashboard'));
    }

    public function test_candidates_can_open_the_examination_portal(): void
    {
        $candidate = Candidate::factory()->create();

        $this->actingAs($candidate->user)
            ->get('/portal')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('portal/home'));
    }

    public function test_academic_administrators_can_manage_users(): void
    {
        $user = $this->userWithRole(SystemRole::AcademicAdministrator);

        $this->actingAs($user)->get('/users')->assertOk();
        $this->actingAs($user)->get('/users/create')->assertOk();
    }

    /**
     * The read-only Roles & Permissions page was removed (owner request,
     * 2026-10-03), together with the permission that guarded it.
     */
    public function test_the_roles_and_permissions_page_no_longer_exists(): void
    {
        $admin = $this->userWithRole(SystemRole::SuperAdministrator);

        $this->actingAs($admin)->get('/roles')->assertNotFound();
        $this->assertFalse(Permission::query()->where('code', 'roles.view')->exists());
        $this->assertNotContains('roles.view', $admin->permissionCodes());
    }

    public function test_removing_the_roles_permission_also_removes_its_grants_from_existing_databases(): void
    {
        $permissionId = DB::table('permissions')->insertGetId([
            'code' => 'roles.view',
            'name' => 'View roles and permissions',
            'description' => 'View roles and the permissions each role grants.',
            'group' => 'Administration',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $adminRole = Role::query()->where('code', SystemRole::SuperAdministrator->value)->firstOrFail();
        DB::table('permission_role')->insert(['role_id' => $adminRole->id, 'permission_id' => $permissionId]);
        $grantsBefore = DB::table('permission_role')->where('role_id', $adminRole->id)->count();

        $migration = require database_path('migrations/2026_10_03_000100_remove_roles_view_permission.php');
        $migration->up();

        $this->assertFalse(DB::table('permissions')->where('code', 'roles.view')->exists());
        $this->assertFalse(DB::table('permission_role')->where('permission_id', $permissionId)->exists());
        // Only that one grant goes; the Admin keeps every other permission.
        $this->assertSame($grantsBefore - 1, DB::table('permission_role')->where('role_id', $adminRole->id)->count());

        $migration->down();
        $restored = DB::table('permissions')->where('code', 'roles.view')->value('id');
        $this->assertNotNull($restored);
        $this->assertTrue(DB::table('permission_role')->where(['role_id' => $adminRole->id, 'permission_id' => $restored])->exists());
    }

    public function test_super_administrators_can_open_every_staff_page(): void
    {
        $user = $this->userWithRole(SystemRole::SuperAdministrator);

        foreach (array_column(self::staffPages(), 0) as $url) {
            $this->actingAs($user)->get($url)->assertOk();
        }
    }

    public function test_account_without_area_access_is_forbidden_from_home(): void
    {
        $role = Role::query()->create(['code' => 'no_access', 'name' => 'No Access']);
        $user = User::factory()->create(['role_id' => $role->id]);

        $this->actingAs($user)->get('/')->assertForbidden();
    }

    public function test_deactivated_user_is_signed_out_on_their_next_request(): void
    {
        $user = $this->userWithRole(SystemRole::Instructor);
        $this->actingAs($user);

        $user->forceFill(['is_active' => false])->save();

        $this->get('/dashboard')
            ->assertRedirect(route('login'))
            ->assertInertiaFlash('toast.type', 'error');

        $this->assertGuest();
    }

    public function test_unknown_pages_render_the_not_found_page_inside_the_users_shell(): void
    {
        $this->actingAs($this->userWithRole(SystemRole::Instructor))
            ->get('/this-page-does-not-exist')
            ->assertNotFound()
            ->assertInertia(fn (Assert $page) => $page
                ->component('errors/error')
                ->where('status', 404)
                ->where('auth.user.username', fn (string $username) => $username !== ''));
    }

    public function test_shared_props_contain_only_the_users_own_permissions_and_no_secrets(): void
    {
        $this->actingAs($this->userWithRole(SystemRole::Instructor))
            ->get('/dashboard')
            ->assertInertia(fn (Assert $page) => $page
                ->where('auth.permissions', fn ($permissions) => collect($permissions)->sort()->values()->all() === ['academic_monitoring.view', 'announcements.manage', 'attendance.manage', 'classes.teach', 'conduct.manage', 'examinations.manage', 'fitness.manage', 'fitness.view', 'grades.record', 'question_bank.manage', 'reports.view', 'schedule.view', 'staff_area.access'])
                ->missing('auth.user.password')
                ->missing('auth.user.remember_token')
                ->missing('auth.user.email'));
    }
}
