import { Head, usePage } from '@inertiajs/react';
import { ListCharts, type ListChart } from '@/components/charts/list-charts';
import { History, SearchX } from 'lucide-react';
import { CampusFilter } from '@/components/academic/campus-filter';
import { AuditChangeList, humanizeKey, type AuditValues } from '@/components/audit/audit-change-list';
import { Button } from '@/components/ui/button';
import { EmptyState } from '@/components/ui/empty-state';
import { FilterBar } from '@/components/ui/filter-bar';
import { FormField, SelectInput, TextInput } from '@/components/ui/form-field';
import { PageHeader } from '@/components/ui/page-header';
import { Pagination } from '@/components/ui/pagination';
import { Table, TableBody, TableHead, Td, Th, Tr } from '@/components/ui/table';
import { useDateFormatter } from '@/lib/format';
import { useQueryFilters } from '@/lib/use-query-filters';
import type { CampusOption, Paginated } from '@/types';

interface Entry {
    id: number;
    actor: string;
    action: string;
    entity: string | null;
    entityId: number | null;
    timestamp: string;
    reason: string | null;
    before: AuditValues;
    after: AuditValues;
}

type FilterKey = 'campus' | 'actor' | 'action' | 'entity' | 'entity_id' | 'from' | 'to';

interface AuditFilters {
    campus: string;
    actor: string;
    action: string;
    entity: string;
    entity_id: string;
    from: string;
    to: string;
    [key: string]: string;
}

interface Props {
    /** Charts computed by the server from the filtered list. */
    charts: ListChart[];
    entries: Paginated<Entry>;
    actors: { id: number; name: string }[];
    actions: { value: string; label: string }[];
    entities: string[];
    filters: Partial<Record<FilterKey, string | number>>;
    /** Campuses to filter by (accounts that see every campus only). */
    campusOptions: CampusOption[];
}

const AUDIT_HISTORY_URL = '/audit-history';

export default function AuditHistory({ entries, actors, actions, entities, filters, campusOptions, charts }: Props) {
    const { values, update, updateMany, reset, isFiltered } = useQueryFilters<AuditFilters>(AUDIT_HISTORY_URL, {
        campus: String(filters.campus ?? ''),
        actor: String(filters.actor ?? ''),
        action: String(filters.action ?? ''),
        entity: String(filters.entity ?? ''),
        entity_id: String(filters.entity_id ?? ''),
        from: String(filters.from ?? ''),
        to: String(filters.to ?? ''),
    });
    const errors = usePage().props.errors as Record<string, string | undefined>;
    const dates = useDateFormatter();

    return (
        <>
            <Head title="Audit History" />

            <PageHeader
                title="Audit History"
                description="Read-only history of account, academic, question bank, and examination changes."
            />

            <ListCharts charts={charts} />

            <div className="rounded-lg border border-line-box bg-surface">
                <FilterBar onReset={reset} canReset={isFiltered}>
                    {/* Actors are listed per campus, so a new campus clears the actor. */}
                    <CampusFilter options={campusOptions} value={values.campus} onChange={(campus) => updateMany({ campus, actor: '' })} />
                    <FormField label="Actor" className="sm:w-52">
                        <SelectInput value={values.actor} onChange={(event) => update('actor', event.target.value)}>
                            <option value="">All actors</option>
                            {actors.map((actor) => (
                                <option key={actor.id} value={String(actor.id)}>
                                    {actor.name}
                                </option>
                            ))}
                        </SelectInput>
                    </FormField>
                    <FormField label="Action" className="sm:w-56">
                        <SelectInput value={values.action} onChange={(event) => update('action', event.target.value)}>
                            <option value="">All actions</option>
                            {actions.map((action) => (
                                <option key={action.value} value={action.value}>
                                    {action.label}
                                </option>
                            ))}
                        </SelectInput>
                    </FormField>
                    <FormField label="Entity" className="sm:w-48">
                        <SelectInput value={values.entity} onChange={(event) => update('entity', event.target.value)}>
                            <option value="">All entities</option>
                            {entities.map((name) => (
                                <option key={name} value={name}>
                                    {humanizeKey(name)}
                                </option>
                            ))}
                        </SelectInput>
                    </FormField>
                    <FormField label="Entity ID" error={errors.entity_id} className="sm:w-32">
                        <TextInput
                            type="number"
                            inputMode="numeric"
                            min="1"
                            value={values.entity_id}
                            onChange={(event) => update('entity_id', event.target.value, { debounce: true })}
                        />
                    </FormField>
                    <FormField label="From" error={errors.from} className="sm:w-44">
                        <TextInput type="date" value={values.from} onChange={(event) => update('from', event.target.value, { debounce: true })} />
                    </FormField>
                    <FormField label="Through" error={errors.to} className="sm:w-44">
                        <TextInput type="date" value={values.to} onChange={(event) => update('to', event.target.value, { debounce: true })} />
                    </FormField>
                </FilterBar>

                {entries.data.length === 0 ? (
                    isFiltered ? (
                        <EmptyState
                            icon={SearchX}
                            title="No audit entries match these filters"
                            description="Try a different actor, action, or date range, or reset the filters to see every entry."
                            action={
                                <Button variant="secondary" onClick={reset}>
                                    Reset Filters
                                </Button>
                            }
                        />
                    ) : (
                        <EmptyState
                            icon={History}
                            title="No audit entries yet"
                            description="Sign-ins and changes to accounts, academic records, questions, and examinations are recorded here."
                        />
                    )
                ) : (
                    <Table caption="Audit entries, newest first">
                        <TableHead>
                            <Th>Time</Th>
                            <Th>Actor</Th>
                            <Th>Action</Th>
                            <Th>Entity</Th>
                            <Th>Changes</Th>
                        </TableHead>
                        <TableBody>
                            {entries.data.map((entry) => (
                                <Tr key={entry.id}>
                                    <Td className="whitespace-nowrap align-top text-ink-muted" numeric>
                                        <time dateTime={entry.timestamp}>{dates.dateTime(entry.timestamp)}</time>
                                    </Td>
                                    <Td className="align-top text-ink">{entry.actor}</Td>
                                    <Td className="align-top font-medium text-ink">{entry.action}</Td>
                                    <Td className="whitespace-nowrap align-top text-ink-muted">
                                        {entry.entity ? humanizeKey(entry.entity) : 'Session'}
                                        {entry.entityId !== null && <span className="tabular-nums"> #{entry.entityId}</span>}
                                    </Td>
                                    <Td className="min-w-80 align-top">
                                        {entry.reason && (
                                            <p className="mb-2 text-ink">
                                                <span className="font-medium">Reason:</span> {entry.reason}
                                            </p>
                                        )}
                                        <details>
                                            <summary className="inline-flex min-h-9 cursor-pointer items-center rounded-md font-medium text-primary-700 hover:underline focus-visible:outline-2 focus-visible:outline-primary-600 pointer-coarse:min-h-11">
                                                View recorded changes
                                            </summary>
                                            <div className="mt-2 rounded-md border border-line bg-surface-muted px-3 py-1">
                                                <AuditChangeList
                                                    before={entry.before}
                                                    after={entry.after}
                                                    caption={`Recorded changes: ${entry.action} by ${entry.actor}`}
                                                />
                                            </div>
                                        </details>
                                    </Td>
                                </Tr>
                            ))}
                        </TableBody>
                    </Table>
                )}

                <Pagination page={entries} noun={{ one: 'entry', other: 'entries' }} />
            </div>
        </>
    );
}
