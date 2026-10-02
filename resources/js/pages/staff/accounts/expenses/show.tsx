import { Head, useForm } from '@inertiajs/react';
import { Ban, Pencil, School, SearchX, UsersRound, WalletCards } from 'lucide-react';
import { useState, type FormEvent } from 'react';
import { AmountCard } from '@/components/accounts/amount-card';
import type { AccountExpense } from '@/components/accounts/types';
import { VoidEntryDialog } from '@/components/accounts/void-entry-dialog';
import type { ClassOptionGroup } from '@/components/candidates/candidate-form';
import { Alert } from '@/components/ui/alert';
import { Button, ButtonLink } from '@/components/ui/button';
import { EmptyState } from '@/components/ui/empty-state';
import { FilterBar, SearchField } from '@/components/ui/filter-bar';
import { FormField, SelectInput, TextInput } from '@/components/ui/form-field';
import { MetricCard } from '@/components/ui/metric-card';
import { PageHeader } from '@/components/ui/page-header';
import { Pagination } from '@/components/ui/pagination';
import { Panel } from '@/components/ui/panel';
import { RadioCards } from '@/components/ui/radio-cards';
import { StatusBadge } from '@/components/ui/status-badge';
import { Table, TableBody, TableHead, Td, Th, Tr } from '@/components/ui/table';
import { formatCalendarDate, useDateFormatter } from '@/lib/format';
import { useMoney } from '@/lib/money';
import { routes } from '@/lib/routes';
import { terms } from '@/lib/terminology';
import { useQueryFilters } from '@/lib/use-query-filters';
import type { Paginated } from '@/types';
import type { StatusValue } from '@/types/grading';

interface ExpenseCharge {
    /** The charge entry of the candidate. */
    id: number;
    candidate: { id: number; candidateNumber: string; name: string; className: string | null };
    postedOn: string;
    category: string;
    type: { value: string; label: string };
    amount: string;
    description: string;
    voided: { at: string; by: string | null; reason: string } | null;
}

interface PickerCandidate {
    id: number;
    candidateNumber: string;
    name: string;
    className: string | null;
    status: StatusValue;
    /** Already has a charge for this expense that counts. */
    charged: boolean;
}

interface ExpenseShowProps {
    expense: AccountExpense & { createdBy: string; assignedCount: number; assignedTotal: string };
    charges: Paginated<ExpenseCharge>;
    filters: { class: string; search: string; [key: string]: string };
    picker: { candidates: PickerCandidate[]; truncated: boolean };
    classOptions: ClassOptionGroup[];
    /** Today in the institution's timezone (Y-m-d). */
    today: string;
    maxCandidates: number;
}

