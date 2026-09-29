<?php

namespace Tests;

use App\Enums\SystemRole;
use App\Models\User;
use Database\Seeders\AccessControlSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    use RefreshDatabase;

    /**
     * Roles and permissions are seeded once when the test database is migrated.
     */
    protected bool $seed = true;

    protected string $seeder = AccessControlSeeder::class;

    /**
     * Tests do not depend on compiled frontend assets. Inertia assertions
     * still verify that each rendered page component exists on disk.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    /**
     * RefreshDatabase drops every table in the connection's database. Refuse
     * to run unless that database is a dedicated test database, so a cached
     * or mistaken configuration can never wipe development data.
     */
    protected function beforeRefreshingDatabase(): void
    {
        $connection = config('database.default');
        $database = (string) config("database.connections.{$connection}.database");

        if (! str_ends_with($database, '_testing')) {
            throw new RuntimeException(
                "Refusing to refresh database [{$database}]. Tests must run against a database whose name ends in \"_testing\"."
            );
        }
    }

    protected function userWithRole(SystemRole $role, array $attributes = []): User
    {
        return User::factory()->withRole($role)->create($attributes);
    }
}
