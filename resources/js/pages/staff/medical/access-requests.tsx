import { Head, Link, useForm, usePage } from '@inertiajs/react';
import { Check, LockKeyhole, X } from 'lucide-react';
import { useState, type FormEvent } from 'react';
import { Alert } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { ConfirmAction } from '@/components/ui/confirm-action';
import { Dialog } from '@/components/ui/dialog';
import { EmptyState } from '@/components/ui/empty-state';
import { FormField, TextArea } from '@/components/ui/form-field';
import { PageHeader } from '@/components/ui/page-header';
import { Pagination } from '@/components/ui/pagination';
import { RadioCards } from '@/components/ui/radio-cards';
import { StatusBadge } from '@/components/ui/status-badge';
import { RowAction, Table, TableBody, TableHead, Td, Th, Tr } from '@/components/ui/table';
import { cn } from '@/lib/cn';
import { useDateFormatter } from '@/lib/format';
import { routes } from '@/lib/routes';
import type { Paginated } from '@/types';
import type { MedicalAccessRow } from '@/types/medical';

type Filter = 'pending' | 'active' | 'all';

interface AccessRequestsProps {
    requests: Paginated<MedicalAccessRow>;
    status: Filter;
    counts: Record<Filter, number>;
    /** Days an approval may last. */
    durations: number[];
    defaultDuration: number;
}

const TABS: Array<{ value: Filter; label: string }> = [
    { value: 'pending', label: 'Waiting for Approval' },
    { value: 'active', label: 'Access Granted Now' },
    { value: 'all', label: 'All' },
];

