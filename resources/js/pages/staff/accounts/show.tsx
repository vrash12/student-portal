import { Head, usePage } from '@inertiajs/react';
import { Ban, Download, ReceiptText, UserRound } from 'lucide-react';
import { useState } from 'react';
import { AmountCard } from '@/components/accounts/amount-card';
import { RecordEntryForm, type CategoryOption } from '@/components/accounts/record-entry-form';
import type { AccountEntryTypeOption, Statement, StatementEntry } from '@/components/accounts/types';
import { VoidEntryDialog } from '@/components/accounts/void-entry-dialog';
import { Alert } from '@/components/ui/alert';
import { Button, ButtonLink, buttonClasses } from '@/components/ui/button';
import { EmptyState } from '@/components/ui/empty-state';
import { FilterBar } from '@/components/ui/filter-bar';
import { FormField, TextInput } from '@/components/ui/form-field';
import { PageHeader } from '@/components/ui/page-header';
import { Panel } from '@/components/ui/panel';
import { StatusBadge } from '@/components/ui/status-badge';
import { Table, TableBody, TableHead, Td, Th, Tr } from '@/components/ui/table';
import { cn } from '@/lib/cn';
import { formatCalendarDate, useDateFormatter } from '@/lib/format';
import { useMoney } from '@/lib/money';
import { routes } from '@/lib/routes';
import { useQueryFilters } from '@/lib/use-query-filters';
import type { StatusValue } from '@/types/grading';

interface AccountShowProps {
    candidate: {
        id: number;
        candidateNumber: string;
        name: string;
        status: StatusValue;
        classBatch: { name: string; period: string } | null;
    };
    statement: Statement;
    /** Active categories, for recording entries. */
    categories: CategoryOption[];
    entryTypes: AccountEntryTypeOption[];
    /** Today in the institution's timezone (Y-m-d). */
    today: string;
    can: { manage: boolean; viewCandidate: boolean };
}

