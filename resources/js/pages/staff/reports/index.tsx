import { Head, useForm, usePage } from '@inertiajs/react';
import { Button, ButtonLink } from '@/components/ui/button';
import { FormField, SelectInput, TextInput } from '@/components/ui/form-field';
import { PageHeader } from '@/components/ui/page-header';
import { Pagination } from '@/components/ui/pagination';
import type { Paginated } from '@/types';

type Row = Record<string, string | number | null>;
interface Option { id: number; name: string }
interface Filters { type: string; period: number | string; class: number | string; subject: number | string; search: string; from: string; to: string }
interface Props { columns: Record<string, string>; rows: Paginated<Row>; filters: Filters; periods: Option[]; classes: Option[]; subjects: Option[]; types: Record<string, string>; scope: string; generatedAt: string; printMode: boolean }

export default function Reports({ columns, rows, filters, periods, classes, subjects, types, scope, generatedAt, printMode }: Props) {
    const { app } = usePage().props;
    const form = useForm({ ...filters });
    const results = ['examination', 'quiz'].includes(filters.type);
    const printQuery = new URLSearchParams(Object.entries(filters).map(([key, value]) => [key, String(value ?? '')]));
    printQuery.set('print', '1');
    return <>
        <Head title="Reports" /><style>{`@media print { @page { size: landscape; margin: 12mm; } #main-content { padding: 0; } table { font-size: 9pt; } th, td { padding: 6pt; } }`}</style>
        <div className="print:hidden"><PageHeader title="Reports" description="Academic standing and assessment results within your authorized subjects." actions={printMode ? <><ButtonLink href={`/reports?${new URLSearchParams(Object.entries(filters).map(([k, v]) => [k, String(v ?? '')]))}`} variant="secondary">Back to report</ButtonLink><Button onClick={() => window.print()}>Print / save PDF</Button></> : <ButtonLink href={`/reports?${printQuery}`} variant="secondary">Printable report</ButtonLink>} /></div>
        {!printMode && <form onSubmit={(event) => { event.preventDefault(); form.get('/reports'); }} className="mb-6 grid gap-4 rounded-xl border border-line bg-surface p-5 sm:grid-cols-2 lg:grid-cols-4">
            <FormField label="Report"><SelectInput value={form.data.type} onChange={(e) => form.setData('type', e.target.value)}>{Object.entries(types).map(([value, label]) => <option key={value} value={value}>{label}</option>)}</SelectInput></FormField>
            <FormField label="Academic period" error={form.errors.period}><SelectInput value={form.data.period} onChange={(e) => { form.setData('period', e.target.value); form.setData('class', ''); form.setData('subject', ''); }}>{periods.length === 0 && <option value="">No available periods</option>}{periods.map((p) => <option key={p.id} value={p.id}>{p.name}</option>)}</SelectInput></FormField>
            <FormField label="Class / batch"><SelectInput value={form.data.class} onChange={(e) => form.setData('class', e.target.value)}><option value="">All authorized classes</option>{classes.map((p) => <option key={p.id} value={p.id}>{p.name}</option>)}</SelectInput></FormField>
            <FormField label="Subject"><SelectInput value={form.data.subject} onChange={(e) => form.setData('subject', e.target.value)}><option value="">All authorized subjects</option>{subjects.map((p) => <option key={p.id} value={p.id}>{p.name}</option>)}</SelectInput></FormField>
            <FormField label="Search report" error={form.errors.search}><TextInput value={form.data.search ?? ''} onChange={(e) => form.setData('search', e.target.value)} maxLength={100} /></FormField>
            {['examination', 'quiz'].includes(form.data.type) && <><FormField label="Submitted from" error={form.errors.from}><TextInput type="date" value={form.data.from ?? ''} onChange={(e) => form.setData('from', e.target.value)} /></FormField><FormField label="Submitted through" error={form.errors.to}><TextInput type="date" value={form.data.to ?? ''} onChange={(e) => form.setData('to', e.target.value)} /></FormField></>}
            <div className="flex items-end"><Button type="submit" loading={form.processing}>Apply filters</Button></div>
        </form>}
        <section className="rounded-xl border border-line bg-surface print:border-0" aria-labelledby="report-title">
            <div className="border-b border-line p-5"><h2 id="report-title" className="text-lg font-semibold">{types[filters.type]}</h2><p className="mt-1 text-sm text-ink-muted">{periods.find((p) => p.id === Number(filters.period))?.name ?? 'No academic period'} · {filters.class ? classes.find((c) => c.id === Number(filters.class))?.name : 'All authorized classes'} · {filters.subject ? subjects.find((s) => s.id === Number(filters.subject))?.name : 'All authorized subjects'}</p>
                <p className="mt-1 text-sm text-ink-muted">Generated {new Date(generatedAt).toLocaleString(undefined, { timeZone: app.timezone })} ({app.timezone}) · {rows.total} records{filters.search ? ` · Search: ${filters.search}` : ''}</p>
                <p className="mt-2 text-sm text-ink-muted">{results ? `Every submitted or expired attempt is listed separately. Submission dates: ${filters.from || 'any'} through ${filters.to || 'any'}. Scores awaiting review are not final.` : 'Current weighted grades from finalized assessments. Provisional grades are included; configured period thresholds determine standing. This is a current snapshot, not a historical as-of report.'} {scope === 'taught' && 'Standing covers only the subjects you teach.'}</p>
                {printMode && rows.total > rows.data.length && <p role="alert" className="mt-2 font-medium text-danger-fg">This printout is limited to {rows.data.length} rows. Narrow the filters for a complete report.</p>}
            </div>
            <div className="overflow-x-auto print:overflow-visible"><table className="w-full text-left text-sm"><caption className="sr-only">{types[filters.type]}</caption><thead className="border-b border-line bg-surface-muted"><tr>{Object.entries(columns).map(([key, label]) => <th key={key} className="px-4 py-3 font-semibold">{label}</th>)}</tr></thead><tbody>{rows.data.map((row, index) => <tr key={index} className="border-b border-line last:border-0 print:break-inside-avoid">{Object.keys(columns).map((key) => <td key={key} className="px-4 py-3 align-top tabular-nums">{row[key] ?? '—'}</td>)}</tr>)}</tbody></table>{rows.total === 0 && <p className="p-8 text-center text-ink-muted">No records match these filters.</p>}</div>
            {!printMode && <Pagination page={rows} noun={{ one: 'record', other: 'records' }} />}
        </section>
    </>;
}
