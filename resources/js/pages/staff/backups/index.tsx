import { Head, router, useForm, usePage } from '@inertiajs/react';
import { DatabaseBackup, FlaskConical, History, LoaderCircle, RotateCcw, ShieldCheck } from 'lucide-react';
import { useEffect, useState, type FormEvent } from 'react';
import { Alert } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Dialog } from '@/components/ui/dialog';
import { EmptyState } from '@/components/ui/empty-state';
import { FormField, PasswordInput, TextInput } from '@/components/ui/form-field';
import { PageHeader } from '@/components/ui/page-header';
import { Panel } from '@/components/ui/panel';
import { StatusBadge, type StatusTone } from '@/components/ui/status-badge';
import { Table, TableBody, TableHead, Td, Th, Tr } from '@/components/ui/table';
import { useDateFormatter } from '@/lib/format';
import { routes } from '@/lib/routes';

type BackupKind = 'daily' | 'weekly' | 'monthly' | 'manual' | 'before-restore';

interface VerifyResult {
    result: 'passed' | 'partial' | 'failed';
    at: string;
    message: string;
}

interface BackupRow {
    id: string;
    kind: BackupKind;
    createdAt: string;
    sizeBytes: number;
    files: number;
    tables: number;
    createdBy: string;
    /** "ok", a problem, or null when no second location is set. */
    copied: string | null;
    verify: VerifyResult | null;
}

interface HistoryEntry {
    at: string;
    event: 'requested' | 'created' | 'verified' | 'restored' | 'pruned';
    result: 'ok' | 'failed';
    message: string;
    backup_id: string | null;
    actor: string | null;
}

interface BackupsProps {
    status: {
        configured: boolean;
        path: string;
        copyPath: string | null;
        freeBytes: number | null;
        schedulerRunning: boolean;
        heartbeatAt: string | null;
        nextBackupAt: string;
        warnings: string[];
        lastVerify: (VerifyResult & { backupId: string }) | null;
    };
    backups: BackupRow[];
    pending: Array<{ type: 'backup' | 'verify' | 'restore'; backupId: string | null; requestedBy: string | null; requestedAt: string }>;
    running: { type: 'backup' | 'verify' | 'restore'; backupId: string | null; startedAt: string | null } | null;
    history: HistoryEntry[];
    schedule: { dailyAt: string; verifyDay: number; verifyAt: string; keep: Record<string, number> };
    confirmationWord: string;
}

const KIND_LABEL: Record<BackupKind, string> = {
    daily: 'Daily',
    weekly: 'Weekly',
    monthly: 'Monthly',
    manual: 'Back Up Now',
    'before-restore': 'Before a restore',
};

const REQUEST_LABEL = { backup: 'Back Up Now', verify: 'Restore test', restore: 'Restore' } as const;

const DAYS = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];

const VERIFY_TONE: Record<VerifyResult['result'], StatusTone> = { passed: 'success', partial: 'warning', failed: 'danger' };

const VERIFY_LABEL: Record<VerifyResult['result'], string> = { passed: 'Test passed', partial: 'Partly tested', failed: 'Test failed' };

/** "2.4 MB", "830 KB", "1.2 GB". */
function size(bytes: number | null): string {
    if (bytes === null) {
        return 'Unknown';
    }
    if (bytes >= 1024 ** 3) {
        return `${(bytes / 1024 ** 3).toFixed(1)} GB`;
    }

    return bytes >= 1024 ** 2 ? `${(bytes / 1024 ** 2).toFixed(1)} MB` : `${Math.max(1, Math.round(bytes / 1024))} KB`;
}

/**
 * Encrypted backups of the whole system (owner request, 2026-10-02): the
 * database and every uploaded file, every night, with a weekly restore test.
 * The page places requests; the server starts them within a minute.
 */
