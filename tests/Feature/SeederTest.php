<?php

namespace Tests\Feature;

use App\Enums\Permission as PermissionCode;
use App\Enums\SystemRole;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\AccessControlSeeder;
use Database\Seeders\DemoAccountsSeeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

class SeederTest extends TestCase
{
    public function test_every_system_role_exists_with_its_default_permissions(): void
    {
        foreach (SystemRole::cases() as $systemRole) {
            $role = Role::query()->with('permissions')->where('code', $systemRole->value)->sole();

            $this->assertTrue($role->is_system);
            $this->assertSame($systemRole->label(), $role->name);
            $this->assertEqualsCanonicalizing(
                array_map(fn (PermissionCode $permission): string => $permission->value, $systemRole->defaultPermissions()),
                $role->permissionCodes(),
            );
        }
    }

    public function test_access_control_seeder_is_idempotent(): void
    {
        $counts = fn (): array => [Role::query()->count(), Permission::query()->count(), DB::table('permission_role')->count()];
        $before = $counts();

        $this->seed(AccessControlSeeder::class);

        $this->assertSame($before, $counts());
    }

    public function test_access_control_seeder_removes_permissions_that_no_longer_exist(): void
    {
        $stale = Permission::query()->create(['code' => 'legacy.permission', 'name' => 'Legacy', 'group' => 'Legacy']);
        Role::query()->where('code', SystemRole::SuperAdministrator->value)->sole()->permissions()->attach($stale);

        $this->seed(AccessControlSeeder::class);

        $this->assertDatabaseMissing('permissions', ['code' => 'legacy.permission']);
        $this->assertDatabaseMissing('permission_role', ['permission_id' => $stale->id]);
    }

    public function test_system_roles_are_reset_to_their_default_permissions(): void
    {
        $instructor = Role::query()->where('code', SystemRole::Instructor->value)->sole();
        $instructor->permissions()->attach(Permission::query()->where('code', PermissionCode::ManageUsers->value)->sole());

        $this->seed(AccessControlSeeder::class);

        $this->assertSame([PermissionCode::AccessStaffArea->value], $instructor->fresh()->permissionCodes());
    }

    public function test_demo_accounts_are_fictional_and_created_once(): void
    {
        $this->seed(DemoAccountsSeeder::class);
        $count = User::query()->count();

        $this->seed(DemoAccountsSeeder::class);

        $this->assertSame($count, User::query()->count());
        $this->assertDatabaseHas('users', ['username' => 'admin', 'name' => 'System Administrator']);
        $this->assertDatabaseHas('users', ['username' => 'instructor.alpha', 'name' => 'Instructor Alpha']);
        $this->assertSame(10, User::query()->whereRelation('role', 'code', SystemRole::Candidate->value)->count());
        $this->assertDatabaseHas('users', ['username' => 'candidate001', 'name' => 'Candidate 001']);
    }

    public function test_demo_accounts_are_never_seeded_in_production(): void
    {
        $this->app->detectEnvironment(fn (): string => 'production');

        $this->expectException(RuntimeException::class);

        $this->app->make(DemoAccountsSeeder::class)->run();
    }
}
