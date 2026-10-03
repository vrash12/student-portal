<?php

namespace Tests\Feature\Campuses;

use App\Enums\SystemRole;
use Database\Seeders\AccessControlSeeder;
use Illuminate\Database\Connection;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * The campuses migration on a database that already has data, as on the
 * live server: everything is attached to a new "Main Campus", teaching
 * staff join it, administrators keep seeing every campus, and running the
 * migration again (after a deploy that stopped half-way) changes nothing.
 * The next migration turns the Main Campus into the South Campus and adds
 * North, East and West: the four fixed campuses (owner decision 2026-10-04).
 *
 * Runs on its own scratch database: the migration changes tables, which
 * MariaDB cannot roll back inside the test transaction.
 */
class CampusMigrationTest extends TestCase
{
    private const CONNECTION = 'campus_backfill';

    private const DATABASE = 'academic_system_campus_backfill_testing';

    private string $defaultConnection;

    protected function setUp(): void
    {
        parent::setUp();

        $this->defaultConnection = (string) config('database.default');
        $settings = config("database.connections.{$this->defaultConnection}");
        config(['database.connections.'.self::CONNECTION => [...$settings, 'database' => self::DATABASE]]);
        // Creating a database ends any open transaction, so not on the test's own connection.
        config(['database.connections.'.self::CONNECTION.'_server' => [...$settings, 'database' => 'information_schema']]);
        $this->server()->statement('create database if not exists `'.self::DATABASE.'` character set utf8mb4 collate utf8mb4_unicode_ci');

        Artisan::call('migrate:fresh', ['--database' => self::CONNECTION, '--force' => true]);
        Artisan::call('db:seed', ['--class' => AccessControlSeeder::class, '--database' => self::CONNECTION, '--force' => true]);
        // The migration and the assertions below use the default connection.
        DB::setDefaultConnection(self::CONNECTION);
    }

    protected function tearDown(): void
    {
        DB::setDefaultConnection($this->defaultConnection);
        DB::purge(self::CONNECTION);
        $this->server()->statement('drop database if exists `'.self::DATABASE.'`');
        DB::purge(self::CONNECTION.'_server');

        parent::tearDown();
    }

    private function server(): Connection
    {
        return DB::connection(self::CONNECTION.'_server');
    }

