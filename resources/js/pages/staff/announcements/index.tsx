import { Head } from '@inertiajs/react';
import { Ban, Megaphone, Pencil, Plus, Star } from 'lucide-react';
import { CampusFilter } from '@/components/academic/campus-filter';
import { ButtonLink } from '@/components/ui/button';
import { ConfirmAction } from '@/components/ui/confirm-action';
import { EmptyState } from '@/components/ui/empty-state';
import { FilterBar } from '@/components/ui/filter-bar';
import { FormField, SelectInput } from '@/components/ui/form-field';
import { PageHeader } from '@/components/ui/page-header';
import { Pagination } from '@/components/ui/pagination';
import { StatusBadge } from '@/components/ui/status-badge';
import { useDateFormatter } from '@/lib/format';
import { routes } from '@/lib/routes';
import { useQueryFilters } from '@/lib/use-query-filters';
import type { CampusOption, Paginated } from '@/types';
import type { AnnouncementListItem } from '@/types/announcements';

type StatusFilter = 'active' | 'current' | 'scheduled' | 'expired' | 'withdrawn' | 'all';

interface AnnouncementsIndexProps {
    announcements: Paginated<AnnouncementListItem>;
    filters: { status: StatusFilter; campus: string };
    /** Campuses to filter by (accounts that see every campus only). */
    campusOptions: CampusOption[];
    /** "all": the user manages every notice of their campuses; "own": only their own. */
    scope: 'all' | 'own';
}

const STATUS_OPTIONS: Array<{ value: StatusFilter; label: string }> = [
    { value: 'active', label: 'Showing and scheduled' },
    { value: 'current', label: 'Showing now' },
    { value: 'scheduled', label: 'Scheduled' },
    { value: 'expired', label: 'Ended' },
    { value: 'withdrawn', label: 'Withdrawn' },
    { value: 'all', label: 'All notices' },
];

/**
 * Notices to candidates (owner request, 2026-10-06). Candidates see the
 * notices meant for them on their portal home page while they are showing.
 */
export default function AnnouncementsIndex({ announcements, filters, campusOptions, scope }: AnnouncementsIndexProps) {
    const dates = useDateFormatter();
    const { values, update, updateMany } = useQueryFilters(routes.announcements.index(), { status: filters.status, campus: filters.campus });
    const canReset = values.status !== 'active' || values.campus !== '';

    const postAction = (
        <ButtonLink href={routes.announcements.create()} variant="primary" icon={<Plus className="size-4" aria-hidden="true" />}>
            Post Notice
        </ButtonLink>
    );

    return (
        <>
            <Head title="Announcements" />

            <PageHeader
                title="Announcements"
                description={
                    scope === 'all'
                        ? 'Notices to candidates. Candidates see the notices meant for them on their portal home page while they are showing.'
                        : 'Notices to the candidates of the classes you teach. You can change or withdraw the notices you posted.'
                }
                actions={postAction}
            />

            <section className="rounded-lg border border-line-box bg-surface" aria-label="Notices">
                <FilterBar onReset={() => updateMany({ status: 'active', campus: '' })} canReset={canReset}>
                    <FormField label="Status" className="sm:w-60">
                        <SelectInput value={values.status} onChange={(event) => update('status', event.target.value as StatusFilter)}>
                            {STATUS_OPTIONS.map((option) => (
                                <option key={option.value} value={option.value}>
                                    {option.label}
                                </option>
                            ))}
                        </SelectInput>
                    </FormField>
                    <CampusFilter options={campusOptions} value={values.campus} onChange={(campus) => update('campus', campus)} />
                </FilterBar>

                {announcements.data.length === 0 ? (
                    <EmptyState
                        icon={Megaphone}
                        title="No notices"
                        description={
                            values.status === 'active'
                                ? 'No notice is showing or scheduled. Post one to tell candidates about changes, schedules and reminders.'
                                : 'No notices match the filters.'
                        }
                        action={values.status === 'active' ? postAction : undefined}
                    />
                ) : (
                    <ul className="divide-y divide-line">
                        {announcements.data.map((announcement) => (
                            <li key={announcement.id} className="flex flex-col gap-3 p-4 sm:p-5">
                                <div className="flex flex-wrap items-start justify-between gap-3">
                                    <div className="min-w-0">
                                        <h2 className="text-base font-semibold text-ink">{announcement.title}</h2>
                                        <p className="mt-0.5 text-sm text-ink-muted">
                                            To {announcement.audience.label} · Posted by {announcement.postedBy}
                                        </p>
                                    </div>
                                    <div className="flex flex-wrap items-center gap-2">
                                        {announcement.important && (
                                            <StatusBadge tone="warning">
                                                <Star className="size-3" aria-hidden="true" />
                                                Important
                                            </StatusBadge>
                                        )}
                                        <StatusBadge tone={announcement.status.tone}>{announcement.status.label}</StatusBadge>
                                    </div>
                                </div>

                                <p className="line-clamp-4 whitespace-pre-line text-sm text-ink">{announcement.body}</p>

                                <div className="flex flex-wrap items-center justify-between gap-3">
                                    <p className="text-xs text-ink-muted">
                                        {announcement.status.value === 'scheduled' ? 'Shows from' : 'Shown from'} {dates.dateTime(announcement.publishesAt)}
                                        {announcement.expiresAt !== null ? ` until ${dates.dateTime(announcement.expiresAt)}` : ' (no end date)'}
                                        {announcement.withdrawnAt !== null && ` · Withdrawn ${dates.dateTime(announcement.withdrawnAt)}`}
                                    </p>
                                    {announcement.canManage && (
                                        <div className="flex flex-wrap gap-2">
                                            <ButtonLink href={routes.announcements.edit(announcement.id)} size="sm" icon={<Pencil className="size-4" aria-hidden="true" />} aria-label={`Edit ${announcement.title}`}>
                                                Edit
                                            </ButtonLink>
                                            <ConfirmAction
                                                method="post"
                                                href={routes.announcements.withdraw(announcement.id)}
                                                icon={<Ban className="size-4" aria-hidden="true" />}
                                                ariaLabel={`Withdraw ${announcement.title}`}
                                                title="Withdraw this notice?"
                                                description={`"${announcement.title}" will no longer show to candidates. It stays in this list as withdrawn and cannot be shown again.`}
                                                confirmLabel="Withdraw Notice"
                                            >
                                                Withdraw
                                            </ConfirmAction>
                                        </div>
                                    )}
                                </div>
                            </li>
                        ))}
                    </ul>
                )}

                <Pagination page={announcements} noun={{ one: 'notice', other: 'notices' }} />
            </section>
        </>
    );
}