export default function Backups({ status, backups, pending, running, history, schedule, confirmationWord }: BackupsProps) {
    const formatDate = useDateFormatter();
    const errors = usePage().props.errors as Record<string, string | undefined>;
    const [restoring, setRestoring] = useState<BackupRow | null>(null);
    const [busy, setBusy] = useState<'backup' | 'verify' | null>(null);
    const working = running !== null || pending.length > 0;

    // While something waits or runs, refresh the page data every 10 seconds.
    useEffect(() => {
        if (!working) {
            return;
        }
        const timer = window.setInterval(() => router.reload({ only: ['status', 'backups', 'pending', 'running', 'history'] }), 10_000);

        return () => window.clearInterval(timer);
    }, [working]);

    const place = (type: 'backup' | 'verify') => {
        router.post(type === 'backup' ? routes.backups.store() : routes.backups.verify(), {}, { preserveScroll: true, onStart: () => setBusy(type), onFinish: () => setBusy(null) });
    };
    const waiting = (type: 'backup' | 'verify') => pending.some((request) => request.type === type) || running?.type === type;
    const last = backups[0] ?? null;

    return (
        <>
            <Head title="Backups" />

            <PageHeader
                title="Backups"
                description={`Encrypted copies of the whole system: the database and every uploaded file. A backup is made every night at ${schedule.dailyAt}, and every ${DAYS[schedule.verifyDay] ?? 'week'} at ${schedule.verifyAt} the newest one is test-restored.`}
                actions={
                    <>
                        <Button
                            variant="secondary"
                            icon={<FlaskConical className="size-4" aria-hidden="true" />}
                            loading={busy === 'verify'}
                            disabled={!status.configured || waiting('verify') || backups.length === 0}
                            onClick={() => place('verify')}
                        >
                            Test Restore Now
                        </Button>
                        <Button
                            icon={<DatabaseBackup className="size-4" aria-hidden="true" />}
                            loading={busy === 'backup'}
                            disabled={!status.configured || waiting('backup')}
                            onClick={() => place('backup')}
                        >
                            Back Up Now
                        </Button>
                    </>
                }
            />

            <div className="flex flex-col gap-6">
                {errors.backup !== undefined && (
                    <Alert tone="danger" title="Nothing was started">
                        {errors.backup}
                    </Alert>
                )}

                {status.warnings.length > 0 && (
                    <Alert tone="warning" title="Backups need attention">
                        <ul className="list-disc space-y-1 pl-5">
                            {status.warnings.map((warning) => (
                                <li key={warning}>{warning}</li>
                            ))}
                        </ul>
                    </Alert>
                )}

                {working && (
                    <Alert tone="info" title={running !== null ? `${REQUEST_LABEL[running.type]} is running` : 'Waiting to start'}>
                        <p className="flex items-center gap-2">
                            <LoaderCircle className="size-4 animate-spin" aria-hidden="true" />
                            {running !== null
                                ? `Started ${running.startedAt === null ? '' : formatDate.dateTime(running.startedAt)}. This page updates by itself.`
                                : `${pending.map((request) => `${REQUEST_LABEL[request.type]} (requested by ${request.requestedBy ?? 'someone'})`).join(', ')}. The server starts it within a minute.`}
                        </p>
                    </Alert>
                )}

                <dl className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                    <Summary label="Last backup" value={last === null ? 'None yet' : formatDate.dateTime(last.createdAt)} hint={last === null ? 'Use Back Up Now to make the first one.' : `${size(last.sizeBytes)} · ${last.files} files · ${last.tables} tables`} />
                    <Summary label="Next backup" value={formatDate.dateTime(status.nextBackupAt)} hint={status.schedulerRunning ? 'The scheduler is running.' : 'The scheduler is not running.'} tone={status.schedulerRunning ? undefined : 'danger'} />
                    <Summary
                        label="Last restore test"
                        value={status.lastVerify === null ? 'Not tested yet' : VERIFY_LABEL[status.lastVerify.result]}
                        hint={status.lastVerify === null ? 'Use Test Restore Now.' : formatDate.dateTime(status.lastVerify.at)}
                        tone={status.lastVerify === null ? 'warning' : status.lastVerify.result === 'failed' ? 'danger' : undefined}
                    />
                    <Summary label="Free space" value={size(status.freeBytes)} hint={`Kept: ${schedule.keep.daily} daily, ${schedule.keep.weekly} weekly, ${schedule.keep.monthly} monthly`} />
                </dl>

                <Panel title="Stored Backups" description={`Stored in ${status.path}${status.copyPath ? `, copied to ${status.copyPath}` : ''}. Each file is encrypted with the backup passphrase kept by IT.`} bodyClassName={backups.length === 0 ? undefined : 'p-0'}>
                    {backups.length === 0 ? (
                        <EmptyState icon={DatabaseBackup} headingLevel="h3" title="No backups yet" description="The first nightly backup runs tonight, or use Back Up Now." />
                    ) : (
                        <Table caption="Backups, newest first" className="min-w-[56rem]">
                            <TableHead>
                                <Th>Made</Th>
                                <Th>Kind</Th>
                                <Th align="right">Size</Th>
                                <Th align="right">Files</Th>
                                <Th>Restore test</Th>
                                <Th align="right">
                                    <span className="sr-only">Actions</span>
                                </Th>
                            </TableHead>
                            <TableBody>
                                {backups.map((backup) => (
                                    <Tr key={backup.id}>
                                        <Td className="whitespace-nowrap text-ink">
                                            <span className="font-medium">{formatDate.dateTime(backup.createdAt)}</span>
                                            <span className="block text-xs text-ink-muted">
                                                By {backup.createdBy}
                                                {backup.copied !== null && (backup.copied === 'ok' ? ' · second copy saved' : ' · second copy failed')}
                                            </span>
                                        </Td>
                                        <Td>{KIND_LABEL[backup.kind]}</Td>
                                        <Td align="right" numeric>
                                            {size(backup.sizeBytes)}
                                        </Td>
                                        <Td align="right" numeric>
                                            {backup.files}
                                        </Td>
                                        <Td>
                                            {backup.verify === null ? (
                                                <span className="text-sm text-ink-muted">Not tested</span>
                                            ) : (
                                                <StatusBadge tone={VERIFY_TONE[backup.verify.result]}>{VERIFY_LABEL[backup.verify.result]}</StatusBadge>
                                            )}
                                        </Td>
                                        <Td align="right">
                                            <Button
                                                variant="secondary"
                                                size="sm"
                                                icon={<RotateCcw className="size-4" aria-hidden="true" />}
                                                disabled={!status.configured || working}
                                                onClick={() => setRestoring(backup)}
                                            >
                                                Restore<span className="sr-only"> the backup of {formatDate.dateTime(backup.createdAt)}</span>
                                            </Button>
                                        </Td>
                                    </Tr>
                                ))}
                            </TableBody>
                        </Table>
                    )}
                </Panel>

                <Panel title="History" description="Every backup, restore test and restore, newest first. Kept with the backups, so it survives a restore.">
                    {history.length === 0 ? (
                        <p className="text-sm text-ink-muted">Nothing yet.</p>
                    ) : (
                        <ol className="flex flex-col divide-y divide-line">
                            {history.map((entry, index) => (
                                <li key={`${entry.at}-${index}`} className="flex flex-col gap-1 py-2.5 sm:flex-row sm:items-start sm:gap-4">
                                    <span className="w-44 shrink-0 text-sm whitespace-nowrap text-ink-muted tabular-nums">{formatDate.dateTime(entry.at)}</span>
                                    <span className="flex min-w-0 flex-1 items-start gap-2 text-sm text-ink">
                                        {entry.result === 'failed' ? (
                                            <StatusBadge tone="danger">Failed</StatusBadge>
                                        ) : entry.event === 'restored' ? (
                                            <ShieldCheck className="mt-0.5 size-4 shrink-0 text-primary-700" aria-hidden="true" />
                                        ) : (
                                            <History className="mt-0.5 size-4 shrink-0 text-ink-muted" aria-hidden="true" />
                                        )}
                                        <span className="min-w-0">
                                            {entry.message}
                                            {entry.actor !== null && <span className="text-ink-muted"> · {entry.actor}</span>}
                                        </span>
                                    </span>
                                </li>
                            ))}
                        </ol>
                    )}
                </Panel>
            </div>

            <RestoreDialog backup={restoring} confirmationWord={confirmationWord} onClose={() => setRestoring(null)} />
        </>
    );
}