    public function test_existing_records_join_a_main_campus(): void
    {
        $migration = $this->campusMigration();
        $migration->down();
        $this->assertFalse(Schema::hasTable('campuses'));

        $ids = $this->legacyData();
        $migration->up();

        $main = DB::table('campuses')->sole();
        $this->assertSame(['Main Campus', 'MAIN', 1], [$main->name, $main->code, (int) $main->is_active]);
        $this->assertSame((int) $main->id, (int) DB::table('class_batches')->where('id', $ids['class'])->value('campus_id'));
        $this->assertSame((int) $main->id, (int) DB::table('class_subjects')->where('id', $ids['offering'])->value('campus_id'));
        $this->assertSame((int) $main->id, (int) DB::table('instructor_assignments')->where('id', $ids['assignment'])->value('campus_id'));
        $this->assertSame((int) $main->id, (int) DB::table('candidates')->where('id', $ids['candidate'])->value('campus_id'));
        // A candidate without a class joins it too.
        $this->assertSame((int) $main->id, (int) DB::table('candidates')->where('id', $ids['unplaced'])->value('campus_id'));

        // Instructors join the campus; administrators and candidate accounts keep none.
        $this->assertSame((int) $main->id, (int) DB::table('users')->where('id', $ids['instructor'])->value('campus_id'));
        $this->assertNull(DB::table('users')->where('id', $ids['admin'])->value('campus_id'));
        $this->assertNull(DB::table('users')->where('id', $ids['candidateUser'])->value('campus_id'));

        // Audit entries about campus records belong to it; shared settings stay institution-wide.
        $this->assertSame((int) $main->id, (int) DB::table('audit_logs')->where('id', $ids['candidateEntry'])->value('campus_id'));
        $this->assertSame((int) $main->id, (int) DB::table('audit_logs')->where('id', $ids['instructorEntry'])->value('campus_id'));
        $this->assertNull(DB::table('audit_logs')->where('id', $ids['subjectEntry'])->value('campus_id'));

        // The Admin role holds campuses.manage again.
        $this->assertTrue(DB::table('permission_role')
            ->join('permissions', 'permissions.id', '=', 'permission_role.permission_id')
            ->join('roles', 'roles.id', '=', 'permission_role.role_id')
            ->where('permissions.code', 'campuses.manage')
            ->where('roles.code', SystemRole::SuperAdministrator->value)
            ->exists());

        // Running it again (a deploy that stopped half-way) changes nothing.
        $migration->up();
        $this->assertSame(1, DB::table('campuses')->count());
        $this->assertSame((int) $main->id, (int) DB::table('candidates')->where('id', $ids['candidate'])->value('campus_id'));

        // Then the four fixed campuses (owner decision 2026-10-04): the Main
        // Campus becomes the South Campus with all its records, and North,
        // East and West are added. Running it again changes nothing.
        $fourCampuses = $this->fourCampusesMigration();
        $fourCampuses->up();
        $fourCampuses->up();

        $this->assertSame(['SOUTH' => 'South Campus', 'NORTH' => 'North Campus', 'EAST' => 'East Campus', 'WEST' => 'West Campus'], $this->campuses());
        $this->assertSame('SOUTH', DB::table('campuses')->where('id', $main->id)->value('code'));
        $this->assertSame((int) $main->id, (int) DB::table('candidates')->where('id', $ids['candidate'])->value('campus_id'));
        $this->assertSame((int) $main->id, (int) DB::table('users')->where('id', $ids['instructor'])->value('campus_id'));
        $this->assertTrue($this->hasCodeCheck());
    }

    public function test_a_new_installation_gets_the_four_campuses(): void
    {
        $migration = $this->campusMigration();
        $migration->down();
        $migration->up();

        $this->assertTrue(Schema::hasTable('campuses'));
        $this->assertSame(0, DB::table('campuses')->count());

        $this->fourCampusesMigration()->up();

        $this->assertSame(['SOUTH' => 'South Campus', 'NORTH' => 'North Campus', 'EAST' => 'East Campus', 'WEST' => 'West Campus'], $this->campuses());
        $this->assertTrue($this->hasCodeCheck());
    }

