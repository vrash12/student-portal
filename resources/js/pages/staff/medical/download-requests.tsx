import { Head, Link, useForm, usePage } from '@inertiajs/react';
import { Check, FileDown, X } from 'lucide-react';
import { useState, type FormEvent } from 'react';
import { Alert } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { ConfirmAction } from '@/components/ui/confirm-action';
import { Dialog } from '@/components/ui/dialog';
import { EmptyState } from '@/components/ui/empty-state';
import { FormField, TextArea } from '@/components/ui/form-field';
import { PageHeader } from '@/components/ui/page-header';
import { Pagination } from '@/components/ui/pagination';
import { StatusBadge } from '@/components/ui/status-badge';
import { RowAction, Table, TableBody, TableHead, Td, Th, Tr } from '@/components/ui/table';
import { cn } from '@/lib/cn';
import { useDateFormatter } from '@/lib/format';
import { routes } from '@/lib/routes';
import type { Paginated } from '@/types';
import type { MedicalDownloadRow } from '@/types/medical';

type Filter = 'pending' | 'active' | 'all';

interface DownloadRequestsProps {
    requests: Paginated<MedicalDownloadRow>;
    status: Filter;
    counts: Record<Filter, number>;
    /** Days an approved download stays available. */
    days: number;
}

const TABS: Array<{ value: Filter; label: string }> = [
    { value: 'pending', label: 'Waiting for Approval' },
    { value: 'active', label: 'Approved Now' },
    { value: 'all', label: 'All' },
];

/**
 * Instructors' requests for a copy of an uploaded medical document (owner
 * request, 2026-10-02). Instructors with approved access view documents
 * only; a download needs the medical staff's approval.
 */
export default function MedicalDownloadRequests({ requests, status, counts, days }: DownloadRequestsProps) {
    const formatDate = useDateFormatter();
    const errors = usePage().props.errors as Record<string, string | undefined>;
    const [deciding, setDeciding] = useState<{ row: MedicalDownloadRow; decision: 'approve' | 'reject' } | null>(null);

    return (
        <>
            <Head title="Medical Document Download Requests" />

            <PageHeader
                title="Download Requests"
                description={`Instructors with access to a medical record view its documents only. To keep a copy they ask here, with a reason. An approval lets that instructor download that one document for ${days} days; every download is recorded.`}
                breadcrumbs={[{ label: 'Medical Records', href: routes.medical.records.index() }, { label: 'Download Requests' }]}
            />

            {errors.download !== undefined && (
                <Alert tone="danger" title="Nothing was changed" className="mb-6">
                    {errors.download}
                </Alert>
            )}

            <section className="rounded-lg border border-line-box bg-surface" aria-label="Medical document download requests">
                <nav aria-label="Filter by status" className="flex flex-wrap gap-2 border-b border-line px-4 py-3">
                    {TABS.map((tab) => {
                        const active = tab.value === status;

                        return (
                            <Link
                                key={tab.value}
                                href={routes.medical.downloads.index({ status: tab.value })}
                                preserveScroll
                                aria-current={active ? 'page' : undefined}
                                className={cn(
                                    'inline-flex min-h-11 items-center gap-2 rounded-lg border px-3 text-sm font-medium',
                                    active ? 'border-primary-700 bg-primary-700 text-white' : 'border-line-box text-ink hover:bg-surface-muted',
                                )}
                            >
                                {tab.label}
                                <span className={cn('rounded-full px-2 text-xs tabular-nums', active ? 'bg-white/20' : 'bg-surface-muted text-ink-muted')}>{counts[tab.value]}</span>
                            </Link>
                        );
                    })}
                </nav>

                {requests.data.length === 0 ? (
                    <EmptyState
                        icon={FileDown}
                        title={status === 'pending' ? 'No requests waiting' : status === 'active' ? 'No approved downloads now' : 'No download requests yet'}
                        description="Instructors with approved access request a download from the documents in a candidate's Medical Record panel."
                    />
                ) : (
                    <Table caption="Medical document download requests" className="min-w-[68rem]">
                        <TableHead>
                            <Th>Request</Th>
                            <Th>Document</Th>
                            <Th>Reason</Th>
                            <Th>Status</Th>
                            <Th align="right">
                                <span className="sr-only">Actions</span>
                            </Th>
                        </TableHead>
                        <TableBody>
                            {requests.data.map((row) => (
                                <Tr key={row.id}>
                                    <Td className="whitespace-nowrap text-ink">
                                        <span className="font-medium">{row.requestedBy}</span>
                                        <span className="block text-xs text-ink-muted">
                                            #{row.id} · {row.requestedAt === null ? '' : formatDate.dateTime(row.requestedAt)}
                                        </span>
                                    </Td>
                                    <Td className="text-ink">
                                        <a href={row.document.fileUrl} target="_blank" rel="noopener" className="font-medium text-primary-700 underline-offset-2 hover:underline">
                                            {row.document.title}
                                        </a>
                                        <span className="block text-xs text-ink-muted">
                                            {row.document.category} · {row.candidate.name} ({row.candidate.number}
                                            {row.candidate.className ? ` · ${row.candidate.className}` : ''})
                                        </span>
                                    </Td>
                                    <Td className="max-w-md text-ink">
                                        <span className="block whitespace-pre-line">{row.reason}</span>
                                        {row.decisionNote && <span className="mt-1 block text-xs text-ink-muted">Answer: {row.decisionNote}</span>}
                                    </Td>
                                    <Td>
                                        <StatusBadge tone={row.status.tone}>{row.status.label}</StatusBadge>
                                        <span className="mt-1 block text-xs text-ink-muted">
                                            {row.status.value === 'approved' && row.expiresAt && `Until ${formatDate.dateTime(row.expiresAt)}`}
                                            {row.status.value === 'revoked' && row.revokedBy && `By ${row.revokedBy}`}
                                            {(row.status.value === 'rejected' || row.status.value === 'approved') && row.decidedBy && ` · ${row.decidedBy}`}
                                            {row.downloadCount > 0 && (
                                                <span className="block">
                                                    Downloaded {row.downloadCount === 1 ? 'once' : `${row.downloadCount} times`}
                                                    {row.lastDownloadedAt !== null && `, last ${formatDate.dateTime(row.lastDownloadedAt)}`}
                                                </span>
                                            )}
                                        </span>
                                    </Td>
                                    <Td align="right">
                                        <span className="inline-flex flex-wrap justify-end gap-1">
                                            <RowAction href={routes.candidates.show(row.candidate.id)} label={`Open the profile of ${row.candidate.name}`}>
                                                Profile
                                            </RowAction>
                                            {row.can.decide && (
                                                <>
                                                    <Button variant="secondary" size="sm" icon={<X className="size-4" aria-hidden="true" />} onClick={() => setDeciding({ row, decision: 'reject' })}>
                                                        Reject
                                                    </Button>
                                                    <Button size="sm" icon={<Check className="size-4" aria-hidden="true" />} onClick={() => setDeciding({ row, decision: 'approve' })}>
                                                        Approve
                                                    </Button>
                                                </>
                                            )}
                                            {row.can.revoke && (
                                                <ConfirmAction
                                                    href={routes.medical.downloads.revoke(row.id)}
                                                    method="post"
                                                    variant="secondary"
                                                    size="sm"
                                                    title="Withdraw this download?"
                                                    description={<p>{row.requestedBy} will no longer be able to download {row.document.title}. Viewing is not affected.</p>}
                                                    confirmLabel="Withdraw Download"
                                                >
                                                    Withdraw
                                                </ConfirmAction>
                                            )}
                                        </span>
                                    </Td>
                                </Tr>
                            ))}
                        </TableBody>
                    </Table>
                )}

                <Pagination page={requests} noun={{ one: 'request', other: 'requests' }} />
            </section>

            <DecisionDialog deciding={deciding} days={days} onClose={() => setDeciding(null)} />
        </>
    );
}