/** Instructors' requests to see a full medical record, for the medical staff to decide. */
export default function MedicalAccessRequests({ requests, status, counts, durations, defaultDuration }: AccessRequestsProps) {
    const formatDate = useDateFormatter();
    const errors = usePage().props.errors as Record<string, string | undefined>;
    const [deciding, setDeciding] = useState<{ row: MedicalAccessRow; decision: 'approve' | 'reject' } | null>(null);

    return (
        <>
            <Head title="Medical Record Access Requests" />

            <PageHeader
                title="Access Requests"
                description="Instructors normally see only the fields shared with instructors. Here they ask, with a reason, to see one candidate's full record. Approve for a limited time, or reject with a reason. Instructors' views of a shared record are recorded."
                breadcrumbs={[{ label: 'Medical Records', href: routes.medical.records.index() }, { label: 'Access Requests' }]}
            />

            {errors.access !== undefined && (
                <Alert tone="danger" title="Nothing was changed" className="mb-6">
                    {errors.access}
                </Alert>
            )}

            <section className="rounded-lg border border-line bg-surface" aria-label="Medical record access requests">
                <nav aria-label="Filter by status" className="flex flex-wrap gap-2 border-b border-line px-4 py-3">
                    {TABS.map((tab) => {
                        const active = tab.value === status;

                        return (
                            <Link
                                key={tab.value}
                                href={routes.medical.access.index({ status: tab.value })}
                                preserveScroll
                                aria-current={active ? 'page' : undefined}
                                className={cn(
                                    'inline-flex min-h-11 items-center gap-2 rounded-lg border px-3 text-sm font-medium',
                                    active ? 'border-primary-700 bg-primary-700 text-white' : 'border-line text-ink hover:bg-surface-muted',
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
                        icon={LockKeyhole}
                        title={status === 'pending' ? 'No requests waiting' : status === 'active' ? 'No instructor has access now' : 'No requests yet'}
                        description="Instructors request a full record from the Medical Record panel of a candidate's profile."
                    />
                ) : (
                    <Table caption="Medical record access requests" className="min-w-[64rem]">
                        <TableHead>
                            <Th>Request</Th>
                            <Th>Candidate</Th>
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
                                            #{row.id} · {formatDate.dateTime(row.requestedAt)}
                                        </span>
                                    </Td>
                                    <Td className="text-ink">
                                        <span className="font-medium">{row.candidate.name}</span>
                                        <span className="block text-xs text-ink-muted">
                                            {row.candidate.number}
                                            {row.candidate.className ? ` · ${row.candidate.className}` : ''}
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
                                            {row.status.value === 'expired' && row.expiresAt && `Ended ${formatDate.dateTime(row.expiresAt)}`}
                                            {row.status.value === 'revoked' && row.revokedBy && `By ${row.revokedBy}`}
                                            {(row.status.value === 'rejected' || row.status.value === 'approved') && row.decidedBy && ` · ${row.decidedBy}`}
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
                                                    href={routes.medical.access.revoke(row.id)}
                                                    method="post"
                                                    variant="secondary"
                                                    size="sm"
                                                    title="Withdraw access now?"
                                                    description={
                                                        <p>
                                                            {row.requestedBy} will see only the shared fields of {row.candidate.name}'s medical record again.
                                                        </p>
                                                    }
                                                    confirmLabel="Withdraw Access"
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

            <DecisionDialog deciding={deciding} durations={durations} defaultDuration={defaultDuration} onClose={() => setDeciding(null)} />
        </>
    );
}

interface DecisionDialogProps {
    deciding: { row: MedicalAccessRow; decision: 'approve' | 'reject' } | null;
    durations: number[];
    defaultDuration: number;
    onClose: () => void;
}

function DecisionDialog({ deciding, durations, defaultDuration, onClose }: DecisionDialogProps) {
    const [processing, setProcessing] = useState(false);
    const approving = deciding?.decision === 'approve';

    return (
        <Dialog
            open={deciding !== null}
            title={approving ? 'Approve Access to the Full Record?' : 'Reject the Request?'}
            description={deciding === null ? undefined : `${deciding.row.requestedBy} · ${deciding.row.candidate.name}`}
            busy={processing}
            onClose={onClose}
        >
            {deciding !== null && (
                <DecisionForm
                    key={`${deciding.row.id}-${deciding.decision}`}
                    row={deciding.row}
                    approving={approving}
                    durations={durations}
                    defaultDuration={defaultDuration}
                    onProcessingChange={setProcessing}
                    onClose={onClose}
                />
            )}
        </Dialog>
    );
}

interface DecisionFormProps {
    row: MedicalAccessRow;
    approving: boolean;
    durations: number[];
    defaultDuration: number;
    onProcessingChange: (processing: boolean) => void;
    onClose: () => void;
}

function DecisionForm({ row, approving, durations, defaultDuration, onProcessingChange, onClose }: DecisionFormProps) {
    const form = useForm({ days: String(defaultDuration), note: '' });

    const submit = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        if (!approving) {
            form.transform(({ note }) => ({ note }));
        }
        form.post(approving ? routes.medical.access.approve(row.id) : routes.medical.access.reject(row.id), {
            preserveScroll: true,
            onStart: () => onProcessingChange(true),
            onFinish: () => onProcessingChange(false),
            onSuccess: () => onClose(),
        });
    };

    return (
        <form onSubmit={submit} noValidate className="flex flex-col gap-5">
            <p className="whitespace-pre-line rounded-lg border border-line bg-surface-muted px-4 py-3 text-sm text-ink">{row.reason}</p>
            {approving && (
                <RadioCards
                    legend="Share the full record for"
                    name="days"
                    columns={3}
                    options={durations.map((days) => ({ value: String(days), label: days === 1 ? '1 day' : `${days} days`, description: days === defaultDuration ? 'Usual' : null }))}
                    value={form.data.days}
                    onChange={(value) => form.setData('days', value)}
                    error={form.errors.days}
                    required
                />
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
                <Button variant="secondary" onClick={onClose} disabled={form.processing}>
                    Go Back
                </Button>
                <Button type="submit" variant={approving ? 'primary' : 'danger'} loading={form.processing}>
                    {approving ? 'Approve Access' : 'Reject Request'}
                </Button>
            </div>
        </form>
    );
}
