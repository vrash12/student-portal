<?php

namespace Tests\Feature\Backups;

use App\Enums\SystemRole;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\Backups\BackupCipher;
use App\Services\Backups\BackupManager;
use App\Services\Backups\DatabaseTools;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use RuntimeException;
use Tests\TestCase;
use ZipArchive;

/**
 * Encrypted backups of the whole system (owner request, 2026-10-02): the
 * database and every uploaded file, nightly, tested, and restorable from the
 * Backups page (Admin only, password and typed confirmation). The database
 * programs are replaced by FakeDatabaseTools; files are real, in a temporary
 * folder.
 */
class BackupTest extends TestCase
{
    private string $root;

    private FakeDatabaseTools $database;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = storage_path('framework/testing/backups-'.Str::random(8));
        File::ensureDirectoryExists($this->root.'/private/medical-documents');
        File::ensureDirectoryExists($this->root.'/public');
        File::put($this->root.'/private/medical-documents/certificate.pdf', '%PDF-1.4 original');
        File::put($this->root.'/public/logo.txt', 'logo');
        config([
            'backups.path' => $this->root.'/backups',
            'backups.copy_path' => null,
            'backups.passphrase' => 'test-passphrase-not-for-production',
            'backups.folders' => ['private' => $this->root.'/private', 'public' => $this->root.'/public'],
        ]);
        $this->database = new FakeDatabaseTools;
        $this->app->instance(DatabaseTools::class, $this->database);
        $this->admin = $this->userWithRole(SystemRole::SuperAdministrator, ['name' => 'System Admin']);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->root);
        parent::tearDown();
    }

    public function test_the_cipher_round_trips_and_refuses_a_wrong_passphrase_or_damage(): void
    {
        $plain = $this->root.'/plain.bin';
        $encrypted = $this->root.'/plain.octbak';
        $back = $this->root.'/back.bin';
        file_put_contents($plain, random_bytes(2_600_000));

        (new BackupCipher('right'))->encrypt($plain, $encrypted);
        $this->assertStringNotContainsString(substr((string) file_get_contents($plain), 0, 64), (string) file_get_contents($encrypted));
        (new BackupCipher('right'))->decrypt($encrypted, $back);
        $this->assertSame(hash_file('sha256', $plain), hash_file('sha256', $back));

        // An empty file works too.
        file_put_contents($plain, '');
        (new BackupCipher('right'))->encrypt($plain, $encrypted);
        (new BackupCipher('right'))->decrypt($encrypted, $back);
        $this->assertSame('', file_get_contents($back));

        file_put_contents($plain, str_repeat('medical data ', 200_000));
        (new BackupCipher('right'))->encrypt($plain, $encrypted);
        $this->assertDecryptFails('wrong', $encrypted, 'passphrase is wrong');

        $bytes = (string) file_get_contents($encrypted);
        file_put_contents($encrypted.'.changed', substr_replace($bytes, chr(ord($bytes[200]) ^ 1), 200, 1));
        $this->assertDecryptFails('right', $encrypted.'.changed', 'damaged');
        file_put_contents($encrypted.'.short', substr($bytes, 0, (int) (strlen($bytes) / 2)));
        $this->assertDecryptFails('right', $encrypted.'.short', 'incomplete');
    }

    public function test_a_backup_holds_the_database_and_every_file_encrypted(): void
    {
        $record = $this->manager()->create('manual', ['id' => $this->admin->id, 'name' => $this->admin->name]);

        $file = $this->root.'/backups/'.$record['id'].'.octbak';
        $this->assertFileExists($file);
        $this->assertFileExists($this->root.'/backups/'.$record['id'].'.json');
        $this->assertSame(2, $record['files']);
        $this->assertSame('System Admin', $record['created_by']);
        $this->assertStringNotContainsString('INSERT INTO users', (string) file_get_contents($file));

        $zip = $this->open($record['id']);
        $this->assertSame($this->database->current, $zip->getFromName('database.sql'));
        $this->assertSame('%PDF-1.4 original', $zip->getFromName('files/private/medical-documents/certificate.pdf'));
        $this->assertSame('logo', $zip->getFromName('files/public/logo.txt'));
        $manifest = json_decode((string) $zip->getFromName('manifest.json'), true);
        $this->assertSame(60, $manifest['database']['tables']['migrations']);
        $zip->close();

        $this->assertSame('created', $this->manager()->history()[0]['event']);
        $this->assertSame(1, AuditLog::query()->where('action', 'backup.created')->count());
        $this->assertSame([], glob($this->root.'/backups/.work/*') ?: []);
    }

    public function test_nightly_backups_are_daily_weekly_or_monthly_and_old_ones_are_removed(): void
    {
        config(['institution.timezone' => 'Asia/Manila']);
        $this->travelTo(now('Asia/Manila')->setDate(2026, 11, 1)->setTime(1, 0));
        $this->assertSame('monthly', $this->manager()->create('scheduled')['kind']);
        $this->travelTo(now('Asia/Manila')->setDate(2026, 11, 8)->setTime(1, 0));
        $this->assertSame('weekly', $this->manager()->create('scheduled')['kind']);
        $this->travelTo(now('Asia/Manila')->setDate(2026, 11, 10)->setTime(1, 0));
        $this->assertSame('daily', $this->manager()->create('scheduled')['kind']);

        config(['backups.keep.manual' => 2]);
        foreach ([1, 2, 3] as $minute) {
            $this->travelTo(now()->addMinutes($minute));
            $this->manager()->create('manual');
        }
        $manual = array_values(array_filter($this->manager()->backups(), fn (array $record): bool => $record['kind'] === 'manual'));
        $this->assertCount(2, $manual);
        $this->assertCount(5, $this->manager()->backups());
    }

    public function test_the_restore_test_reports_passed_partial_or_failed(): void
    {
        $record = $this->manager()->create('manual');

        $tested = $this->manager()->verify($record['id']);
        $this->assertSame('passed', $tested['verify']['result']);
        $this->assertNotNull($this->database->imports[0]['database']);
        $this->assertSame([], glob($this->root.'/backups/.work/*') ?: []);

        $this->database->refuseCreate = true;
        $this->assertSame('partial', $this->manager()->verify($record['id'])['verify']['result']);

        $this->database->refuseCreate = false;
        $this->database->failImport = true;
        try {
            $this->manager()->verify($record['id']);
            $this->fail('The restore test should fail.');
        } catch (RuntimeException) {
            $this->assertSame('failed', $this->manager()->find($record['id'])['verify']['result']);
        }

        // A changed backup file is refused before anything else.
        file_put_contents($this->root.'/backups/'.$record['id'].'.octbak', 'x', FILE_APPEND);
        $this->expectExceptionMessage('changed or damaged');
        $this->manager()->verify($record['id']);
    }

    public function test_a_restore_takes_a_safety_backup_and_puts_back_the_database_and_files(): void
    {
        $record = $this->manager()->create('manual');
        $backupSql = $this->database->current;

        // Changes made after the backup.
        $this->database->current = "-- fake dump\nINSERT INTO users VALUES (1, 'Changed');\n";
        File::put($this->root.'/private/medical-documents/certificate.pdf', '%PDF-1.4 changed');
        File::put($this->root.'/private/medical-documents/new.pdf', '%PDF-1.4 new');

        $this->manager()->restore($record['id'], ['id' => $this->admin->id, 'name' => $this->admin->name]);

        $this->assertSame($backupSql, $this->database->current);
        $this->assertSame(1, $this->database->dropped);
        $this->assertSame('%PDF-1.4 original', File::get($this->root.'/private/medical-documents/certificate.pdf'));
        $this->assertFileDoesNotExist($this->root.'/private/medical-documents/new.pdf');
        $this->assertFalse(app()->isDownForMaintenance());

        $safety = collect($this->manager()->backups())->firstWhere('kind', 'before-restore');
        $this->assertNotNull($safety);
        $zip = $this->open($safety['id']);
        $this->assertSame('%PDF-1.4 changed', $zip->getFromName('files/private/medical-documents/certificate.pdf'));
        $zip->close();
        $this->assertSame('restored', $this->manager()->history()[0]['event']);
        $this->assertSame([], glob(storage_path('app/.restore-*')) ?: []);
    }

    public function test_a_failed_restore_puts_the_system_back(): void
    {
        $record = $this->manager()->create('manual');
        $this->database->current = "-- fake dump\nINSERT INTO users VALUES (1, 'Current');\n";
        $current = $this->database->current;
        File::put($this->root.'/private/medical-documents/certificate.pdf', '%PDF-1.4 current');
        $this->database->failImport = true;

        try {
            $this->manager()->restore($record['id']);
            $this->fail('The restore should fail.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Simulated import failure.', $exception->getMessage());
        }

        $this->assertSame($current, $this->database->current);
        $this->assertSame('%PDF-1.4 current', File::get($this->root.'/private/medical-documents/certificate.pdf'));
        $this->assertFalse(app()->isDownForMaintenance());
        $last = $this->manager()->history()[0];
        $this->assertSame('failed', $last['result']);
        $this->assertStringContainsString('put back as it was', $last['message']);
    }

    public function test_the_backups_page_is_for_the_admin_only(): void
    {
        $this->actingAs($this->admin)->get(route('backups.index'))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('staff/backups/index')
                ->where('status.configured', true)
                ->has('backups', 0)
                ->where('confirmationWord', 'RESTORE'));

        foreach ([SystemRole::AcademicAdministrator, SystemRole::Instructor] as $role) {
            $user = $this->userWithRole($role);
            $this->actingAs($user)->get(route('backups.index'))->assertForbidden();
            $this->actingAs($user)->post(route('backups.store'))->assertForbidden();
        }

        // The dashboard warns the Admin, nobody else.
        $this->actingAs($this->admin)->get(route('dashboard'))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('backupWarnings', fn ($warnings) => count($warnings) > 0));
        $this->actingAs($this->userWithRole(SystemRole::Instructor))->get(route('dashboard'))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('backupWarnings', []));
    }

    public function test_requests_from_the_page_run_on_the_next_scheduler_minute(): void
    {
        $this->actingAs($this->admin)->post(route('backups.store'))->assertRedirect(route('backups.index'));
        $this->actingAs($this->admin)->post(route('backups.store'))->assertSessionHasErrors('backup');
        $this->assertSame('backup', $this->manager()->pending()[0]['type']);

        $this->artisan('backups:process')->assertSuccessful();
        $this->assertSame([], $this->manager()->pending());
        $backup = $this->manager()->backups()[0];
        $this->assertSame('manual', $backup['kind']);
        $this->assertSame('System Admin', $backup['created_by']);

        // A restore needs the typed word and the admin's password.
        $this->actingAs($this->admin)->post(route('backups.restore', $backup['id']), ['confirmation' => 'restore', 'password' => 'password'])->assertSessionHasErrors('confirmation');
        $this->actingAs($this->admin)->post(route('backups.restore', $backup['id']), ['confirmation' => 'RESTORE', 'password' => 'not-my-password'])->assertSessionHasErrors('password');
        $this->assertSame([], $this->manager()->pending());
        $this->actingAs($this->admin)->post(route('backups.restore', '20990101-000000-manual'), ['confirmation' => 'RESTORE', 'password' => 'password'])->assertNotFound();

        $this->actingAs($this->admin)->post(route('backups.restore', $backup['id']), ['confirmation' => 'RESTORE', 'password' => 'password'])->assertSessionHasNoErrors();
        $this->assertSame('restore', $this->manager()->pending()[0]['type']);
        $this->database->current = '-- changed';
        $this->artisan('backups:process')->assertSuccessful();
        $this->assertSame("-- fake dump\nINSERT INTO users VALUES (1, 'Original');\n", $this->database->current);
        $this->assertSame('restored', $this->manager()->history()[0]['event']);
    }

    public function test_nothing_starts_without_the_passphrase(): void
    {
        config(['backups.passphrase' => null]);

        $this->actingAs($this->admin)->get(route('backups.index'))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('status.configured', false)
                ->where('status.warnings.0', fn (string $warning) => str_contains($warning, 'BACKUP_PASSPHRASE')));
        $this->actingAs($this->admin)->post(route('backups.store'))->assertSessionHasErrors('backup');
        $this->expectExceptionMessage('BACKUP_PASSPHRASE');
        $this->manager()->create('manual');
    }

    private function manager(): BackupManager
    {
        return app(BackupManager::class);
    }

    private function open(string $id): ZipArchive
    {
        $zipPath = $this->root.'/'.$id.'.zip';
        (new BackupCipher((string) config('backups.passphrase')))->decrypt($this->root.'/backups/'.$id.'.octbak', $zipPath);
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($zipPath) === true);

        return $zip;
    }

    private function assertDecryptFails(string $passphrase, string $file, string $message): void
    {
        try {
            (new BackupCipher($passphrase))->decrypt($file, $this->root.'/out.bin');
            $this->fail('Decryption should fail.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString($message, $exception->getMessage());
        }
    }
}
