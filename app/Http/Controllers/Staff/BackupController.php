<?php

namespace App\Http\Controllers\Staff;

use App\Http\Controllers\Controller;
use App\Services\Backups\BackupManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

/**
 * The Backups page (owner request, 2026-10-02; Admin only, backups.manage):
 * the state of the encrypted nightly backups, Back Up Now, Test Restore Now,
 * and restoring the whole system from a backup after the admin re-enters
 * their password and types RESTORE. The page only places requests; the
 * scheduler runs them within a minute (BackupManager::processPending), so a
 * long backup or restore never depends on a browser staying open.
 */
class BackupController extends Controller
{
    public const CONFIRMATION = 'RESTORE';

    public function __construct(private readonly BackupManager $backups) {}

    public function index(): Response
    {
        $status = $this->backups->status();

        return Inertia::render('staff/backups/index', [
            'status' => [
                'configured' => $status['configured'],
                'path' => $status['path'],
                'copyPath' => $status['copyPath'],
                'freeBytes' => $status['freeBytes'],
                'schedulerRunning' => $status['schedulerRunning'],
                'heartbeatAt' => $status['heartbeatAt'],
                'nextBackupAt' => $status['nextBackupAt'],
                'warnings' => $status['warnings'],
                'lastVerify' => $status['lastVerify'],
            ],
            'backups' => array_map(fn (array $record): array => [
                'id' => $record['id'],
                'kind' => $record['kind'],
                'createdAt' => $record['created_at'],
                'sizeBytes' => $record['size_bytes'],
                'files' => $record['files'],
                'tables' => $record['database_tables'],
                'createdBy' => $record['created_by'],
                'copied' => $record['copy'],
                'verify' => $record['verify'],
            ], $status['backups']),
            'pending' => array_map(fn (array $request): array => [
                'type' => $request['type'],
                'backupId' => $request['backup_id'],
                'requestedBy' => $request['actor']['name'] ?? null,
                'requestedAt' => $request['requested_at'],
            ], $status['pending']),
            'running' => $status['running'] === null ? null : [
                'type' => $status['running']['type'],
                'backupId' => $status['running']['backup_id'],
                'startedAt' => $status['running']['started_at'] ?? null,
            ],
            'history' => $status['history'],
            'schedule' => [
                'dailyAt' => config('backups.daily_at'),
                'verifyDay' => (int) config('backups.verify_day'),
                'verifyAt' => config('backups.verify_at'),
                'keep' => config('backups.keep'),
            ],
            'confirmationWord' => self::CONFIRMATION,
        ]);
    }

    /** Back Up Now. */
    public function store(Request $request): RedirectResponse
    {
        return $this->place('backup', null, $request, 'Back Up Now requested. It starts within a minute; this page shows it when it is done.');
    }

    /** Test Restore Now (the newest backup). */
    public function verify(Request $request): RedirectResponse
    {
        return $this->place('verify', null, $request, 'Restore test requested. It starts within a minute.');
    }

    public function restore(Request $request, string $backup): RedirectResponse
    {
        abort_if($this->backups->find($backup) === null, 404);
        $request->validate([
            'confirmation' => ['required', 'string', 'in:'.self::CONFIRMATION],
            'password' => ['required', 'string', 'current_password'],
        ], [
            'confirmation.required' => 'Type '.self::CONFIRMATION.' to confirm.',
            'confirmation.in' => 'Type '.self::CONFIRMATION.' in capital letters to confirm.',
            'password.required' => 'Enter your password.',
            'password.current_password' => 'This is not your password.',
        ]);

        return $this->place('restore', $backup, $request, 'Restore requested. Within a minute the system shows a maintenance page while it restores, and everyone, including you, is signed out.');
    }

    /**
     * @param  'backup'|'verify'|'restore'  $type
     */
    private function place(string $type, ?string $backupId, Request $request, string $message): RedirectResponse
    {
        if (! $this->backups->isConfigured()) {
            throw ValidationException::withMessages(['backup' => 'Backups are off: the backup passphrase (BACKUP_PASSPHRASE) is not set on the server.']);
        }

        try {
            $this->backups->request($type, $backupId, $request->user());
        } catch (RuntimeException $exception) {
            throw ValidationException::withMessages(['backup' => $exception->getMessage()]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => $message]);

        return redirect()->route('backups.index');
    }
}
