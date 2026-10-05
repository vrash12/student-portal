<?php

namespace Database\Factories;

use App\Enums\SystemRole;
use App\Models\Campus;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;

/**
 * Requires the system roles to exist (run AccessControlSeeder first).
 *
 * Teaching staff belong to the shared default campus unless a test chooses
 * one; other accounts (administrators, candidates) have no campus, so
 * administrators see every campus.
 *
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'username' => fake()->unique()->userName(),
            'email' => null,
            'password' => static::$password ??= Hash::make('password'),
            'role_id' => fn (): int => $this->roleId(SystemRole::Instructor),
            'campus_id' => fn (array $attributes): ?int => $this->requiresCampus((int) $attributes['role_id'])
                ? CampusFactory::defaultCampusId()
                : null,
            'is_active' => true,
        ];
    }

    public function withRole(SystemRole $role): static
    {
        return $this->state(fn (): array => ['role_id' => $this->roleId($role)]);
    }

    public function inactive(): static
    {
        return $this->state(fn (): array => ['is_active' => false]);
    }

    /** An account limited to the campus (or, with null, one that sees every campus). */
    public function onCampus(?Campus $campus): static
    {
        return $this->state(fn (): array => ['campus_id' => $campus?->id]);
    }

    private function roleId(SystemRole $role): int
    {
        return Role::query()->where('code', $role->value)->valueOrFail('id');
    }

    /** Instructors and dietitians belong to one campus (Role::requiresCampus). */
    private function requiresCampus(int $roleId): bool
    {
        return (bool) Role::query()->with('permissions')->find($roleId)?->requiresCampus();
    }
}
