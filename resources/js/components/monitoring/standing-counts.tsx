import { Link } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { StatusBadge, type StatusTone } from '@/components/ui/status-badge';
import type { StandingCounts as Counts } from '@/types/monitoring';

type StandingKey = 'failing' | 'at_risk' | 'incomplete' | 'passing';

interface StandingCountsProps {
    counts: Counts;
    /** Label of the first card, e.g. "Monitored Candidates". */
    totalLabel: string;
    /** Which standing cards to show, in order. */
    standings: StandingKey[];
    /** Link of each standing card (the filtered monitoring list); no links when omitted. */
    hrefFor?: (standing: StandingKey) => string;
    /** The standing currently filtered on, marked with aria-current. */
    current?: string;
}

const cards: Record<StandingKey, { count: keyof Counts; label: string; tone: StatusTone }> = {
    failing: { count: 'failing', label: 'Failing', tone: 'danger' },
    at_risk: { count: 'atRisk', label: 'At Risk', tone: 'warning' },
    incomplete: { count: 'incomplete', label: 'Incomplete', tone: 'neutral' },
    passing: { count: 'passing', label: 'Passing', tone: 'success' },
};

/**
 * Candidate counts by standing (UI_UX_DESIGN.md §17-§18). Cards stay neutral;
 * each standing is named in text with its icon, never by color alone. The
 * total card also says how many have no standing yet, so the numbers always
 * add up.
 */
export function StandingCounts({ counts, totalLabel, standings, hrefFor, current }: StandingCountsProps) {
    const columns = standings.length + 1 >= 5 ? 'grid-cols-2 sm:grid-cols-3 xl:grid-cols-5' : 'grid-cols-1 sm:grid-cols-3';

    return (
        <dl className={`grid gap-4 ${columns}`}>
            <Card label={<span className="text-sm font-medium text-ink-muted">{totalLabel}</span>} value={counts.monitored}>
                {counts.noStanding > 0 && (
                    <dd className="mt-1 text-sm text-ink-subtle">
                        <span className="tabular-nums">{counts.noStanding}</span> with no standing yet
                    </dd>
                )}
            </Card>
            {standings.map((standing) => {
                const card = cards[standing];
                const value = counts[card.count];

                return (
                    <Card
                        key={standing}
                        label={<StatusBadge tone={card.tone}>{card.label}</StatusBadge>}
                        value={value}
                        href={hrefFor?.(standing)}
                        // Starts with the visible text (WCAG 2.5.3), e.g. "14 Failing candidates".
                        hrefLabel={`${value} ${card.label} ${value === 1 ? 'candidate' : 'candidates'}`}
                        isCurrent={current === standing}
                    />
                );
            })}
        </dl>
    );
}

interface CardProps {
    label: ReactNode;
    value: number;
    href?: string;
    hrefLabel?: string;
    isCurrent?: boolean;
    children?: ReactNode;
}

function Card({ label, value, href, hrefLabel, isCurrent = false, children }: CardProps) {
    return (
        <div className={`rounded-lg border bg-surface px-4 py-4 ${isCurrent ? 'border-primary-600' : 'border-line'}`}>
            <dt>{label}</dt>
            <dd className="mt-2 text-3xl font-semibold text-ink tabular-nums">
                {href === undefined ? (
                    value
                ) : (
                    <Link
                        href={href}
                        className="rounded-sm hover:underline"
                        aria-label={hrefLabel}
                        aria-current={isCurrent ? 'true' : undefined}
                        preserveScroll
                    >
                        {value}
                    </Link>
                )}
            </dd>
            {children}
        </div>
    );
}
