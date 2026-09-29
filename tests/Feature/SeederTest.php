<?php

namespace Tests\Feature;

use App\Enums\Permission as PermissionCode;
use App\Enums\SystemRole;
use App\Models\AcademicPeriod;
use App\Models\Candidate;
use App\Models\ClassBatch;
use App\Models\InstructorAssignment;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Subject;
use App\Models\User;
use Database\Seeders\AccessControlSeeder;
use Database\Seeders\DemoAcademicSeeder;
use Database\Seeders\DemoAccountsSeeder;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
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
            $this->assertSame($systemRole->rank(), $role->rank);
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

        $this->assertEqualsCanonicalizing(
            array_map(fn (PermissionCode $permission): string => $permission->value, SystemRole::Instructor->defaultPermissions()),
            $instructor->fresh()->permissionCodes(),
        );
        $this->assertNotContains(PermissionCode::ManageUsers->value, $instructor->fresh()->permissionCodes());
    }

    public function test_demo_data_is_fictional_and_created_once(): void
    {
        $this->seed([DemoAccountsSeeder::class, DemoAcademicSeeder::class]);
        $counts = fn (): array => [
            User::query()->count(),
            Candidate::query()->count(),
            ClassBatch::query()->count(),
            Subject::query()->count(),
            InstructorAssignment::query()->count(),
        ];
        $before = $counts();

        $this->seed([DemoAccountsSeeder::class, DemoAcademicSeeder::class]);

        $this->assertSame($before, $counts());
        $this->assertDatabaseHas('users', ['username' => 'admin', 'name' => 'System Administrator']);
        $this->assertDatabaseHas('users', ['username' => 'instructor.alpha', 'name' => 'Instructor Alpha']);
        $this->assertSame([14, 10, 2, 4, 8], $before);
        $this->assertDatabaseHas('subjects', ['code' => 'SUBJ-1', 'name' => 'Subject 1']);
        $this->assertSame(1, AcademicPeriod::query()->active()->count());

        $candidate = Candidate::query()->with('user')->where('candidate_number', '2026-0001')->sole();
        $this->assertSame('2026-0001', $candidate->user->username);
        $this->assertSame('Candidate 001', $candidate->user->name);
        $this->assertSame(SystemRole::Candidate->value, $candidate->user->role->code);
    }

    /**
     * @return array<string, array{class-string}>
     */
    public static function demoSeeders(): array
    {
        return [
            'accounts' => [DemoAccountsSeeder::class],
            'academic structure' => [DemoAcademicSeeder::class],
        ];
    }

    /**
     * @param  class-string  $seeder
     */
    #[DataProvider('demoSeeders')]
    public function test_demo_data_is_never_seeded_in_production(string $seeder): void
    {
        $this->app->detectEnvironment(fn (): string => 'production');

        $this->expectException(RuntimeException::class);

        $this->app->make($seeder)->run();
    }
}