export default function AccountExpenseShow({ expense, charges, filters, picker, classOptions, today, maxCandidates }: ExpenseShowProps) {
    const money = useMoney();
    const formatDate = useDateFormatter();
    const [voiding, setVoiding] = useState<ExpenseCharge | null>(null);

    return (
        <>
            <Head title={expense.name} />

            <PageHeader
                title={expense.name}
                description={
                    <>
                        {expense.category.name} · {money(expense.amount)} per candidate · Due{' '}
                        {expense.dueOn === null ? 'upon assessment' : formatCalendarDate(expense.dueOn)}
                        {!expense.isActive && (
                            <>
                                {' '}
                                <StatusBadge tone="neutral">Inactive</StatusBadge>
                            </>
                        )}
                    </>
                }
                breadcrumbs={[
                    { label: 'Expenses', href: routes.accounts.expenses.index() },
                    { label: expense.name },
                ]}
                actions={
                    <ButtonLink href={routes.accounts.expenses.edit(expense.id)} icon={<Pencil className="size-4" aria-hidden="true" />}>
                        Edit Expense
                    </ButtonLink>
                }
            />

            <div className="flex flex-col gap-6">
                <dl className="grid grid-cols-1 gap-4 sm:grid-cols-3">
                    <AmountCard label="Amount per candidate" amount={money(expense.amount)} description={expense.description ?? `Created by ${expense.createdBy}.`} />
                    <MetricCard label="Candidates charged" value={expense.assignedCount} description="Voided charges are not counted." />
                    <AmountCard label="Total assessed" amount={money(expense.assignedTotal)} description="Across every candidate charged." />
                </dl>

                {expense.isActive ? (
                    <AssignPanel expense={expense} filters={filters} picker={picker} classOptions={classOptions} today={today} maxCandidates={maxCandidates} />
                ) : (
                    <Alert tone="warning" title="This expense is inactive">
                        Its charges are kept, but it cannot be assigned. Edit the expense to reactivate it.
                    </Alert>
                )}

                <Panel
                    title="Charged Candidates"
                    description="Void a charge made by mistake; the candidate can then be charged again."
                    bodyClassName="p-0"
                >
                    {charges.data.length === 0 ? (
                        <EmptyState
                            icon={WalletCards}
                            headingLevel="h3"
                            title="Not assigned yet"
                            description="Assign this expense to a whole class or to chosen candidates above."
                        />
                    ) : (
                        <Table caption="Candidates charged this expense" className="min-w-[48rem]">
                            <TableHead>
                                <Th>Candidate No.</Th>
                                <Th>Name</Th>
                                <Th>{terms.classBatch.singular}</Th>
                                <Th>Assessed</Th>
                                <Th>Status</Th>
                                <Th align="right">
                                    <span className="sr-only">Actions</span>
                                </Th>
                            </TableHead>
                            <TableBody>
                                {charges.data.map((charge) => (
                                    <Tr key={charge.id}>
                                        <Td className="whitespace-nowrap text-ink">{charge.candidate.candidateNumber}</Td>
                                        <Td>
                                            <span className="font-medium text-ink">{charge.candidate.name}</span>
                                        </Td>
                                        <Td className="text-ink">{charge.candidate.className ?? '—'}</Td>
                                        <Td className="whitespace-nowrap text-ink">{formatCalendarDate(charge.postedOn)}</Td>
                                        <Td>
                                            {charge.voided === null ? (
                                                <StatusBadge tone="warning">Charged</StatusBadge>
                                            ) : (
                                                <span className="flex flex-col gap-1">
                                                    <span>
                                                        <StatusBadge tone="neutral">Voided</StatusBadge>
                                                    </span>
                                                    <span className="text-xs text-ink-muted">
                                                        {charge.voided.reason} · {charge.voided.by ?? 'Unknown user'}, {formatDate.dateTime(charge.voided.at)}
                                                    </span>
                                                </span>
                                            )}
                                        </Td>
                                        <Td align="right">
                                            {charge.voided === null && (
                                                <Button
                                                    variant="ghost"
                                                    size="sm"
                                                    icon={<Ban className="size-4" aria-hidden="true" />}
                                                    aria-label={`Void the charge of ${expense.name} for ${charge.candidate.name}`}
                                                    onClick={() => setVoiding(charge)}
                                                >
                                                    Void
                                                </Button>
                                            )}
                                        </Td>
                                    </Tr>
                                ))}
                            </TableBody>
                        </Table>
                    )}
                    <Pagination page={charges} noun={{ one: 'charge', other: 'charges' }} />
                </Panel>
            </div>

            <VoidEntryDialog entry={voiding} amountLabel={voiding === null ? '' : money(voiding.amount)} onClose={() => setVoiding(null)} />
        </>
    );
}

interface AssignFormData {
    mode: 'class' | 'candidates';
    class_batch_id: string;
    candidate_ids: number[];
    posted_on: string;
}

interface AssignPanelProps {
    expense: ExpenseShowProps['expense'];
    filters: ExpenseShowProps['filters'];
    picker: ExpenseShowProps['picker'];
    classOptions: ClassOptionGroup[];
    today: string;
    maxCandidates: number;
}

/**
 * Assigns the expense to a whole class (the server charges its candidates
 * who are not withdrawn) or to candidates chosen from a class or a search.
 * Candidates already charged are skipped by the server.
 */
