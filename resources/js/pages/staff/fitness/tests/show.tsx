import { Head, router } from '@inertiajs/react';
import { Pencil, Trash2 } from 'lucide-react';
import { useState } from 'react';
import { BarList } from '@/components/charts/bar-list';
import { ChartFigure } from '@/components/charts/chart-figure';
import { ColumnChart } from '@/components/charts/column-chart';
import { ResultsSheet } from '@/components/fitness/results-sheet';
import { Button, ButtonLink } from '@/components/ui/button';
import { ConfirmDialog } from '@/components/ui/confirm-dialog';
import { PageHeader } from '@/components/ui/page-header';
import { Panel } from '@/components/ui/panel';
import { StatusBadge, type StatusTone } from '@/components/ui/status-badge';
import { formatCalendarDate, formatGrade } from '@/lib/format';
import { routes } from '@/lib/routes';
import type { FitnessSheetRow, FitnessSummary, FitnessTestEvent } from '@/types/fitness';

interface FitnessTestShowProps {
    test: {
        id: number;
        title: string;
        testedOn: string;
        notes: string | null;
        classBatch: { id: number; name: string; period: string };
        createdBy: string;
    };
    events: FitnessTestEvent[];
    rows: FitnessSheetRow[];
    summary: FitnessSummary;
    can: { manage: boolean; delete: boolean; viewCandidates: boolean };
}

const outcomeCards: Array<{ key: keyof FitnessSummary['counts']; label: string; tone: StatusTone }> = [
    { key: 'passed', label: 'Passed', tone: 'success' },
    { key: 'failed', label: 'Failed', tone: 'danger' },
    { key: 'incomplete', label: 'Incomplete', tone: 'warning' },
    { key: 'not_tested', label: 'Not Tested', tone: 'neutral' },
];

export default function FitnessTestShow({ test, events, rows, summary, can }: FitnessTestShowProps) {
    const [confirmingDelete, setConfirmingDelete] = useState(false);
    const [deleting, setDeleting] = useState(false);
    const hasResults = summary.counts.passed + summary.counts.failed + summary.counts.incomplete > 0;

    const remove = () => {
        router.delete(routes.fitness.tests.destroy(test.id), {
            onStart: () => setDeleting(true),
            onFinish: () => {
                setDeleting(false);
                setConfirmingDelete(false);
            },
        });
    };

    return (
        <>
            <Head title={test.title} />

            <PageHeader
                title={test.title}
                description={`${test.classBatch.name} · ${test.classBatch.period} · ${formatCalendarDate(test.testedOn)}`}
                breadcrumbs={[{ label: 'Military Fitness', href: routes.fitness.index() }, { label: test.title }]}
                actions={
                    can.manage && (
                        <>
                            {can.delete && (
                                <Button variant="secondary" icon={<Trash2 className="size-4" aria-hidden="true" />} onClick={() => setConfirmingDelete(true)}>
                                    Delete Test
                                </Button>
                            )}
                            <ButtonLink href={routes.fitness.tests.edit(test.id)} icon={<Pencil className="size-4" aria-hidden="true" />}>
                                Edit Details
                            </ButtonLink>
                        </>
                    )
                }
            />

            <div className="flex flex-col gap-6">
                <Panel
                    title="Summary"
                    description={`${rows.length} ${rows.length === 1 ? 'candidate' : 'candidates'}. Failing any event fails the test; the overall score is the mean of the event points.`}
                >
                    <div className="flex flex-col gap-6">
                        <dl className="grid grid-cols-2 gap-4 lg:grid-cols-5">
                            {outcomeCards.map((card) => (
                                <div key={card.key} className="rounded-lg border border-line-box bg-surface px-4 py-4">
                                    <dt>
                                        <StatusBadge tone={card.tone}>{card.label}</StatusBadge>
                                    </dt>
                                    <dd className="mt-2 text-3xl font-semibold text-ink tabular-nums">{summary.counts[card.key]}</dd>
                                </div>
                            ))}
                            <div className="rounded-lg border border-line-box bg-surface px-4 py-4">
                                <dt className="text-sm font-medium text-ink-muted">Mean overall score</dt>
                                <dd className="mt-2 text-3xl font-semibold text-ink tabular-nums">{formatGrade(summary.meanPoints)}</dd>
                            </div>
                        </dl>

                        {hasResults && (
                            <div className="grid gap-8 xl:grid-cols-2">
                                <ChartFigure title="Pass Rate by Event" description="Share of recorded results meeting each event's passing standard.">
                                    <BarList
                                        bars={summary.eventPassRates.map((event) => ({ label: event.label, value: event.value }))}
                                        emptyValue="No results yet"
                                        formatValue={(value) => `${Math.round(value)}%`}
                                    />
                                </ChartFigure>
                                {summary.pointsDistribution.length > 0 && (
                                    <ChartFigure title="Overall Scores" description="Candidates with every event recorded, by overall points.">
                                        <ColumnChart columns={summary.pointsDistribution} noun={{ one: 'candidate', other: 'candidates' }} />
                                    </ChartFigure>
                                )}
                            </div>
                        )}

                        {test.notes && (
                            <p className="text-sm text-ink-muted">
                                <span className="font-medium text-ink">Notes:</span> {test.notes}
                            </p>
                        )}
                        <p className="text-xs text-ink-subtle">Created by {test.createdBy}. Standards are those in effect when the test was created.</p>
                    </div>
                </Panel>

                <Panel
                    title="Results"
                    description={
                        can.manage
                            ? 'Enter repetitions as whole numbers and times as minutes:seconds (for example 12:30). Clear a cell to remove a result. Points are calculated when saved.'
                            : 'Raw results with the points and outcome calculated from the test standards.'
                    }
                    bodyClassName="p-0"
                >
                    <ResultsSheet testId={test.id} events={events} rows={rows} editable={can.manage} linkCandidates={can.viewCandidates} />
                </Panel>
            </div>

            <ConfirmDialog
                open={confirmingDelete}
                title={`Delete ${test.title}?`}
                description={<p>The test has no recorded results. Deleting it removes the test; the deletion is kept in the audit log.</p>}
                confirmLabel="Delete Test"
                processing={deleting}
                onConfirm={remove}
                onCancel={() => setConfirmingDelete(false)}
            />
        </>
    );
}
