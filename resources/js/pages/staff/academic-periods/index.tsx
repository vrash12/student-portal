import { Head, Link, router } from '@inertiajs/react';
import { CalendarRange, Plus } from 'lucide-react';
import { useState } from 'react';
import { Button, ButtonLink } from '@/components/ui/button';
import { ClientPagination, useClientPagination } from '@/components/ui/client-pagination';
import { ConfirmDialog } from '@/components/ui/confirm-dialog';
import { EmptyState } from '@/components/ui/empty-state';
import { PageHeader } from '@/components/ui/page-header';
import { StatusBadge } from '@/components/ui/status-badge';
import { RowAction, Table, TableBody, TableHead, Td, Th, Tr } from '@/components/ui/table';
import { formatCalendarDate, formatGrade } from '@/lib/format';
import { routes } from '@/lib/routes';
import { terms } from '@/lib/terminology';
import type { GradingThresholds } from '@/types/grading';

interface PeriodRow {
    id: number;
    name: string;
    startsOn: string;
    endsOn: string;
    isActive: boolean;
    classCount: number;
    /** Passing and warning grades; null when not set up. */
    thresholds: GradingThresholds | null;
}

interface AcademicPeriodsIndexProps {
    periods: PeriodRow[];
    can: { configureGrading: boolean };
}

export default function AcademicPeriodsIndex({ periods, can }: AcademicPeriodsIndexProps) {
    const [activating, setActivating] = useState<PeriodRow | null>(null);
    const [processing, setProcessing] = useState(false);
    const currentPeriod = periods.find((period) => period.isActive) ?? null;
    const pagination = useClientPagination(periods);

    const createAction = (
        <ButtonLink href={routes.academicPeriods.create()} variant="primary" icon={<Plus className="size-4" aria-hidden="true" />}>
            Create Period
        </ButtonLink>
    );

    const activate = () => {
        if (activating === null) {
            return;
        }

        router.post(
            routes.academicPeriods.activate(activating.id),
            {},
            {
                preserveScroll: true,
                onStart: () => setProcessing(true),
                onFinish: () => {
                    setProcessing(false);
                    setActivating(null);
                },
            },
        );
    };

    return (
        <>
            <Head title="Academic Periods" />

            <PageHeader
                title="Academic Periods"
                description={`Terms or cycles that group ${terms.classBatch.plural.toLowerCase()}. One period is active at a time.`}
                actions={createAction}
            />

            <div className="rounded-lg border border-line bg-surface">
                {periods.length === 0 ? (
                    <EmptyState
                        icon={CalendarRange}
                        title="No academic periods yet"
                        description={`Create the first academic period, then add ${terms.classBatch.plural.toLowerCase()} to it.`}
                        action={createAction}
                    />
                ) : (
                    <Table caption="Academic periods">
                        <TableHead>
                            <Th>Period</Th>
                            <Th>Dates</Th>
                            <Th>Status</Th>
                            <Th>Grading Thresholds</Th>
                            <Th align="right">{terms.classBatch.plural}</Th>
                            <Th align="right">
                                <span className="sr-only">Actions</span>
                            </Th>
                        </TableHead>
                        <TableBody>
                            {pagination.rows.map((period) => (
                                <Tr key={period.id}>
                                    <Td className="font-medium text-ink">
                                        <Link href={routes.academicPeriods.show(period.id)} className="text-primary-700 underline">
                                            {period.name}
                                        </Link>
                                    </Td>
                                    <Td className="text-ink-muted">
                                        {formatCalendarDate(period.startsOn)} – {formatCalendarDate(period.endsOn)}
                                    </Td>
                                    <Td>
                                        {period.isActive ? (
                                            <StatusBadge tone="success">Active</StatusBadge>
                                        ) : (
                                            <StatusBadge tone="neutral">Inactive</StatusBadge>
                                        )}
                                    </Td>
                                    <Td>
                                        {period.thresholds === null ? (
                                            <StatusBadge tone="neutral">Not Set</StatusBadge>
                                        ) : (
                                            <span className="whitespace-nowrap text-ink">
                                                Passing <span className="tabular-nums">{formatGrade(period.thresholds.passingGrade)}</span> · Warning{' '}
                                                <span className="tabular-nums">{formatGrade(period.thresholds.warningGrade)}</span>
                                            </span>
                                        )}
                                    </Td>
                                    <Td align="right" numeric>
                                        {period.classCount}
                                    </Td>
                                    <Td align="right">
                                        <div className="flex justify-end gap-1">
                                            <RowAction href={routes.academicPeriods.show(period.id)} label={`View ${period.name}: classes, subjects and instructors`}>
                                                View
                                            </RowAction>
                                            {!period.isActive && (
                                                <Button variant="ghost" size="sm" onClick={() => setActivating(period)}>
                                                    Set Active
                                                </Button>
                                            )}
                                            {can.configureGrading && (
                                                <RowAction href={routes.academicPeriods.thresholds(period.id)} label={`Thresholds for ${period.name}`}>
                                                    Thresholds
                                                </RowAction>
                                            )}
                                            <RowAction href={routes.academicPeriods.edit(period.id)} label={`Edit ${period.name}`}>
                                                Edit
                                            </RowAction>
                                        </div>
                                    </Td>
                                </Tr>
                            ))}
                        </TableBody>
                    </Table>
                )}
                <ClientPagination pagination={pagination} noun={{ one: 'period', other: 'periods' }} label="Academic period pages" />
            </div>

            <ConfirmDialog
                open={activating !== null}
                tone="primary"
                title="Set the active academic period?"
                description={
                    <>
                        <p>{activating?.name} will become the active academic period.</p>
                        {currentPeriod && <p>{currentPeriod.name} will no longer be active.</p>}
                        <p>Class lists and dashboards show the active period by default.</p>
                    </>
                }
                confirmLabel="Set Active Period"
                processing={processing}
                onConfirm={activate}
                onCancel={() => setActivating(null)}
            />
        </>
    );
}