function Summary({ label, value, hint, tone }: { label: string; value: string; hint: string; tone?: 'warning' | 'danger' }) {
    return (
        <div className="rounded-xl border border-line-box bg-surface px-4 py-3">
            <dt className="text-sm text-ink-muted">{label}</dt>
            <dd className={tone === 'danger' ? 'mt-1 text-lg font-semibold text-danger-fg' : tone === 'warning' ? 'mt-1 text-lg font-semibold text-warning-fg' : 'mt-1 text-lg font-semibold text-ink'}>{value}</dd>
            <dd className="mt-0.5 text-xs text-ink-muted">{hint}</dd>
        </div>
    );
}

/** Restoring replaces everything: the admin re-enters their password and types the confirmation word. */
function RestoreDialog({ backup, confirmationWord, onClose }: { backup: BackupRow | null; confirmationWord: string; onClose: () => void }) {
    const formatDate = useDateFormatter();
    const form = useForm({ confirmation: '', password: '' });

    const close = () => {
        form.reset();
        form.clearErrors();
        onClose();
    };

    const submit = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        if (backup === null) {
            return;
        }
        form.post(routes.backups.restore(backup.id), { preserveScroll: true, onSuccess: close, onFinish: () => form.reset('password') });
    };

    return (
        <Dialog open={backup !== null} title="Restore the Whole System?" description={backup === null ? undefined : `Backup of ${formatDate.dateTime(backup.createdAt)}`} busy={form.processing} onClose={close}>
            {backup !== null && (
                <form onSubmit={submit} noValidate className="flex flex-col gap-5">
                    <Alert tone="danger" title="Everything recorded after this backup will be lost">
                        <ul className="list-disc space-y-1 pl-5">
                            <li>Grades, examination answers, uploads and every other change made after {formatDate.dateTime(backup.createdAt)} are replaced.</li>
                            <li>A safety backup of the system as it is now is taken first, so this can be undone by restoring that one.</li>
                            <li>For a few minutes the system shows a maintenance page, and everyone, including you, is signed out.</li>
                        </ul>
                    </Alert>
                    <FormField label={`Type ${confirmationWord} to confirm`} required error={form.errors.confirmation}>
                        <TextInput value={form.data.confirmation} onChange={(event) => form.setData('confirmation', event.target.value)} autoComplete="off" spellCheck={false} />
                    </FormField>
                    <FormField label="Your password" required error={form.errors.password}>
                        <PasswordInput value={form.data.password} onChange={(event) => form.setData('password', event.target.value)} autoComplete="current-password" />
                    </FormField>
                    <div className="flex flex-col-reverse gap-2 border-t border-line pt-4 sm:flex-row sm:justify-end">
                        <Button variant="secondary" onClick={close} disabled={form.processing}>
                            Cancel
                        </Button>
                        <Button type="submit" variant="danger" loading={form.processing} disabled={form.data.confirmation !== confirmationWord || form.data.password === ''}>
                            Restore the System
                        </Button>
                    </div>
                </form>
            )}
        </Dialog>
    );
}