function DecisionDialog({ deciding, days, onClose }: { deciding: { row: MedicalDownloadRow; decision: 'approve' | 'reject' } | null; days: number; onClose: () => void }) {
    const approving = deciding?.decision === 'approve';
    const form = useForm({ note: '' });

    const close = () => {
        form.reset();
        form.clearErrors();
        onClose();
    };

    const submit = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        if (deciding === null) {
            return;
        }
        form.post(approving ? routes.medical.downloads.approve(deciding.row.id) : routes.medical.downloads.reject(deciding.row.id), {
            preserveScroll: true,
            onSuccess: close,
        });
    };

    return (
        <Dialog
            open={deciding !== null}
            title={approving ? 'Approve the Download?' : 'Reject the Request?'}
            description={deciding === null ? undefined : `${deciding.row.requestedBy} · ${deciding.row.document.title}`}
            busy={form.processing}
            onClose={close}
        >
            {deciding !== null && (
                <form onSubmit={submit} noValidate className="flex flex-col gap-5">
                    <p className="whitespace-pre-line rounded-lg border border-line-box bg-surface-muted px-4 py-3 text-sm text-ink">{deciding.row.reason}</p>
                    {approving && (
                        <Alert tone="info">
                            {deciding.row.requestedBy} can download this one document for {days} days. Every download is recorded, and you can withdraw it earlier.
                        </Alert>
                    )}
                    <FormField
                        label={approving ? 'Note to the instructor' : 'Reason for rejection'}
                        required={!approving}
                        error={form.errors.note}
                        hint={approving ? 'Optional.' : 'Required. The instructor sees this.'}
                    >
                        <TextArea value={form.data.note} onChange={(event) => form.setData('note', event.target.value)} maxLength={1000} rows={3} />
                    </FormField>
                    <div className="flex flex-col-reverse gap-2 border-t border-line pt-4 sm:flex-row sm:justify-end">
                        <Button variant="secondary" onClick={close} disabled={form.processing}>
                            Go Back
                        </Button>
                        <Button type="submit" variant={approving ? 'primary' : 'danger'} loading={form.processing}>
                            {approving ? 'Approve Download' : 'Reject Request'}
                        </Button>
                    </div>
                </form>
            )}
        </Dialog>
    );
}