function AssignPanel({ expense, filters, picker, classOptions, today, maxCandidates }: AssignPanelProps) {
    const money = useMoney();
    const classTerm = terms.classBatch.singular;
    const [connectionError, setConnectionError] = useState<string | null>(null);
    const { values, update, reset, isFiltered } = useQueryFilters(routes.accounts.expenses.show(expense.id), filters);
    const form = useForm<AssignFormData>({
        mode: 'class',
        class_batch_id: '',
        candidate_ids: [],
        posted_on: today,
    });
    const selected = new Set(form.data.candidate_ids);
    const selectable = picker.candidates.filter((candidate) => !candidate.charged);
    const allShownSelected = selectable.length > 0 && selectable.every((candidate) => selected.has(candidate.id));
    const errors = form.errors as Partial<Record<string, string>>;
    const candidateError = errors.candidate_ids ?? Object.entries(errors).find(([key]) => key.startsWith('candidate_ids.'))?.[1];

    const toggle = (candidateId: number, checked: boolean) => {
        const next = new Set(selected);
        if (checked) {
            next.add(candidateId);
        } else {
            next.delete(candidateId);
        }
        form.setData('candidate_ids', [...next]);
    };

    const toggleAllShown = () => {
        const next = new Set(selected);
        for (const candidate of selectable) {
            if (allShownSelected) {
                next.delete(candidate.id);
            } else {
                next.add(candidate.id);
            }
        }
        form.setData('candidate_ids', [...next]);
    };

    const submit = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        form.post(routes.accounts.expenses.assign(expense.id), {
            preserveScroll: true,
            onStart: () => setConnectionError(null),
            onSuccess: () => form.reset('candidate_ids', 'class_batch_id'),
            onNetworkError: () => {
                setConnectionError('The expense was not assigned because the connection was interrupted. No candidate was charged. Check the connection and try again.');

                return false;
            },
        });
    };

    const count = form.data.candidate_ids.length;
    const submitLabel = form.data.mode === 'class' ? `Charge Whole ${classTerm}` : `Charge ${count} ${count === 1 ? 'Candidate' : 'Candidates'}`;

    return (
        <Panel title="Assign to Candidates" description={`Each candidate chosen is charged ${money(expense.amount)} once. Candidates already charged are left as they are.`}>
            <form onSubmit={submit} noValidate className="flex flex-col gap-5">
                {connectionError !== null && <Alert tone="danger">{connectionError}</Alert>}
                {errors.expense && <Alert tone="danger">{errors.expense}</Alert>}

                <RadioCards
                    legend="Charge"
                    name="mode"
                    options={[
                        { value: 'class', label: `A whole ${classTerm.toLowerCase()}`, description: 'Every candidate of the class who is not withdrawn.', icon: School },
                        { value: 'candidates', label: 'Chosen candidates', description: 'Pick candidates from a class or by search.', icon: UsersRound },
                    ]}
                    value={form.data.mode}
                    onChange={(value) => form.setData('mode', value === 'candidates' ? 'candidates' : 'class')}
                    error={form.errors.mode}
                    required
                />

                <div className="grid gap-5 sm:grid-cols-2">
                    {form.data.mode === 'class' && (
                        <FormField label={classTerm} required error={form.errors.class_batch_id}>
                            <SelectInput name="class_batch_id" value={form.data.class_batch_id} onChange={(event) => form.setData('class_batch_id', event.target.value)}>
                                <option value="">Choose a {classTerm.toLowerCase()}</option>
                                <ClassOptions classOptions={classOptions} />
                            </SelectInput>
                        </FormField>
                    )}
                    <FormField label="Date of the charge" required error={form.errors.posted_on} hint="The date the candidates are charged.">
                        <TextInput type="date" name="posted_on" value={form.data.posted_on} onChange={(event) => form.setData('posted_on', event.target.value)} />
                    </FormField>
                </div>

                {form.data.mode === 'candidates' && (
                    <div className="rounded-lg border border-line-box">
                        <FilterBar onReset={reset} canReset={isFiltered}>
                            <FormField label={classTerm} className="sm:w-56">
                                <SelectInput value={values.class} onChange={(event) => update('class', event.target.value)}>
                                    <option value="">All {terms.classBatch.plural.toLowerCase()}</option>
                                    <ClassOptions classOptions={classOptions} />
                                </SelectInput>
                            </FormField>
                            <SearchField
                                placeholder="Search candidates by number or name…"
                                value={values.search}
                                onChange={(value) => update('search', value, { debounce: true })}
                            />
                        </FilterBar>

                        {!isFiltered ? (
                            <p className="px-4 py-5 text-sm text-ink-muted">Choose a {classTerm.toLowerCase()} or search to list candidates.</p>
                        ) : picker.candidates.length === 0 ? (
                            <EmptyState icon={SearchX} headingLevel="h3" title="No candidates match" description="Choose another class or change the search." />
                        ) : (
                            <>
                                <div className="flex flex-wrap items-center justify-between gap-3 border-b border-line px-4 py-3">
                                    <p className="text-sm text-ink-muted" aria-live="polite">
                                        <span className="font-medium text-ink tabular-nums">{count}</span> chosen
                                        {count > 0 && ' (kept when you change the class or search)'}
                                    </p>
                                    <div className="flex gap-2">
                                        {count > 0 && (
                                            <Button variant="ghost" size="sm" onClick={() => form.setData('candidate_ids', [])}>
                                                Clear Choice
                                            </Button>
                                        )}
                                        <Button variant="secondary" size="sm" onClick={toggleAllShown} disabled={selectable.length === 0}>
                                            {allShownSelected ? 'Unselect Shown' : 'Select All Shown'}
                                        </Button>
                                    </div>
                                </div>
                                <ul className="max-h-[28rem] divide-y divide-line overflow-y-auto">
                                    {picker.candidates.map((candidate) => (
                                        <li key={candidate.id}>
                                            <label
                                                className={`flex min-h-12 items-center gap-3 px-4 py-2 ${candidate.charged ? 'cursor-not-allowed bg-surface-muted' : 'cursor-pointer hover:bg-surface-muted'}`}
                                            >
                                                <input
                                                    type="checkbox"
                                                    className="size-4 shrink-0 accent-primary-600 pointer-coarse:size-5"
                                                    checked={candidate.charged || selected.has(candidate.id)}
                                                    disabled={candidate.charged}
                                                    onChange={(event) => toggle(candidate.id, event.target.checked)}
                                                />
                                                <span className="min-w-0 flex-1">
                                                    <span className="block text-sm font-medium text-ink">
                                                        {candidate.name} <span className="font-normal text-ink-muted">· {candidate.candidateNumber}</span>
                                                    </span>
                                                    <span className="block text-xs text-ink-muted">{candidate.className ?? `No ${classTerm.toLowerCase()}`}</span>
                                                </span>
                                                {candidate.status.value !== 'enrolled' && <StatusBadge tone={candidate.status.tone}>{candidate.status.label}</StatusBadge>}
                                                {candidate.charged && <StatusBadge tone="neutral">Already charged</StatusBadge>}
                                            </label>
                                        </li>
                                    ))}
                                </ul>
                                {picker.truncated && (
                                    <p className="border-t border-line px-4 py-3 text-sm text-ink-muted">
                                        Only the first {picker.candidates.length} candidates are listed. Narrow the search, or charge a whole {classTerm.toLowerCase()}.
                                    </p>
                                )}
                            </>
                        )}
                        {candidateError && <p className="border-t border-line px-4 py-3 text-sm text-danger-fg">{candidateError}</p>}
                    </div>
                )}

                <div className="flex flex-col-reverse items-stretch gap-3 sm:flex-row sm:items-center sm:justify-end">
                    {form.data.mode === 'candidates' && count > maxCandidates && (
                        <p className="text-sm text-danger-fg">
                            At most {maxCandidates} candidates at a time. Charge a whole {classTerm.toLowerCase()} instead.
                        </p>
                    )}
                    <Button
                        type="submit"
                        loading={form.processing}
                        disabled={form.data.mode === 'candidates' && (count === 0 || count > maxCandidates)}
                        icon={<WalletCards className="size-4" aria-hidden="true" />}
                    >
                        {submitLabel}
                    </Button>
                </div>
            </form>
        </Panel>
    );
}

function ClassOptions({ classOptions }: { classOptions: ClassOptionGroup[] }) {
    return (
        <>
            {classOptions.map((group) => (
                <optgroup key={group.period} label={`${group.period}${group.isActive ? ' (active)' : ''}`}>
                    {group.classes.map((classBatch) => (
                        <option key={classBatch.id} value={String(classBatch.id)}>
                            {classBatch.name}
                        </option>
                    ))}
                </optgroup>
            ))}
        </>
    );
}
