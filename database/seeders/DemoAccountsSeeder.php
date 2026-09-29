<?php

namespace Database\Seeders;

use App\Enums\SystemRole;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * Clearly fictional demo accounts for local development and demonstrations.
 *
 * All accounts share the password from DEMO_ACCOUNT_PASSWORD, falling back
 * to the documented development default. Never runs in production.
 */
class DemoAccountsSeeder extends Seeder
{
    private const CANDIDATE_COUNT = 10;

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

        for ($number = 1; $number <= self::CANDIDATE_COUNT; $number++) {
            $padded = str_pad((string) $number, 3, '0', STR_PAD_LEFT);
            $this->account("candidate{$padded}", "Candidate {$padded}", SystemRole::Candidate, $password);
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
        $user->role()->associate(Role::query()->where('code', $systemRole->value)->firstOrFail());
        $user->is_active = true;
        $user->save();
    }
}
