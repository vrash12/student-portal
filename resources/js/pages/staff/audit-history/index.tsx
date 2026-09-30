import { Head, useForm, usePage } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import { FormField, SelectInput, TextInput } from '@/components/ui/form-field';
import { PageHeader } from '@/components/ui/page-header';
import { Pagination } from '@/components/ui/pagination';
import type { Paginated } from '@/types';

interface Entry { id: number; actor: string; action: string; entity: string | null; entityId: number | null; timestamp: string; reason: string | null; before: Record<string, unknown> | null; after: Record<string, unknown> | null }
interface Props { entries: Paginated<Entry>; actors: { id: number; name: string }[]; actions: { value: string; label: string }[]; entities: string[]; filters: Partial<Record<'actor' | 'action' | 'entity' | 'entity_id' | 'from' | 'to', string>> }

export default function AuditHistory({ entries, actors, actions, entities, filters }: Props) {
    const { app } = usePage().props;
    const form = useForm({ actor: filters.actor ?? '', action: filters.action ?? '', entity: filters.entity ?? '', entity_id: filters.entity_id ?? '', from: filters.from ?? '', to: filters.to ?? '' });
    return <><Head title="Audit History" /><PageHeader title="Audit History" description="Read-only history of account, academic, question bank, and examination changes." />
        <form onSubmit={(e) => { e.preventDefault(); form.get('/audit-history'); }} className="mb-6 grid gap-4 rounded-xl border border-line bg-surface p-5 sm:grid-cols-2 lg:grid-cols-3">
            <FormField label="Actor"><SelectInput value={form.data.actor} onChange={(e) => form.setData('actor', e.target.value)}><option value="">All actors</option>{actors.map((a) => <option key={a.id} value={a.id}>{a.name}</option>)}</SelectInput></FormField>
            <FormField label="Action"><SelectInput value={form.data.action} onChange={(e) => form.setData('action', e.target.value)}><option value="">All actions</option>{actions.map((a) => <option key={a.value} value={a.value}>{a.label}</option>)}</SelectInput></FormField>
            <FormField label="Entity"><SelectInput value={form.data.entity} onChange={(e) => form.setData('entity', e.target.value)}><option value="">All entities</option>{entities.map((name) => <option key={name} value={name}>{name.replaceAll('_', ' ')}</option>)}</SelectInput></FormField>
            <FormField label="Entity ID" error={form.errors.entity_id}><TextInput type="number" min="1" value={form.data.entity_id} onChange={(e) => form.setData('entity_id', e.target.value)} /></FormField>
            <FormField label="From" error={form.errors.from}><TextInput type="date" value={form.data.from} onChange={(e) => form.setData('from', e.target.value)} /></FormField>
            <FormField label="Through" error={form.errors.to}><TextInput type="date" value={form.data.to} onChange={(e) => form.setData('to', e.target.value)} /></FormField>
            <div><Button type="submit" loading={form.processing}>Apply filters</Button></div>
        </form>
        <section className="overflow-hidden rounded-xl border border-line bg-surface" aria-label="Audit entries"><div className="overflow-x-auto"><table className="w-full min-w-[48rem] text-left text-sm"><thead className="border-b border-line bg-surface-muted"><tr>{['Time', 'Actor', 'Action', 'Entity', 'Change'].map((label) => <th key={label} className="p-4">{label}</th>)}</tr></thead><tbody>{entries.data.map((entry) => <tr key={entry.id} className="border-b border-line align-top"><td className="p-4 tabular-nums">{new Date(entry.timestamp).toLocaleString(undefined, { timeZone: app.timezone })}</td><td className="p-4">{entry.actor}</td><td className="p-4">{entry.action}</td><td className="p-4">{entry.entity?.replaceAll('_', ' ') ?? 'Session'} {entry.entityId ? `#${entry.entityId}` : ''}</td><td className="max-w-lg p-4">{entry.reason && <p className="mb-2">Reason: {entry.reason}</p>}<details><summary className="cursor-pointer font-medium text-primary-700 focus-visible:outline-2">View recorded changes</summary><dl className="mt-3 space-y-3"><div><dt className="font-semibold">Previous</dt><dd><pre className="whitespace-pre-wrap break-words text-xs">{entry.before ? JSON.stringify(entry.before, null, 2) : 'No previous values recorded'}</pre></dd></div><div><dt className="font-semibold">New</dt><dd><pre className="whitespace-pre-wrap break-words text-xs">{entry.after ? JSON.stringify(entry.after, null, 2) : 'No new values recorded'}</pre></dd></div></dl></details></td></tr>)}</tbody></table>{entries.total === 0 && <p className="p-8 text-center text-ink-muted">No audit entries match these filters.</p>}</div><Pagination page={entries} noun={{ one: 'entry', other: 'entries' }} /></section>
    </>;
}