export default function AccountShow({ candidate, statement, categories, entryTypes, today, can }: AccountShowProps) {
    const money = useMoney();
    const { errors } = usePage().props;
    const { values, updateMany } = useQueryFilters(routes.accounts.show(candidate.id), { from: statement.from ?? '', to: statement.to ?? '' });
    const [voiding, setVoiding] = useState<StatementEntry | null>(null);
    const hasPeriod = statement.from !== null || statement.to !== null;
    // The PDF covers the period the server is showing.
    const statementHref = routes.accounts.statement(candidate.id, { from: statement.from ?? '', to: statement.to ?? '' });

    return (
        <>
            <Head title={`Statement of Account · ${candidate.name}`} />

            <PageHeader
                title={candidate.name}
                description={
                    <>
                        Statement of Account · Candidate {candidate.candidateNumber}
                        {candidate.classBatch !== null && ` · ${candidate.classBatch.name} (${candidate.classBatch.period})`}
                        {candidate.status.value !== 'enrolled' && (
                            <>
                                {' '}
                                <StatusBadge tone={candidate.status.tone}>{candidate.status.label}</StatusBadge>
                            </>
                        )}
                    </>
                }
                breadcrumbs={[{ label: 'Statements of Account', href: routes.accounts.index() }, { label: candidate.name }]}
                actions={
                    <>
                        {can.viewCandidate && (
                            <ButtonLink href={routes.candidates.show(candidate.id)} icon={<UserRound className="size-4" aria-hidden="true" />}>
                                Candidate Profile
                            </ButtonLink>
                        )}
                        {/* A native link: the PDF is a file download, not an Inertia page. */}
                        <a href={statementHref} className={buttonClasses('primary')}>
                            <Download className="size-4" aria-hidden="true" />
                            Download SOA (PDF)
                        </a>
                    </>
                }
            />

            <div className="flex flex-col gap-6">
                <section aria-labelledby="statement-summary-heading" className="flex flex-col gap-3">
                    <div className="flex flex-wrap items-center justify-between gap-2">
                        <h2 id="statement-summary-heading" className="text-lg font-semibold text-ink">
                            Summary <span className="text-sm font-normal text-ink-muted">· {periodLabel(statement.from, statement.to)}</span>
                        </h2>
                        <StatusBadge tone={statement.status.tone}>{statement.status.label}</StatusBadge>
                    </div>
                    <dl className="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
                        <AmountCard
                            label="Balance brought forward"
                            amount={money(statement.opening)}
                            description={statement.from === null ? 'No start date: every entry is included.' : `Before ${formatCalendarDate(statement.from)}.`}
                        />
                        <AmountCard label="Charges" amount={money(statement.charges)} description="Increase the balance." />
                        <AmountCard label="Credits" amount={money(statement.credits)} description="Allowances, payments and deposits." />
                        <AmountCard
                            label={statement.to === null ? 'Current balance' : 'Closing balance'}
                            amount={money(statement.closing)}
                            description={statement.status.label}
                        />
                    </dl>
                </section>

                {can.manage && (
                    <Panel
                        title="Record an Entry"
                        description="Charges increase the balance; credits reduce it. Entries cannot be edited or deleted: void a mistaken entry and record the correct one."
                    >
                        {categories.length === 0 ? (
                            <Alert tone="warning" title="No active account categories">
                                <p>Entries are recorded under a category. Add or reactivate a category first.</p>
                                <div className="mt-2">
                                    <ButtonLink href={routes.accounts.categories.index()} variant="secondary" size="sm">
                                        Open Account Categories
                                    </ButtonLink>
                                </div>
                            </Alert>
                        ) : (
                            <RecordEntryForm candidateId={candidate.id} categories={categories} entryTypes={entryTypes} today={today} />
                        )}
                    </Panel>
                )}

                <Panel
                    title="Account Activity"
                    description="Entries of the period, oldest first, with the balance after each. Voided entries stay listed, struck through, and do not count."
                    bodyClassName="p-0"
                >
                    <FilterBar onReset={() => updateMany({ from: '', to: '' })} canReset={values.from !== '' || values.to !== ''}>
                        <FormField label="From" error={errors.from} className="sm:w-48">
                            <TextInput type="date" value={values.from} onChange={(event) => updateMany({ from: event.target.value })} />
                        </FormField>
                        <FormField label="To" error={errors.to} className="sm:w-48">
                            <TextInput type="date" value={values.to} onChange={(event) => updateMany({ to: event.target.value })} />
                        </FormField>
                    </FilterBar>

                    {statement.entries.length === 0 && statement.from === null ? (
                        <EmptyState
                            icon={ReceiptText}
                            headingLevel="h3"
                            title={hasPeriod ? 'No entries in this period' : 'No entries yet'}
                            description={
                                hasPeriod
                                    ? 'No charges or credits are dated in this period. Change the dates, or reset them to see every entry.'
                                    : can.manage
                                      ? 'Record the first charge or credit with the form above.'
                                      : 'No charges or credits have been recorded for this candidate.'
                            }
                        />
                    ) : (
                        <LedgerTable statement={statement} canManage={can.manage} onVoid={setVoiding} />
                    )}
                </Panel>

                <p className="text-sm text-ink-muted">
                    Amounts in parentheses are credit balances, in the candidate’s favor. The PDF covers the same period and leaves voided entries out.
                </p>
            </div>

            {can.manage && <VoidEntryDialog entry={voiding} amountLabel={voiding === null ? '' : money(voiding.amount)} onClose={() => setVoiding(null)} />}
        </>
    );
}

interface LedgerTableProps {
    statement: Statement;
    canManage: boolean;
    onVoid: (entry: StatementEntry) => void;
}

