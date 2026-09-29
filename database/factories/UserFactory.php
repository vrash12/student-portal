<?php

namespace Database\Factories;

use App\Enums\SystemRole;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;

/**
 * Requires the system roles to exist (run AccessControlSeeder first).
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

    private function roleId(SystemRole $role): int
    {
        return Role::query()->where('code', $role->value)->valueOrFail('id');
    }
}