    public function test_an_unused_extra_campus_is_removed_and_one_in_use_is_kept(): void
    {
        $migration = $this->campusMigration();
        $migration->down();
        $migration->up();

        $now = now();
        DB::table('campuses')->insert([
            ['name' => 'Spare Campus', 'code' => 'SPARE', 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'Annex', 'code' => 'ANNEX', 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
        ]);
        $annex = (int) DB::table('campuses')->where('code', 'ANNEX')->value('id');
        $period = DB::table('academic_periods')->insertGetId(['name' => '2026-2027', 'starts_on' => '2026-06-01', 'ends_on' => '2027-05-31', 'is_active' => false, 'created_at' => $now, 'updated_at' => $now]);
        DB::table('class_batches')->insert(['academic_period_id' => $period, 'campus_id' => $annex, 'name' => 'Class A', 'created_at' => $now, 'updated_at' => $now]);

        $this->fourCampusesMigration()->up();

        // The spare campus is gone; the annex has a class, so it stays and the rule waits.
        $this->assertSame(['SOUTH', 'NORTH', 'EAST', 'WEST', 'ANNEX'], array_keys($this->campuses()));
        $this->assertFalse($this->hasCodeCheck());
    }

    private function campusMigration(): Migration
    {
        return require database_path('migrations/2026_10_03_000700_create_campuses.php');
    }

    private function fourCampusesMigration(): Migration
    {
        return require database_path('migrations/2026_10_04_000100_fix_the_four_campuses.php');
    }

    /**
     * Campus names by code, the four in their fixed order first.
     *
     * @return array<string, string>
     */
    private function campuses(): array
    {
        $order = ['SOUTH', 'NORTH', 'EAST', 'WEST'];

        return DB::table('campuses')->get(['code', 'name'])
            ->sortBy(fn (object $campus): int => ($index = array_search($campus->code, $order, true)) === false ? 99 : $index)
            ->mapWithKeys(fn (object $campus): array => [$campus->code => $campus->name])
            ->all();
    }

    private function hasCodeCheck(): bool
    {
        return DB::table('information_schema.table_constraints')
            ->where('constraint_schema', DB::getDatabaseName())
            ->where('table_name', 'campuses')
            ->where('constraint_name', 'campuses_code_check')
            ->exists();
    }

    /**
     * Records as they were before campuses existed.
     *
     * @return array<string, int>
     */
    private function legacyData(): array
    {
        $now = now();
        $role = fn (SystemRole $role): int => (int) DB::table('roles')->where('code', $role->value)->value('id');
        $user = fn (string $username, SystemRole $systemRole): int => DB::table('users')->insertGetId([
            'name' => $username, 'username' => $username, 'password' => 'not-a-real-hash', 'role_id' => $role($systemRole),
            'is_active' => true, 'created_at' => $now, 'updated_at' => $now,
        ]);

        $admin = $user('admin', SystemRole::SuperAdministrator);
        $instructor = $user('instructor1', SystemRole::Instructor);
        $candidateUser = $user('student01', SystemRole::Candidate);
        $unplacedUser = $user('student02', SystemRole::Candidate);

        $period = DB::table('academic_periods')->insertGetId(['name' => '2026-2027', 'starts_on' => '2026-08-03', 'ends_on' => '2027-07-30', 'created_at' => $now, 'updated_at' => $now]);
        $class = DB::table('class_batches')->insertGetId(['academic_period_id' => $period, 'name' => 'Class A', 'created_at' => $now, 'updated_at' => $now]);
        $subject = DB::table('subjects')->insertGetId(['code' => 'SUBJ-1', 'name' => 'Subject 1', 'created_at' => $now, 'updated_at' => $now]);
        $offering = DB::table('class_subjects')->insertGetId(['class_batch_id' => $class, 'subject_id' => $subject, 'created_at' => $now, 'updated_at' => $now]);
        $assignment = DB::table('instructor_assignments')->insertGetId(['class_subject_id' => $offering, 'instructor_id' => $instructor, 'created_at' => $now, 'updated_at' => $now]);
        $candidate = DB::table('candidates')->insertGetId([
            'user_id' => $candidateUser, 'candidate_number' => 'student01', 'qr_token' => 'tokenstudent01', 'first_name' => 'Student', 'last_name' => '01',
            'class_batch_id' => $class, 'created_at' => $now, 'updated_at' => $now,
        ]);
        $unplaced = DB::table('candidates')->insertGetId([
            'user_id' => $unplacedUser, 'candidate_number' => 'student02', 'qr_token' => 'tokenstudent02', 'first_name' => 'Student', 'last_name' => '02',
            'class_batch_id' => null, 'created_at' => $now, 'updated_at' => $now,
        ]);

        $entry = fn (string $action, string $type, int $id): int => DB::table('audit_logs')->insertGetId([
            'actor_id' => $admin, 'action' => $action, 'auditable_type' => $type, 'auditable_id' => $id, 'created_at' => $now,
        ]);

        return [
            'admin' => $admin, 'instructor' => $instructor, 'candidateUser' => $candidateUser,
            'class' => $class, 'offering' => $offering, 'assignment' => $assignment, 'candidate' => $candidate, 'unplaced' => $unplaced,
            'candidateEntry' => $entry('candidate.updated', 'candidate', $candidate),
            'instructorEntry' => $entry('user.updated', 'user', $instructor),
            'subjectEntry' => $entry('subject.updated', 'subject', $subject),
        ];
    }
}
