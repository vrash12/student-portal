import { ReceiptText } from 'lucide-react';
import { AmountCard } from '@/components/accounts/amount-card';
import type { AccountOverview } from '@/components/accounts/types';
import { ButtonLink } from '@/components/ui/button';
import { EmptyState } from '@/components/ui/empty-state';
import { Panel } from '@/components/ui/panel';
import { RowAction } from '@/components/ui/table';
import { formatCalendarDate } from '@/lib/format';
import { useMoney } from '@/lib/money';
import { routes } from '@/lib/routes';

function candidates(count: number): string {
    return `${count} ${count === 1 ? 'candidate' : 'candidates'}`;
}

/**
 * Dashboard panel for users who may view statements of account: totals of
 * the balances calculated by the server, and the latest entries.
 */
export function StatementsOverview({ overview }: { overview: AccountOverview }) {
    const money = useMoney();
    const isEmpty = overview.recentEntries.length === 0 && overview.candidatesDue === 0 && overview.candidatesInCredit === 0;

    return (
        <Panel
            title="Statements of Account"
            description="Balances over every entry that has not been voided. Amounts only; no payments are made through the system."
            actions={
                <ButtonLink href={routes.accounts.index()} variant="secondary">
                    Open Statements of Account
                </ButtonLink>
            }
        >
            {isEmpty ? (
                <EmptyState
                    icon={ReceiptText}
                    headingLevel="h3"
                    title="No entries recorded yet"
                    description="Charges and credits recorded on candidates’ statements of account are summarized here."
                />
            ) : (
                <div className="flex flex-col gap-5">
                    <dl className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <AmountCard
                            label="Total balance due"
                            amount={money(overview.totalDue)}
                            description={`${candidates(overview.candidatesDue)} with a balance due`}
                        />
                        <AmountCard
                            label="Total credit balances"
                            amount={money(overview.totalCredit)}
                            description={`${candidates(overview.candidatesInCredit)} in credit`}
                        />
                    </dl>
                    {overview.recentEntries.length > 0 && (
                        <section aria-labelledby="recent-account-entries-heading" className="-mx-5 -mb-5 border-t border-line">
                            <h3 id="recent-account-entries-heading" className="px-5 pt-4 text-sm font-semibold text-ink">
                                Latest Entries
                            </h3>
                            <ul className="divide-y divide-line">
                                {overview.recentEntries.map((entry) => (
                                    <li key={entry.id} className="flex flex-wrap items-center justify-between gap-3 px-5 py-3">
                                        <div className="min-w-0">
                                            <p className="font-medium text-ink">
                                                {entry.candidate.name} <span className="font-normal text-ink-muted">({entry.candidate.candidateNumber})</span>
                                            </p>
                                            <p className="text-sm text-ink-muted">
                                                {formatCalendarDate(entry.postedOn)} · {entry.category} · {entry.type.label}{' '}
                                                <span className="font-medium text-ink tabular-nums">{money(entry.amount)}</span>
                                            </p>
                                        </div>
                                        <RowAction href={routes.accounts.show(entry.candidate.id)} label={`Open the statement of account of ${entry.candidate.name}`}>
                                            Statement
                                        </RowAction>
                                    </li>
                                ))}
                            </ul>
                        </section>
                    )}
                </div>
            )}
        </Panel>
    );
}
