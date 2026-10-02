<?php

use App\Services\Backups\BackupManager;
use App\Services\Examinations\CandidateAttemptService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('examinations:expire', function () {
    $count = app(CandidateAttemptService::class)->expireDue();
    $this->info("Reconciled {$count} expired attempts.");
})->purpose('Submit or expire attempts whose server deadline has passed');

Schedule::command('examinations:expire')->everyMinute()->withoutOverlapping();

// Backups (owner request, 2026-10-02): nightly backup, weekly restore test, and
// every minute the requests from the Backups page. Each command waits for any
// backup operation already running (one at a time, see BackupManager).
Artisan::command('backups:run {--kind=scheduled : scheduled (daily, weekly or monthly by date) or manual}', function (BackupManager $backups) {
    $kind = (string) $this->option('kind');
    $record = $backups->create($kind, $kind === 'scheduled' ? null : ['id' => null, 'name' => 'Server command']);
    $this->info("Backup {$record['id']} created ({$record['size_bytes']} bytes, {$record['files']} files).");
})->purpose('Back up the database and all uploaded files now (encrypted)');

Artisan::command('backups:verify {backup? : backup id; the newest when left out}', function (BackupManager $backups) {
    $record = $backups->verify($this->argument('backup'));
    $this->info($record['verify']['message']);
})->purpose('Test-restore a backup into a scratch database');

Artisan::command('backups:list', function (BackupManager $backups) {
    $this->table(['Backup', 'Kind', 'Size (bytes)', 'Files', 'Restore test'], array_map(fn (array $record): array => [
        $record['id'], $record['kind'], $record['size_bytes'], $record['files'], $record['verify']['result'] ?? '-',
    ], $backups->backups()));
})->purpose('List the backups on this server');

Artisan::command('backups:restore {backup : backup id (see backups:list)} {--force : do not ask for confirmation}', function (BackupManager $backups) {
    $id = (string) $this->argument('backup');
    if ($backups->find($id) === null) {
        $this->error("There is no backup {$id}.");

        return 1;
    }
    if (! $this->option('force') && ! $this->confirm("Replace the whole system (database and uploaded files) with backup {$id}? A safety backup is taken first.")) {
        return 1;
    }
    $backups->restore($id, ['id' => null, 'name' => 'Server command']);
    $this->info("Restored from {$id}.");

    return 0;
})->purpose('Restore the whole system from a backup');

Artisan::command('backups:process', function (BackupManager $backups) {
    $ran = $backups->processPending();
    if ($ran > 0) {
        $this->info("Ran {$ran} backup request(s).");
    }
})->purpose('Run the requests waiting from the Backups page');

$timezone = (string) config('institution.timezone');
Schedule::command('backups:process')->everyMinute();
Schedule::command('backups:run')->dailyAt((string) config('backups.daily_at'))->timezone($timezone);
Schedule::command('backups:verify')->weeklyOn((int) config('backups.verify_day'), (string) config('backups.verify_at'))->timezone($timezone);

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');