/** The ledger with its running balance, computed by the server. */
function LedgerTable({ statement, canManage, onVoid }: LedgerTableProps) {
    const money = useMoney();
    const formatDate = useDateFormatter();
    const columnCount = canManage ? 7 : 6;

    return (
        <Table caption="Statement of account entries" className="min-w-[60rem]">
            <TableHead>
                <Th>Date</Th>
                <Th>Category</Th>
                <Th>Description</Th>
                <Th align="right">Charge</Th>
                <Th align="right">Credit</Th>
                <Th align="right">Balance</Th>
                {canManage && (
                    <Th align="right">
                        <span className="sr-only">Actions</span>
                    </Th>
                )}
            </TableHead>
            <TableBody>
                {statement.from !== null && (
                    <tr className="bg-surface-muted">
                        <Td className="whitespace-nowrap text-ink">{formatCalendarDate(statement.from)}</Td>
                        <td className="px-4 py-3 font-medium text-ink" colSpan={4}>
                            Balance brought forward
                        </td>
                        <Td align="right" numeric className="whitespace-nowrap font-semibold text-ink">
                            {money(statement.opening)}
                        </Td>
                        {canManage && <td />}
                    </tr>
                )}
                {statement.entries.length === 0 && (
                    <tr>
                        <td className="px-4 py-3 text-ink-muted" colSpan={columnCount}>
                            No charges or credits are dated in this period.
                        </td>
                    </tr>
                )}
                {statement.entries.map((entry) => {
                    const voided = entry.voided !== null;
                    const amount = money(entry.amount);
                    const struck = voided ? 'text-ink-muted line-through' : 'text-ink';

                    return (
                        <Tr key={entry.id}>
                            <Td className={cn('whitespace-nowrap', struck)}>{formatCalendarDate(entry.postedOn)}</Td>
                            <Td className={struck}>{entry.category}</Td>
                            <Td className="min-w-64">
                                <span className={cn('block', struck)}>{entry.description}</span>
                                <span className="block text-xs text-ink-muted">
                                    {entry.reference !== null && `Ref. ${entry.reference} · `}
                                    Recorded by {entry.recordedBy}
                                    {entry.recordedAt !== null && `, ${formatDate.dateTime(entry.recordedAt)}`}
                                </span>
                                {entry.voided !== null && (
                                    <span className="mt-1.5 flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-ink">
                                        <StatusBadge tone="neutral">Voided</StatusBadge>
                                        <span>
                                            Reason: {entry.voided.reason}
                                            <span className="text-ink-muted">
                                                {' '}
                                                · {entry.voided.by ?? 'Unknown user'}, {formatDate.dateTime(entry.voided.at)}
                                            </span>
                                        </span>
                                    </span>
                                )}
                            </Td>
                            <Td align="right" numeric className={cn('whitespace-nowrap', struck)}>
                                {entry.type.value === 'charge' && amount}
                            </Td>
                            <Td align="right" numeric className={cn('whitespace-nowrap', struck)}>
                                {entry.type.value === 'credit' && amount}
                            </Td>
                            <Td align="right" numeric className="whitespace-nowrap font-semibold text-ink">
                                {entry.balance === null ? <span className="text-xs font-normal text-ink-muted">Not counted</span> : money(entry.balance)}
                            </Td>
                            {canManage && (
                                <Td align="right">
                                    {!voided && (
                                        <Button
                                            variant="ghost"
                                            size="sm"
                                            icon={<Ban className="size-4" aria-hidden="true" />}
                                            aria-label={`Void the ${entry.type.label.toLowerCase()} of ${amount} dated ${formatCalendarDate(entry.postedOn)}: ${entry.description}`}
                                            onClick={() => onVoid(entry)}
                                        >
                                            Void
                                        </Button>
                                    )}
                                </Td>
                            )}
                        </Tr>
                    );
                })}
            </TableBody>
        </Table>
    );
}

function periodLabel(from: string | null, to: string | null): string {
    if (from !== null && to !== null) {
        return `${formatCalendarDate(from)} to ${formatCalendarDate(to)}`;
    }
    if (from !== null) {
        return `From ${formatCalendarDate(from)}`;
    }

    return to !== null ? `Through ${formatCalendarDate(to)}` : 'All entries';
}
