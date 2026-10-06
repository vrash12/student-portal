import { Head, Link } from '@inertiajs/react';
import { CalendarDays, Plus } from 'lucide-react';
import { CampusFilter } from '@/components/academic/campus-filter';
import { WeekCalendar, WeekNavigation } from '@/components/schedule/week-calendar';
import { ButtonLink } from '@/components/ui/button';
import { EmptyState } from '@/components/ui/empty-state';
import { FilterBar } from '@/components/ui/filter-bar';
import { FormField, SelectInput } from '@/components/ui/form-field';
import { PageHeader } from '@/components/ui/page-header';
import { cn } from '@/lib/cn';
import { routes } from '@/lib/routes';
import { terms } from '@/lib/terminology';
import { useQueryFilters } from '@/lib/use-query-filters';
import type { CampusOption } from '@/types';
import type { ScheduleDay, ScheduleWeekInfo } from '@/types/schedule';

interface ScheduleIndexProps {
    week: ScheduleWeekInfo;
    days: ScheduleDay[];
    /** "mine": the instructor's own week; "class": one class's week. */
    view: 'mine' | 'class';
    filters: { period: string; campus: string; class: string; week: string };
    campusOptions: CampusOption[];
    periods: Array<{ id: number; name: string; isActive: boolean }>;
    classes: Array<{ id: number; name: string }>;
    can: { teach: boolean; create: boolean };
}

/**
 * The training schedule (owner request, 2026-10-06): a class's week, or an
 * instructor's own, with examinations, fitness tests and attendance sessions.
 */
export default function ScheduleIndex({ week, days, view, filters, campusOptions, periods, classes, can }: ScheduleIndexProps) {
    const { singular, plural } = terms.classBatch;
    const { values, update, updateMany } = useQueryFilters(routes.schedule.index(), {
        view,
        period: filters.period,
        campus: filters.campus,
        class: filters.class,
        week: filters.week,
    });
    const defaultPeriod = String(periods[0]?.id ?? '');
    const canReset = values.period !== defaultPeriod || values.campus !== '';
    const hrefFor = (date: string) => routes.schedule.index({ view, period: filters.period, campus: filters.campus, class: filters.class, week: date });
    const itemCount = days.reduce((total, day) => total + day.items.length, 0);

    const addAction = can.create && (
        <ButtonLink
            href={routes.schedule.entries.create({ class: view === 'class' ? filters.class : '', date: week.isCurrent ? week.today : week.start })}
            variant="primary"
            icon={<Plus className="size-4" aria-hidden="true" />}
        >
            Add to Schedule
        </ButtonLink>
    );

    return (
        <>
            <Head title="Training Schedule" />

            <PageHeader
                title="Training Schedule"
                description={
                    view === 'mine'
                        ? 'Your week: the sessions you lead or that are in the subjects you teach, and their examinations.'
                        : `The week of one ${singular.toLowerCase()}: its sessions, examinations, fitness tests and attendance sessions.`
                }
                actions={addAction}
            />

            {can.teach && (
                <div role="tablist" aria-label="Schedule view" className="mb-4 inline-flex rounded-lg border border-line-box bg-surface p-1">
                    {(['mine', 'class'] as const).map((option) => (
                        <Link
                            key={option}
                            role="tab"
                            aria-selected={view === option}
                            href={routes.schedule.index({ view: option, period: filters.period, campus: filters.campus, class: filters.class, week: filters.week })}
                            preserveScroll
                            className={cn(
                                'rounded-md px-4 py-2 text-sm font-medium focus-visible:outline focus-visible:outline-2 focus-visible:outline-primary-600',
                                view === option ? 'bg-primary-700 text-white' : 'text-ink hover:bg-surface-muted',
                            )}
                        >
                            {option === 'mine' ? 'My Schedule' : `By ${singular}`}
                        </Link>
                    ))}
                </div>
            )}

            <section className="flex flex-col gap-4 rounded-lg border border-line-box bg-surface" aria-label="Week">
                {view === 'class' && (
                    <FilterBar onReset={() => updateMany({ period: defaultPeriod, campus: '', class: '' })} canReset={canReset}>
                        <FormField label="Academic period" className="sm:w-56">
                            <SelectInput value={values.period} onChange={(event) => updateMany({ period: event.target.value, class: '' })}>
                                {periods.length === 0 && <option value="">No academic periods</option>}
                                {periods.map((period) => (
                                    <option key={period.id} value={String(period.id)}>
                                        {period.name}
                                        {period.isActive ? ' (active)' : ''}
                                    </option>
                                ))}
                            </SelectInput>
                        </FormField>
                        <CampusFilter options={campusOptions} value={values.campus} onChange={(campus) => updateMany({ campus, class: '' })} />
                        <FormField label={singular} className="sm:w-56">
                            <SelectInput value={values.class} onChange={(event) => update('class', event.target.value)} disabled={classes.length === 0}>
                                {classes.length === 0 && <option value="">No {plural.toLowerCase()}</option>}
                                {classes.map((classBatch) => (
                                    <option key={classBatch.id} value={String(classBatch.id)}>
                                        {classBatch.name}
                                    </option>
                                ))}
                            </SelectInput>
                        </FormField>
                    </FilterBar>
                )}

                <div className="flex flex-col gap-4 p-4 pt-0 first:pt-4">
                    <WeekNavigation week={week} hrefFor={hrefFor} />

                    {view === 'class' && classes.length === 0 ? (
                        <EmptyState
                            icon={CalendarDays}
                            title={`No ${plural.toLowerCase()} to show`}
                            description={`There are no ${plural.toLowerCase()} in the selected period that you can see.`}
                        />
                    ) : (
                        <>
                            {itemCount === 0 && (
                                <p className="rounded-md border border-dashed border-line-strong/60 px-4 py-3 text-sm text-ink-muted">
                                    Nothing is scheduled this week.
                                    {can.create ? ' Use Add to Schedule to plan sessions; weekly sessions repeat on the same weekday.' : ''}
                                </p>
                            )}
                            <WeekCalendar days={days} today={week.today} showClass={view === 'mine'} sevenColumnsFrom="2xl" />
                        </>
                    )}
                </div>
            </section>
        </>
    );
}
