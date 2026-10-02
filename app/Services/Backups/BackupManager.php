<?php

namespace App\Services\Backups;

use App\Enums\AuditAction;
use App\Models\User;
use App\Services\AuditLogger;
use Carbon\CarbonImmutable;
use FilesystemIterator;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use Throwable;
use ZipArchive;

/**
 * Encrypted backups of the whole system (owner request, 2026-10-02): the
 * database and every uploaded file, nightly, kept in rotation, tested by a
 * weekly restore into a scratch database, and restorable from the Backups
 * page or the command line.
 *
 * Everything about backups lives in files under config('backups.path'),
 * never in the database, so it survives a restore: each backup is
 * `<id>.octbak` (encrypted) with `<id>.json` (its description, no personal
 * data); `history.jsonl` lists every backup, test and restore; requests from
 * the Backups page wait in `pending.json` until the scheduler runs them
 * (`backups:process`, every minute). Only one operation runs at a time.
 *
 * A restore first takes a safety backup, puts the system in maintenance
 * mode, replaces the database and the uploaded files, runs migrations (so an
 * older backup fits the current version) and comes back up; if it fails it
 * puts the safety backup back.
 */
class BackupManager
{
    public const EXTENSION = 'octbak';

    public const KINDS = ['daily', 'weekly', 'monthly', 'manual', 'before-restore'];

    public const REQUESTS = ['backup', 'verify', 'restore'];

    /** @var resource|null */
    private $lock = null;

    /** Set when a failed restore could not be undone: the system then stays in maintenance mode. */
    private bool $rollBackFailed = false;

    public function __construct(
        private readonly DatabaseTools $database,
        private readonly AuditLogger $audit,
    ) {}

    public function isConfigured(): bool
    {
        return is_string(config('backups.passphrase')) && config('backups.passphrase') !== '';
    }

    public function path(): string
    {
        $path = rtrim((string) config('backups.path'), '\\/');
        File::ensureDirectoryExists($path);

        return $path;
    }

    // ------------------------------------------------------------------
    // Backups on disk
    // ------------------------------------------------------------------

