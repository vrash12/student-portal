import { Head } from '@inertiajs/react';
import { CalendarDays, CircleCheck, CircleMinus, CircleX, Dumbbell, Gauge, TrendingUp, Trophy } from 'lucide-react';
import { BarList } from '@/components/charts/bar-list';
import { PortalEmpty, PortalHeading, PortalSection, StatTile } from '@/components/portal/portal-ui';
import { StatusBadge, type StatusTone } from '@/components/ui/status-badge';
import { formatCalendarDate, formatGrade, formatPoints } from '@/lib/format';
import type { CandidateFitnessTest } from '@/types/fitness';

interface FitnessProps {
    candidate: { name: string; number: string; className: string | null };
    /** Own fitness tests, newest first; points and outcomes come from the server. */
    tests: CandidateFitnessTest[];
}

const points = (value: number) => `${formatGrade(value)} pts`;

/** A passing-points line for a chart when every event of the test shares the same passing points (set per event on the server). */
function passingReference(test: CandidateFitnessTest): Array<{ label: string; value: number }> {
    const values = [...new Set(test.events.map((event) => event.passingPoints))];

    return values.length === 1 && values[0] !== undefined ? [{ label: `Passing points (${formatPoints(values[0])})`, value: values[0] }] : [];
}

/** Candidate "Physical Fitness": own fitness tests, event by event, and progress across tests. */
export default function PortalFitness({ tests }: FitnessProps) {
    const [latest, ...earlier] = tests;

    return (
        <>
            <Head title="Physical Fitness" />
            <PortalHeading
                icon={Dumbbell}
                title="Physical Fitness"
                description="Your fitness test results. Each result earns points up to 100; every event must reach its passing points."
            />

            {latest === undefined ? (
                <PortalEmpty icon={Dumbbell} title="No fitness tests yet">
                    Your results appear here after your class takes a fitness test.
                </PortalEmpty>
            ) : (
                <div className="flex flex-col gap-10">
                    <PortalSection icon={Trophy} title={latest.title} description={`Latest test · ${formatCalendarDate(latest.testedOn)} · ${latest.classBatch}`}>
                        <div className="flex flex-col gap-8">
                            <dl className="grid gap-5 sm:grid-cols-2">
                                <div className="rounded-xl border border-line-box bg-surface-muted/60 p-5">
                                    <dt className="text-sm font-medium text-ink-muted">Result</dt>
                                    <dd className="mt-3">
                                        <StatusBadge tone={latest.outcome.status.tone as StatusTone} className="px-3 py-1.5 text-base">
                                            {latest.outcome.status.label}
                                        </StatusBadge>
                                    </dd>
                                </div>
                                <StatTile
                                    icon={Gauge}
                                    label="Overall Score"
                                    value={latest.outcome.points === null ? '—' : points(latest.outcome.points)}
                                    hint={latest.outcome.points === null ? 'Shown once every event is recorded.' : 'Mean of your event points.'}
                                />
                            </dl>

                            <BarList
                                bars={latest.events.map((event) => ({ label: event.name, value: event.result?.points ?? null }))}
                                references={passingReference(latest)}
                                formatValue={points}
                                emptyValue="Not recorded"
                            />

                            <ul className="grid gap-5 md:grid-cols-2">
                                {latest.events.map((event) => (
                                    <li key={event.id} className="flex flex-col gap-3 rounded-xl border border-line-box p-5">
                                        <div className="flex items-start justify-between gap-3">
                                            <p className="text-lg font-semibold text-primary-900">{event.name}</p>
                                            <EventOutcome passed={event.result?.passed ?? null} />
                                        </div>
                                        <p className="text-3xl font-bold text-ink tabular-nums">{event.result?.display ?? '—'}</p>
                                        <p className="text-sm text-ink-muted">
                                            Passing {event.passingDisplay} ({formatPoints(event.passingPoints)} pts) · Best {event.maximumDisplay}
                                            {event.result !== null && ` · ${points(event.result.points)}`}
                                        </p>
                                    </li>
                                ))}
                            </ul>
                        </div>
                    </PortalSection>

                    {earlier.length > 0 && (
                        <PortalSection icon={TrendingUp} title="My Progress" description="Overall score of each test, oldest first. Incomplete tests have no overall score.">
                            <div className="flex flex-col gap-8">
                                <BarList
                                    bars={[...tests].reverse().map((test) => ({ label: `${test.title} · ${formatCalendarDate(test.testedOn)}`, value: test.outcome.points }))}
                                    references={passingReference(latest)}
                                    formatValue={points}
                                    emptyValue="Incomplete"
                                />
                                <ul className="divide-y divide-line rounded-xl border border-line-box">
                                    {earlier.map((test) => (
                                        <li key={test.id} className="flex flex-wrap items-center justify-between gap-3 px-5 py-4">
                                            <div className="min-w-0">
                                                <p className="text-base font-semibold text-ink">{test.title}</p>
                                                <p className="inline-flex items-center gap-1.5 text-sm text-ink-muted">
                                                    <CalendarDays className="size-4" aria-hidden="true" />
                                                    {formatCalendarDate(test.testedOn)}
                                                </p>
                                            </div>
                                            <div className="flex items-center gap-4">
                                                {test.outcome.points !== null && <span className="text-lg font-bold text-ink tabular-nums">{points(test.outcome.points)}</span>}
                                                <StatusBadge tone={test.outcome.status.tone as StatusTone}>{test.outcome.status.label}</StatusBadge>
                                            </div>
                                        </li>
                                    ))}
                                </ul>
                            </div>
                        </PortalSection>
                    )}
                </div>
            )}
        </>
    );
}

/** An event's outcome in words with an icon. */
function EventOutcome({ passed }: { passed: boolean | null }) {
    if (passed === null) {
        return (
            <span className="inline-flex items-center gap-1.5 text-sm font-medium text-ink-muted">
                <CircleMinus className="size-5" aria-hidden="true" />
                Not recorded
            </span>
        );
    }

    return passed ? (
        <span className="inline-flex items-center gap-1.5 text-sm font-semibold text-success-fg">
            <CircleCheck className="size-5" aria-hidden="true" />
            Passed
        </span>
    ) : (
        <span className="inline-flex items-center gap-1.5 text-sm font-semibold text-danger-fg">
            <CircleX className="size-5" aria-hidden="true" />
            Below standard
        </span>
    );
}
