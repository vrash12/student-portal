import { Head } from '@inertiajs/react';
import { ReceiptText, SearchX, Tags } from 'lucide-react';
import { AmountCard } from '@/components/accounts/amount-card';
import type { AccountOverview, BalanceStatus } from '@/components/accounts/types';
import type { ClassOptionGroup } from '@/components/candidates/candidate-form';
import { Button, ButtonLink } from '@/components/ui/button';
import { EmptyState } from '@/components/ui/empty-state';
import { FilterBar, SearchField } from '@/components/ui/filter-bar';
import { FormField, SelectInput } from '@/components/ui/form-field';
import { MetricCard } from '@/components/ui/metric-card';
import { PageHeader } from '@/components/ui/page-header';
import { Pagination } from '@/components/ui/pagination';
import { StatusBadge } from '@/components/ui/status-badge';
import { RowAction, Table, TableBody, TableHead, Td, Th, Tr } from '@/components/ui/table';
import { useMoney } from '@/lib/money';
import { routes } from '@/lib/routes';
import { terms } from '@/lib/terminology';
import { useQueryFilters } from '@/lib/use-query-filters';
import type { Paginated } from '@/types';
import type { StatusValue } from '@/types/grading';

interface AccountRow {
    id: number;
    candidateNumber: string;
    name: string;
    className: string | null;
    /** Enrollment status of the candidate record. */
    status: StatusValue;
    charges: string;
    credits: string;
    balance: string;
    balanceStatus: BalanceStatus;
}

interface AccountFilters {
    search: string;
    class: string;
    balance: string;
    [key: string]: string;
}

interface AccountsIndexProps {
    candidates: Paginated<AccountRow>;
    filters: AccountFilters;
    classOptions: ClassOptionGroup[];
    overview: AccountOverview;
    can: { manage: boolean };
}

const balanceOptions = [
    { value: 'due', label: 'Balance due' },
    { value: 'credit', label: 'Credit balance' },
    { value: 'settled', label: 'Settled' },
];

export default function AccountsIndex({ candidates, filters, classOptions, overview, can }: AccountsIndexProps) {
    const { values, update, reset, isFiltered } = useQueryFilters(routes.accounts.index(), filters);
    const money = useMoney();
    const classTerm = terms.classBatch.singular;

    return (
        <>
            <Head title="Statements of Account" />

            <PageHeader
                title="Statements of Account"
                description="Charges and credits recorded for each candidate, and the resulting balance. The system records amounts only; no payments are made through it."
                actions={
                    can.manage && (
                        <ButtonLink href={routes.accounts.categories.index()} icon={<Tags className="size-4" aria-hidden="true" />}>
                            Account Categories
                        </ButtonLink>
                    )
                }
            />

            <div className="flex flex-col gap-6">
                <dl className="grid grid-cols-1 gap-4 sm:grid-cols-3">
                    <AmountCard
                        label="Total balance due"
                        amount={money(overview.totalDue)}
                        description="Sum of every balance owed, after credits."
                    />
                    <MetricCard
                        label="Candidates with a balance due"
                        value={overview.candidatesDue}
                        description={`${overview.candidatesInCredit} ${overview.candidatesInCredit === 1 ? 'candidate has' : 'candidates have'} a credit balance.`}
                    />
                    <AmountCard label="Total credit balances" amount={money(overview.totalCredit)} description="Amounts in candidates’ favor." />
                </dl>

                <section className="rounded-lg border border-line bg-surface" aria-label="Candidate balances">
                    <FilterBar onReset={reset} canReset={isFiltered}>
                        <SearchField
                            placeholder="Search candidates by number or name…"
                            value={values.search}
                            onChange={(value) => update('search', value, { debounce: true })}
                        />
                        <FormField label={classTerm} className="sm:w-56">
                            <SelectInput value={values.class} onChange={(event) => update('class', event.target.value)}>
                                <option value="">All {terms.classBatch.plural.toLowerCase()}</option>
                                {classOptions.map((group) => (
                                    <optgroup key={group.period} label={`${group.period}${group.isActive ? ' (active)' : ''}`}>
                                        {group.classes.map((classBatch) => (
                                            <option key={classBatch.id} value={String(classBatch.id)}>
                                                {classBatch.name}
                                            </option>
                                        ))}
                                    </optgroup>
                                ))}
                            </SelectInput>
                        </FormField>
                        <FormField label="Balance" className="sm:w-48">
                            <SelectInput value={values.balance} onChange={(event) => update('balance', event.target.value)}>
                                <option value="">All balances</option>
                                {balanceOptions.map((option) => (
                                    <option key={option.value} value={option.value}>
                                        {option.label}
                                    </option>
                                ))}
                            </SelectInput>
                        </FormField>
                    </FilterBar>

                    {candidates.data.length === 0 ? (
                        isFiltered ? (
                            <EmptyState
                                icon={SearchX}
                                title="No candidates match these filters"
                                description="Try a different search term, or reset the filters to see every candidate."
                                action={
                                    <Button variant="secondary" onClick={reset}>
                                        Reset Filters
                                    </Button>
                                }
                            />
                        ) : (
                            <EmptyState
                                icon={ReceiptText}
                                title="No candidates yet"
                                description="Statements of account appear here once candidate records exist."
                            />
                        )
                    ) : (
                        <Table caption="Candidate balances" className="min-w-[56rem]">
                            <TableHead>
                                <Th>Candidate No.</Th>
                                <Th>Name</Th>
                                <Th>{classTerm}</Th>
                                <Th align="right">Charges</Th>
                                <Th align="right">Credits</Th>
                                <Th align="right">Balance</Th>
                                <Th>Status</Th>
                                <Th align="right">
                                    <span className="sr-only">Actions</span>
                                </Th>
                            </TableHead>
                            <TableBody>
                                {candidates.data.map((candidate) => (
                                    <Tr key={candidate.id}>
                                        <Td className="whitespace-nowrap font-medium text-ink" numeric>
                                            {candidate.candidateNumber}
                                        </Td>
                                        <Td className="text-ink">
                                            {candidate.name}
                                            {candidate.status.value !== 'enrolled' && (
                                                <span className="block text-xs text-ink-muted">{candidate.status.label}</span>
                                            )}
                                        </Td>
                                        <Td className="whitespace-nowrap text-ink-muted">{candidate.className ?? 'Not assigned'}</Td>
                                        <Td align="right" numeric className="whitespace-nowrap text-ink">
                                            {money(candidate.charges)}
                                        </Td>
                                        <Td align="right" numeric className="whitespace-nowrap text-ink">
                                            {money(candidate.credits)}
                                        </Td>
                                        <Td align="right" numeric className="whitespace-nowrap font-semibold text-ink">
                                            {money(candidate.balance)}
                                        </Td>
                                        <Td>
                                            <StatusBadge tone={candidate.balanceStatus.tone}>{candidate.balanceStatus.label}</StatusBadge>
                                        </Td>
                                        <Td align="right">
                                            <RowAction href={routes.accounts.show(candidate.id)} label={`Open the statement of account of ${candidate.name}`}>
                                                Statement
                                            </RowAction>
                                        </Td>
                                    </Tr>
                                ))}
                            </TableBody>
                        </Table>
                    )}

                    <Pagination page={candidates} noun={{ one: 'candidate', other: 'candidates' }} />
                </section>

                <p className="text-sm text-ink-muted">Balances count every entry that has not been voided. Amounts in parentheses are credit balances.</p>
            </div>
        </>
    );
}