    /**
     * Every backup, newest first.
     *
     * @return list<array<string, mixed>>
     */
    public function backups(): array
    {
        $records = [];
        foreach (glob($this->path().DIRECTORY_SEPARATOR.'*.json') ?: [] as $file) {
            $record = json_decode((string) file_get_contents($file), true);
            if (is_array($record) && isset($record['id']) && is_file($this->file((string) $record['id']))) {
                $records[] = $record;
            }
        }
        usort($records, fn (array $a, array $b): int => strcmp((string) $b['created_at'], (string) $a['created_at']));

        return $records;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(string $id): ?array
    {
        if (preg_match('/^\d{8}-\d{6}-[a-z-]+(-[a-z0-9]{4})?$/', $id) !== 1) {
            return null;
        }
        $json = $this->path().DIRECTORY_SEPARATOR.$id.'.json';
        if (! is_file($json) || ! is_file($this->file($id))) {
            return null;
        }
        $record = json_decode((string) file_get_contents($json), true);

        return is_array($record) ? $record : null;
    }

    // ------------------------------------------------------------------
    // Operations (each runs alone)
    // ------------------------------------------------------------------

    /**
     * Backs up the database and every uploaded file now.
     *
     * @param  'daily'|'weekly'|'monthly'|'manual'|'before-restore'|'scheduled'  $kind
     * @param  array{id: int|null, name: string}|null  $actor
     * @return array<string, mixed>
     */
    public function create(string $kind, ?array $actor = null): array
    {
        return $this->exclusively(function () use ($kind, $actor): array {
            $record = $this->doCreate($kind === 'scheduled' ? $this->scheduledKind() : $kind, $actor);
            $this->prune();

            return $record;
        });
    }

    /**
     * Restore test: decrypts the backup, checks every part, and restores its
     * database into a scratch database that is dropped afterwards.
     *
     * @param  array{id: int|null, name: string}|null  $actor
     * @return array<string, mixed>
     */
    public function verify(?string $id = null, ?array $actor = null): array
    {
        return $this->exclusively(fn (): array => $this->doVerify($id ?? ($this->backups()[0]['id'] ?? throw new RuntimeException('There is no backup to test yet.')), $actor));
    }

    /**
     * Replaces the whole system with a backup.
     *
     * @param  array{id: int|null, name: string}|null  $actor
     * @return array<string, mixed>
     */
    public function restore(string $id, ?array $actor = null): array
    {
        return $this->exclusively(fn (): array => $this->doRestore($id, $actor));
    }

    /** Deletes backups beyond the number kept for each kind (and their copies). */
    public function prune(): void
    {
        $keep = (array) config('backups.keep');
        $byKind = [];
        foreach ($this->backups() as $record) {
            $byKind[$record['kind']][] = $record;
        }
        foreach ($byKind as $kind => $records) {
            foreach (array_slice($records, max(0, (int) ($keep[$kind] ?? 10))) as $old) {
                foreach ([$this->path(), config('backups.copy_path')] as $folder) {
                    if (is_string($folder) && $folder !== '') {
                        File::delete([rtrim($folder, '\\/').DIRECTORY_SEPARATOR.$old['id'].'.'.self::EXTENSION, rtrim($folder, '\\/').DIRECTORY_SEPARATOR.$old['id'].'.json']);
                    }
                }
                $this->log('pruned', 'ok', "Removed the old {$kind} backup of ".$this->label($old).'.', $old['id']);
            }
        }
    }

    // ------------------------------------------------------------------
    // Requests from the Backups page, run by the scheduler
    // ------------------------------------------------------------------

    /**
     * @param  'backup'|'verify'|'restore'  $type
     */
    public function request(string $type, ?string $backupId, User $actor): void
    {
        $this->withPending(function (array $pending) use ($type, $backupId, $actor): array {
            foreach ($pending as $request) {
                if ($request['type'] === $type && ($request['backup_id'] ?? null) === $backupId) {
                    throw new RuntimeException('This request is already waiting to start.');
                }
            }
            $pending[] = [
                'id' => (string) Str::uuid(),
                'type' => $type,
                'backup_id' => $backupId,
                'actor' => ['id' => $actor->id, 'name' => $actor->name],
                'requested_at' => now()->toIso8601String(),
            ];

            return $pending;
        });

        $this->log('requested', 'ok', match ($type) {
            'backup' => 'Back Up Now requested.',
            'verify' => 'Restore test requested.',
            'restore' => 'Restore of the backup of '.$this->label($this->find((string) $backupId) ?? ['created_at' => null]).' requested.',
        }, $backupId, ['id' => $actor->id, 'name' => $actor->name]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function pending(): array
    {
        $file = $this->path().DIRECTORY_SEPARATOR.'pending.json';
        $pending = is_file($file) ? json_decode((string) file_get_contents($file), true) : [];

        return is_array($pending) ? array_values($pending) : [];
    }

    /** @return array<string, mixed>|null */
    public function running(): ?array
    {
        $file = $this->path().DIRECTORY_SEPARATOR.'running.json';
        $running = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;

        return is_array($running) ? $running : null;
    }

    /**
     * Runs the requests waiting from the Backups page, oldest first. Returns
     * how many ran; 0 when another operation is running.
     */
    public function processPending(): int
    {
        $this->heartbeat();
        if (! $this->tryLock()) {
            return 0;
        }

        $ran = 0;
        try {
            while (($request = $this->takeNext()) !== null) {
                $ran++;
                File::put($this->path().DIRECTORY_SEPARATOR.'running.json', json_encode([...$request, 'started_at' => now()->toIso8601String()]));
                try {
                    $this->runRequest($request);
                } catch (Throwable $exception) {
                    // Already in the history; keep running the other requests.
                    Log::error('Backup request failed', ['type' => $request['type'], 'message' => $exception->getMessage()]);
                } finally {
                    File::delete($this->path().DIRECTORY_SEPARATOR.'running.json');
                }
            }
        } finally {
            $this->unlock();
        }

        return $ran;
    }

    /**
     * @param  array<string, mixed>  $request
     */
    private function runRequest(array $request): void
    {
        if ($request['type'] === 'backup') {
            $this->doCreate('manual', $request['actor']);
            $this->prune();
        } elseif ($request['type'] === 'verify') {
            $latest = $request['backup_id'] ?? ($this->backups()[0]['id'] ?? null);
            if ($latest === null) {
                $this->log('verified', 'failed', 'There is no backup to test yet.', null, $request['actor']);

                return;
            }
            $this->doVerify($latest, $request['actor']);
        } elseif ($request['type'] === 'restore') {
            $this->doRestore((string) $request['backup_id'], $request['actor']);
        }
    }

    public function heartbeat(): void
    {
        File::put($this->path().DIRECTORY_SEPARATOR.'heartbeat', now()->toIso8601String());
    }

    /**
     * The history of backups, tests and restores, newest first.
     *
     * @return list<array<string, mixed>>
     */
    public function history(int $limit = 30): array
    {
        $file = $this->path().DIRECTORY_SEPARATOR.'history.jsonl';
        $lines = is_file($file) ? array_reverse(array_filter(explode(PHP_EOL, (string) file_get_contents($file)))) : [];

        return array_values(array_filter(array_map(fn (string $line): ?array => json_decode($line, true), array_slice($lines, 0, $limit))));
    }

    /**
     * @param  'requested'|'created'|'verified'|'restored'|'pruned'  $event
     * @param  'ok'|'failed'  $result
     * @param  array{id: int|null, name: string}|null  $actor
     */
    private function log(string $event, string $result, string $message, ?string $backupId = null, ?array $actor = null): void
    {
        File::append($this->path().DIRECTORY_SEPARATOR.'history.jsonl', json_encode([
            'at' => now()->toIso8601String(),
            'event' => $event,
            'result' => $result,
            'message' => $message,
            'backup_id' => $backupId,
            'actor' => $actor['name'] ?? null,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE).PHP_EOL);
    }

    /**
     * Everything the Backups page shows.
     *
     * @return array<string, mixed>
     */
    public function status(): array
    {
        $timezone = (string) config('institution.timezone');
        $backups = $this->backups();
        $last = $backups[0] ?? null;
        $lastVerified = collect($backups)->filter(fn (array $record): bool => ($record['verify'] ?? null) !== null)->sortByDesc('verify.at')->first();
        $heartbeatFile = $this->path().DIRECTORY_SEPARATOR.'heartbeat';
        $heartbeat = is_file($heartbeatFile) ? CarbonImmutable::parse(trim((string) file_get_contents($heartbeatFile))) : null;
        $schedulerRunning = $heartbeat !== null && $heartbeat->greaterThan(now()->subMinutes(3));
        $history = $this->history();
        $free = @disk_free_space($this->path());

        $warnings = [];
        if (! $this->isConfigured()) {
            $warnings[] = 'Backups are off: the backup passphrase (BACKUP_PASSPHRASE) is not set on the server.';
        }
        if (! $schedulerRunning) {
            $warnings[] = 'The scheduler is not running, so nightly backups and requests from this page do not start. The server must run "php artisan schedule:run" every minute (see the README).';
        }
        if ($last === null) {
            $warnings[] = 'There is no backup yet.';
        } elseif (CarbonImmutable::parse($last['created_at'])->lessThan(now()->subHours((int) config('backups.max_age_hours')))) {
            $warnings[] = 'The last backup is more than '.config('backups.max_age_hours').' hours old.';
        }
        $lastFailure = collect($history)->first(fn (array $entry): bool => $entry['result'] === 'failed');
        $lastSuccess = collect($history)->first(fn (array $entry): bool => in_array($entry['event'], ['created', 'restored', 'verified'], true) && $entry['result'] !== 'failed');
        if ($lastFailure !== null && ($lastSuccess === null || $lastFailure['at'] > $lastSuccess['at'])) {
            $warnings[] = 'The last operation failed: '.$lastFailure['message'];
        }
        if ($lastVerified === null) {
            $warnings[] = 'No backup has been test-restored yet.';
        } elseif (($lastVerified['verify']['result'] ?? null) === 'failed') {
            $warnings[] = 'The last restore test failed: '.$lastVerified['verify']['message'];
        }
        if ($last !== null && ($last['copy'] ?? null) !== null && $last['copy'] !== 'ok') {
            $warnings[] = 'The last backup was not copied to the second location: '.$last['copy'];
        }
        if (is_float($free) && $last !== null && $free < 3 * (int) $last['size_bytes']) {
            $warnings[] = 'The backup disk is almost full.';
        }

        [$hour, $minute] = array_map('intval', explode(':', (string) config('backups.daily_at')) + [1 => 0]);
        $next = now($timezone)->setTime($hour, $minute);
        if ($next->isPast()) {
            $next = $next->addDay();
        }

        return [
            'configured' => $this->isConfigured(),
            'path' => $this->path(),
            'copyPath' => config('backups.copy_path'),
            'freeBytes' => is_float($free) ? (int) $free : null,
            'schedulerRunning' => $schedulerRunning,
            'heartbeatAt' => $heartbeat?->toIso8601String(),
            'nextBackupAt' => $next->toIso8601String(),
            'warnings' => $warnings,
            'backups' => $backups,
            'lastVerify' => $lastVerified === null ? null : ['backupId' => $lastVerified['id'], ...$lastVerified['verify']],
            'pending' => $this->pending(),
            'running' => $this->running(),
            'history' => $history,
        ];
    }

    // ------------------------------------------------------------------
    // The work itself (callers hold the lock)
    // ------------------------------------------------------------------

    /**
     * @param  array{id: int|null, name: string}|null  $actor
     * @return array<string, mixed>
     */
    private function doCreate(string $kind, ?array $actor): array
    {
        $cipher = $this->cipher();
        $id = $this->newId($kind);
        $work = $this->work($id);
        $target = $this->file($id);

        try {
            $sql = $work.DIRECTORY_SEPARATOR.'database.sql';
            $this->database->dump($sql);
            $tables = $this->database->tableCounts();

            $zipPath = $work.DIRECTORY_SEPARATOR.'archive.zip';
            $zip = new ZipArchive;
            if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                throw new RuntimeException('The backup archive could not be created.');
            }
            $zip->addFile($sql, 'database.sql');
            $fileCount = 0;
            $fileBytes = 0;
            foreach ((array) config('backups.folders') as $name => $folder) {
                $zip->addEmptyDir('files/'.$name);
                foreach ($this->filesIn((string) $folder) as $relative => $absolute) {
                    $zip->addFile($absolute, 'files/'.$name.'/'.$relative);
                    $fileCount++;
                    $fileBytes += (int) filesize($absolute);
                }
            }
            $manifest = [
                'format' => 1,
                'id' => $id,
                'kind' => $kind,
                'created_at' => now()->toIso8601String(),
                'database' => ['name' => config('database.connections.'.config('database.default').'.database'), 'sha256' => hash_file('sha256', $sql), 'tables' => $tables],
                'files' => ['count' => $fileCount, 'bytes' => $fileBytes, 'folders' => array_keys((array) config('backups.folders'))],
            ];
            $zip->addFromString('manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            if ($zip->close() !== true) {
                throw new RuntimeException('The backup archive could not be written. Is the disk full?');
            }

            $cipher->encrypt($zipPath, $target.'.part');
            File::move($target.'.part', $target);

            // Read it back once: it must decrypt and open.
            $check = $work.DIRECTORY_SEPARATOR.'check.zip';
            $cipher->decrypt($target, $check);
            $this->openArchive($check)->close();

            $record = [
                'id' => $id,
                'kind' => $kind,
                'created_at' => $manifest['created_at'],
                'size_bytes' => (int) filesize($target),
                'sha256' => hash_file('sha256', $target),
                'database_tables' => count($tables),
                'database_rows' => array_sum($tables),
                'files' => $fileCount,
                'file_bytes' => $fileBytes,
                'created_by' => $actor['name'] ?? 'Schedule',
                'copy' => null,
                'verify' => null,
            ];
            $record['copy'] = $this->copyToSecondLocation($target, $record);
            $this->saveRecord($record);
            $this->log('created', 'ok', ucfirst($kind).' backup created ('.$this->size($record['size_bytes']).', '.$fileCount.' files).', $id, $actor);
            $this->auditSafely(AuditAction::BackupCreated, ['backup' => $id, 'kind' => $kind, 'size_bytes' => $record['size_bytes']], $actor);

            return $record;
        } catch (Throwable $exception) {
            File::delete([$target, $target.'.part']);
            $this->log('created', 'failed', 'Backup failed: '.$exception->getMessage(), $id, $actor);
            $this->auditSafely(AuditAction::BackupFailed, ['backup' => $id, 'kind' => $kind], $actor, $exception->getMessage());

            throw $exception;
        } finally {
            File::deleteDirectory($work);
        }
    }

    /**
     * @param  array{id: int|null, name: string}|null  $actor
     * @return array<string, mixed>
     */
    private function doVerify(string $id, ?array $actor): array
    {
        $record = $this->find($id) ?? throw new RuntimeException('That backup no longer exists.');
        $work = $this->work('verify-'.$id);
        $scratch = (string) (config('backups.verify_database') ?: config('database.connections.'.config('database.default').'.database').'_restore_check');
        $created = false;

        try {
            $manifest = $this->unpack($record, $work, $work.DIRECTORY_SEPARATOR.'files');
            $extracted = iterator_count($this->filesIn($work.DIRECTORY_SEPARATOR.'files'));
            if ($extracted !== (int) $manifest['files']['count']) {
                throw new RuntimeException("The backup holds {$extracted} files instead of {$manifest['files']['count']}.");
            }

            try {
                $this->database->dropDatabase($scratch);
                $this->database->createDatabase($scratch);
                $created = true;
            } catch (Throwable $exception) {
                $result = ['result' => 'partial', 'at' => now()->toIso8601String(), 'message' => 'Files and database dump checked; the test restore into a scratch database was skipped because the database user cannot create one ('.Str::limit($exception->getMessage(), 120).').'];

                return $this->finishVerify($record, $result, $actor);
            }

            $this->database->import($work.DIRECTORY_SEPARATOR.'database.sql', $scratch);
            $restored = $this->database->tableCounts($scratch);
            $missing = array_diff(array_keys($manifest['database']['tables']), array_keys($restored));
            if ($missing !== []) {
                throw new RuntimeException('Tables missing after the test restore: '.implode(', ', $missing).'.');
            }
            if (($restored['migrations'] ?? null) !== ($manifest['database']['tables']['migrations'] ?? null)) {
                throw new RuntimeException('The database version after the test restore does not match the backup.');
            }

            return $this->finishVerify($record, [
                'result' => 'passed',
                'at' => now()->toIso8601String(),
                'message' => 'Test restore passed: '.count($restored).' tables, '.array_sum($restored).' rows, '.$extracted.' files.',
            ], $actor);
        } catch (Throwable $exception) {
            $this->finishVerify($record, ['result' => 'failed', 'at' => now()->toIso8601String(), 'message' => $exception->getMessage()], $actor);

            throw $exception;
        } finally {
            if ($created) {
                try {
                    $this->database->dropDatabase($scratch);
                } catch (Throwable) {
                    // A leftover scratch database is harmless; the next test drops it.
                }
            }
            File::deleteDirectory($work);
        }
    }

    /**
     * @param  array{id: int|null, name: string}|null  $actor
     * @return array<string, mixed>
     */
    private function doRestore(string $id, ?array $actor): array
    {
        $record = $this->find($id) ?? throw new RuntimeException('That backup no longer exists.');
        $work = $this->work('restore-'.$id);
        $incoming = storage_path('app/.restore-incoming-'.$id);
        $previous = storage_path('app/.restore-previous-'.$id);
        $wasDown = app()->isDownForMaintenance();
        $safety = null;
        $databaseReplaced = false;
        $filesSwapped = [];
        $this->rollBackFailed = false;

        try {
            // Unpack and check everything before touching the live system.
            $this->unpack($record, $work, $incoming);
            $safety = $this->doCreate('before-restore', $actor);

            if (! $wasDown) {
                Artisan::call('down', ['--retry' => 60]);
            }
            $databaseReplaced = true;
            $this->database->dropAllTables();
            $this->database->import($work.DIRECTORY_SEPARATOR.'database.sql');

            File::ensureDirectoryExists($previous);
            foreach ((array) config('backups.folders') as $name => $folder) {
                $from = $incoming.DIRECTORY_SEPARATOR.$name;
                if (is_dir($folder)) {
                    File::moveDirectory($folder, $previous.DIRECTORY_SEPARATOR.$name);
                }
                $filesSwapped[] = $name;
                is_dir($from) ? File::moveDirectory($from, $folder) : File::ensureDirectoryExists($folder);
            }

            // A backup from an older version gets the newer tables and columns.
            Artisan::call('migrate', ['--force' => true]);
            Artisan::call('cache:clear');

            $this->log('restored', 'ok', 'The system was restored to the backup of '.$this->label($record).'. Safety backup taken first: '.$safety['id'].'.', $id, $actor);
            $this->auditSafely(AuditAction::BackupRestored, ['backup' => $id, 'safety_backup' => $safety['id']], $actor);

            return $record;
        } catch (Throwable $exception) {
            $message = 'Restore failed: '.$exception->getMessage();
            if ($databaseReplaced && $safety !== null) {
                $message .= $this->rollBack($safety, $filesSwapped, $previous);
            }
            $this->log('restored', 'failed', $message, $id, $actor);
            $this->auditSafely(AuditAction::BackupRestoreFailed, ['backup' => $id], $actor, $exception->getMessage());

            throw $exception;
        } finally {
            File::deleteDirectory($work);
            File::deleteDirectory($incoming);
            // After a failed roll-back the system stays down and keeps the previous files for IT.
            if (! $this->rollBackFailed) {
                if (! $wasDown) {
                    Artisan::call('up');
                }
                File::deleteDirectory($previous);
            }
        }
    }

    /**
     * Puts the safety backup back after a failed restore. Returns a sentence
     * for the history.
     *
     * @param  list<string>  $swapped
     */
    private function rollBack(array $safety, array $swapped, string $previous): string
    {
        try {
            $work = $this->work('rollback-'.$safety['id']);
            try {
                $this->unpack($safety, $work, null);
                $this->database->dropAllTables();
                $this->database->import($work.DIRECTORY_SEPARATOR.'database.sql');
            } finally {
                File::deleteDirectory($work);
            }
            foreach ($swapped as $name) {
                $folder = (string) config('backups.folders.'.$name);
                if (is_dir($previous.DIRECTORY_SEPARATOR.$name)) {
                    File::deleteDirectory($folder);
                    File::moveDirectory($previous.DIRECTORY_SEPARATOR.$name, $folder);
                }
            }
            Artisan::call('migrate', ['--force' => true]);

            return ' The system was put back as it was before the restore.';
        } catch (Throwable $exception) {
            $this->rollBackFailed = true;

            return ' Putting the system back also failed ('.$exception->getMessage().'); it stays in maintenance mode. On the server, run: php artisan backups:restore '.$safety['id'];
        }
    }

    /**
     * Decrypts a backup, checks it and unpacks the database dump into $work
     * and the files into $filesTarget (folders by name).
     *
     * @param  array<string, mixed>  $record
     * @return array<string, mixed> the manifest
     */
    private function unpack(array $record, string $work, ?string $filesTarget): array
    {
        $file = $this->file((string) $record['id']);
        if (hash_file('sha256', $file) !== $record['sha256']) {
            throw new RuntimeException('The backup file was changed or damaged since it was made.');
        }

        $zipPath = $work.DIRECTORY_SEPARATOR.'archive.zip';
        $this->cipher()->decrypt($file, $zipPath);
        $zip = $this->openArchive($zipPath);
        try {
            $manifest = json_decode((string) $zip->getFromName('manifest.json'), true);
            if (! is_array($manifest) || ($manifest['format'] ?? null) !== 1) {
                throw new RuntimeException('The backup has no valid manifest.');
            }
            if ($zip->extractTo($work, 'database.sql') !== true || hash_file('sha256', $work.DIRECTORY_SEPARATOR.'database.sql') !== $manifest['database']['sha256']) {
                throw new RuntimeException('The database dump in the backup is damaged.');
            }

            if ($filesTarget !== null) {
                File::deleteDirectory($filesTarget);
                File::ensureDirectoryExists($filesTarget);
                for ($index = 0; $index < $zip->numFiles; $index++) {
                    $name = (string) $zip->getNameIndex($index);
                    if (! str_starts_with($name, 'files/') || str_ends_with($name, '/')) {
                        continue;
                    }
                    $relative = substr($name, strlen('files/'));
                    if (str_contains($relative, '..') || str_starts_with($relative, '/')) {
                        throw new RuntimeException('The backup contains an unsafe file name.');
                    }
                    $destination = $filesTarget.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relative);
                    File::ensureDirectoryExists(dirname($destination));
                    $stream = $zip->getStream($name);
                    if ($stream === false || file_put_contents($destination, $stream) === false) {
                        throw new RuntimeException("The file {$relative} could not be unpacked.");
                    }
                    fclose($stream);
                }
                foreach ((array) $manifest['files']['folders'] as $folder) {
                    File::ensureDirectoryExists($filesTarget.DIRECTORY_SEPARATOR.$folder);
                }
            }
        } finally {
            $zip->close();
            File::delete($zipPath);
        }

        return $manifest;
    }

    /**
     * @param  array<string, mixed>  $record
     * @param  array<string, mixed>  $result
     * @param  array{id: int|null, name: string}|null  $actor
     * @return array<string, mixed>
     */
    private function finishVerify(array $record, array $result, ?array $actor): array
    {
        $record['verify'] = $result;
        $this->saveRecord($record);
        $this->log('verified', $result['result'] === 'failed' ? 'failed' : 'ok', $result['message'], $record['id'], $actor);
        $this->auditSafely(AuditAction::BackupTested, ['backup' => $record['id'], 'result' => $result['result']], $actor);

        return $record;
    }

    /**
     * @param  array<string, mixed>  $record
     */
    private function copyToSecondLocation(string $file, array $record): ?string
    {
        $copyPath = config('backups.copy_path');
        if (! is_string($copyPath) || $copyPath === '') {
            return null;
        }

        try {
            $folder = rtrim($copyPath, '\\/');
            File::ensureDirectoryExists($folder);
            if (! copy($file, $folder.DIRECTORY_SEPARATOR.basename($file))) {
                throw new RuntimeException('copy failed');
            }
            File::put($folder.DIRECTORY_SEPARATOR.$record['id'].'.json', json_encode([...$record, 'copy' => 'ok'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return 'ok';
        } catch (Throwable $exception) {
            return Str::limit($exception->getMessage(), 160);
        }
    }

    /**
     * Files under a folder, relative path (with /) => absolute path.
     *
     * @return \Generator<string, string>
     */
    private function filesIn(string $folder): \Generator
    {
        if (! is_dir($folder)) {
            return;
        }
        $base = rtrim(realpath($folder) ?: $folder, '\\/');
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $item) {
            if ($item->isFile()) {
                $absolute = $item->getPathname();
                yield str_replace('\\', '/', ltrim(substr($absolute, strlen($base)), '\\/')) => $absolute;
            }
        }
    }

    private function openArchive(string $path): ZipArchive
    {
        $zip = new ZipArchive;
        if ($zip->open($path, ZipArchive::RDONLY) !== true || $zip->locateName('manifest.json') === false) {
            throw new RuntimeException('The backup archive cannot be opened.');
        }

        return $zip;
    }

    /**
     * @param  array<string, mixed>  $record
     */
    private function saveRecord(array $record): void
    {
        File::put($this->path().DIRECTORY_SEPARATOR.$record['id'].'.json', json_encode($record, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    private function scheduledKind(): string
    {
        $today = now((string) config('institution.timezone'));

        return match (true) {
            $today->day === 1 => 'monthly',
            $today->isSunday() => 'weekly',
            default => 'daily',
        };
    }

    private function newId(string $kind): string
    {
        if (! in_array($kind, self::KINDS, true)) {
            throw new RuntimeException("Unknown kind of backup: {$kind}");
        }
        $id = now((string) config('institution.timezone'))->format('Ymd-His').'-'.$kind;

        return is_file($this->file($id)) ? $id.'-'.Str::lower(Str::random(4)) : $id;
    }

    private function file(string $id): string
    {
        return $this->path().DIRECTORY_SEPARATOR.$id.'.'.self::EXTENSION;
    }

    private function work(string $name): string
    {
        $work = $this->path().DIRECTORY_SEPARATOR.'.work'.DIRECTORY_SEPARATOR.$name;
        File::deleteDirectory($work);
        File::ensureDirectoryExists($work);

        return $work;
    }

    private function cipher(): BackupCipher
    {
        if (! $this->isConfigured()) {
            throw new RuntimeException('The backup passphrase (BACKUP_PASSPHRASE) is not set on the server.');
        }

        return new BackupCipher((string) config('backups.passphrase'));
    }

    /**
     * @param  array<string, mixed>  $record
     */
    private function label(array $record): string
    {
        return $record['created_at'] === null ? 'an unknown date' : CarbonImmutable::parse($record['created_at'])->timezone((string) config('institution.timezone'))->format('M j, Y g:i A');
    }

    private function size(int $bytes): string
    {
        return $bytes >= 1_048_576 ? round($bytes / 1_048_576, 1).' MB' : max(1, (int) round($bytes / 1024)).' KB';
    }

    /**
     * Audit entries go to the database, which a restore replaces; the history
     * file is the lasting record. Never fails the operation.
     *
     * @param  array<string, mixed>  $values
     * @param  array{id: int|null, name: string}|null  $actor
     */
    private function auditSafely(AuditAction $action, array $values, ?array $actor, ?string $reason = null): void
    {
        try {
            $user = ($actor['id'] ?? null) === null ? null : User::query()->find($actor['id']);
            $this->audit->record($action, null, newValues: [...$values, 'by' => $actor['name'] ?? 'Schedule'], reason: $reason === null ? null : Str::limit($reason, 500), actor: $user);
        } catch (Throwable $exception) {
            Log::warning('Backup audit entry not written', ['action' => $action->value, 'message' => $exception->getMessage()]);
        }
    }

    // ------------------------------------------------------------------
    // Locks
    // ------------------------------------------------------------------

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    private function exclusively(callable $callback): mixed
    {
        $this->heartbeat();
        $this->lock(false);
        try {
            return $callback();
        } finally {
            $this->unlock();
        }
    }

    private function tryLock(): bool
    {
        return $this->lock(true);
    }

    private function lock(bool $nonBlocking): bool
    {
        $this->lock = fopen($this->path().DIRECTORY_SEPARATOR.'.lock', 'c');
        if ($this->lock === false || ! flock($this->lock, $nonBlocking ? LOCK_EX | LOCK_NB : LOCK_EX)) {
            if (is_resource($this->lock)) {
                fclose($this->lock);
            }
            $this->lock = null;

            return false;
        }

        return true;
    }

    private function unlock(): void
    {
        if (is_resource($this->lock)) {
            flock($this->lock, LOCK_UN);
            fclose($this->lock);
        }
        $this->lock = null;
    }

    /**
     * Reads and rewrites pending.json under its own lock.
     *
     * @param  callable(list<array<string, mixed>>): list<array<string, mixed>>  $change
     */
    private function withPending(callable $change): void
    {
        $handle = fopen($this->path().DIRECTORY_SEPARATOR.'.pending.lock', 'c');
        flock($handle, LOCK_EX);
        try {
            $pending = $change($this->pending());
            File::put($this->path().DIRECTORY_SEPARATOR.'pending.json', json_encode(array_values($pending), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function takeNext(): ?array
    {
        $next = null;
        $this->withPending(function (array $pending) use (&$next): array {
            $next = array_shift($pending);

            return $pending;
        });

        return $next;
    }
}
