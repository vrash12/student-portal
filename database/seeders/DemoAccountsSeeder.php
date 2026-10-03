<?php

namespace Database\Seeders;

use App\Enums\Permission;
use App\Enums\SystemRole;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * Clearly fictional staff accounts for local development and demonstrations.
 * Candidate accounts are created with their records by DemoAcademicSeeder.
 * Instructors are on the South Campus; administrators see every campus.
 *
 * All accounts share the password from DEMO_ACCOUNT_PASSWORD, falling back
 * to the documented development default. Never runs in production.
 */
class DemoAccountsSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->isProduction()) {
            throw new RuntimeException('Demo accounts must not be seeded in production.');
        }

        $password = (string) config('demo.account_password');

        $staff = [
            ['admin', 'System Administrator', SystemRole::SuperAdministrator],
            ['academic.admin', 'Academic Administrator', SystemRole::AcademicAdministrator],
            ['instructor.alpha', 'Instructor Alpha', SystemRole::Instructor],
            ['instructor.bravo', 'Instructor Bravo', SystemRole::Instructor],
        ];

        foreach ($staff as [$username, $name, $role]) {
            $this->account($username, $name, $role, $password);
        }
    }

    private function account(string $username, string $name, SystemRole $systemRole, string $password): void
    {
        if (User::query()->where('username', $username)->exists()) {
            return;
        }

        $user = new User([
            'name' => $name,
            'username' => $username,
            'email' => null,
            'password' => $password,
        ]);
        $role = Role::query()->where('code', $systemRole->value)->firstOrFail();
        $user->role()->associate($role);
        if (in_array(Permission::TeachClasses, $systemRole->defaultPermissions(), true)) {
            $user->campus()->associate(DemoCampusSeeder::southCampus());
        }
        $user->is_active = true;
        $user->save();
    }
}
