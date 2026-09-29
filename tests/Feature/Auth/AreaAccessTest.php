<?php

namespace Tests\Feature\Auth;

use App\Enums\SystemRole;
use App\Models\Role;
use App\Models\User;
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
            'roles' => ['/roles'],
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
            'roles' => ['/roles'],
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
            'roles' => ['/roles'],
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
        $this->actingAs($this->userWithRole(SystemRole::Candidate))
            ->get('/portal')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('portal/home'));
    }

    public function test_academic_administrators_can_manage_users_but_not_view_roles(): void
    {
        $user = $this->userWithRole(SystemRole::AcademicAdministrator);

        $this->actingAs($user)->get('/users')->assertOk();
        $this->actingAs($user)->get('/users/create')->assertOk();
        $this->actingAs($user)->get('/roles')->assertForbidden();
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
                ->where('auth.permissions', fn ($permissions) => collect($permissions)->sort()->values()->all() === ['classes.teach', 'grades.record', 'staff_area.access'])
                ->missing('auth.user.password')
                ->missing('auth.user.remember_token')
                ->missing('auth.user.email'));
    }
}
