import { Head, usePage } from '@inertiajs/react';
import { Download, FileChartColumn, SearchX } from 'lucide-react';
import { CampusFilter } from '@/components/academic/campus-filter';
import { ReportCharts } from '@/components/reports/report-charts';
import { ReportTable } from '@/components/reports/report-table';
import { Button, buttonClasses } from '@/components/ui/button';
import { EmptyState } from '@/components/ui/empty-state';
import { FilterBar, SearchField } from '@/components/ui/filter-bar';
import { FormField, SelectInput, TextInput } from '@/components/ui/form-field';
import { PageHeader } from '@/components/ui/page-header';
import { Pagination } from '@/components/ui/pagination';
import { useDateFormatter } from '@/lib/format';
import { terms } from '@/lib/terminology';
import { useQueryFilters } from '@/lib/use-query-filters';
import type { CampusOption, Paginated } from '@/types';
import type { ReportChart } from '@/types/charts';

type Row = Record<string, string | number | null>;
interface Option {
    id: number;
    name: string;
}
interface Filters {
    type: string;
    period: number | string;
    campus: string;
    class: number | string;
    subject: number | string;
    search: string;
    from: string;
    to: string;
}
interface ReportFilterValues {
    type: string;
    period: string;
    campus: string;
    class: string;
    subject: string;
    search: string;
    from: string;
    to: string;
    [key: string]: string;
}
interface Props {
    columns: Record<string, string>;
    /** Columns holding numbers, right-aligned (UI_UX_DESIGN.md §31). */
    numericColumns?: string[];
    rows: Paginated<Row>;
    /** Charts over every row of the filtered report; empty when a chart would add nothing. */
    charts: ReportChart[];
    filters: Filters;
    /** Campuses to filter by (accounts that see every campus only). */
    campusOptions: CampusOption[];
    /** The campus the report covers ("All campuses" when not narrowed). */
    campusLabel: string;
    periods: Option[];
    classes: Option[];
    subjects: Option[];
    types: Record<string, string>;
    scope: string;
    generatedAt: string;
}

const REPORTS_URL = '/reports';
const RESULT_TYPES = ['examination', 'quiz'];

/** Search matches every displayed column, so the placeholder names the useful ones for each report (§51). */
function searchPlaceholder(type: string): string {
    const classTerm = terms.classBatch.singular.toLowerCase();

    switch (type) {
        case 'examination':
        case 'quiz':
            return `Search by candidate name or number, ${classTerm}, subject, or title…`;
        case 'subject':
            return `Search by ${classTerm} or subject…`;
        case 'class':
            return `Search by ${classTerm} name…`;
        case 'distribution':
            return 'Search by grade range…';
        default:
            return `Search by candidate name or number, or ${classTerm}…`;
    }
}

function queryString(filters: Filters, extra: Record<string, string> = {}): string {
    const query = new URLSearchParams(Object.entries(filters).map(([key, value]) => [key, String(value ?? '')]));
    Object.entries(extra).forEach(([key, value]) => query.set(key, value));

    return query.toString();
}

export default function Reports({
    columns,
    numericColumns = [],
    rows,
    charts,
    filters,
    campusOptions,
    campusLabel,
    periods,
    classes,
    subjects,
    types,
    scope,
    generatedAt,
}: Props) {
    const { app } = usePage().props;
    const errors = usePage().props.errors as Record<string, string | undefined>;
    const dates = useDateFormatter();
    const { values, update, updateMany } = useQueryFilters<ReportFilterValues>(REPORTS_URL, {
        type: String(filters.type ?? ''),
        period: String(filters.period ?? ''),
        campus: String(filters.campus ?? ''),
        class: String(filters.class ?? ''),
        subject: String(filters.subject ?? ''),
        search: String(filters.search ?? ''),
        from: String(filters.from ?? ''),
        to: String(filters.to ?? ''),
    });

    const classTerm = terms.classBatch;
    const allClassesLabel = `All authorized ${classTerm.plural.toLowerCase()}`;
    const results = RESULT_TYPES.includes(filters.type);
    // The server opens the first available period when none is chosen.
    const defaultPeriod = String(periods[0]?.id ?? '');
    const canReset =
        values.period !== defaultPeriod || [values.campus, values.class, values.subject, values.search, values.from, values.to].some((value) => value !== '');
    // Reset keeps the chosen report type and returns every other filter to its default.
    const resetFilters = () => updateMany({ period: defaultPeriod, campus: '', class: '', subject: '', search: '', from: '', to: '' });

    const periodName = periods.find((period) => period.id === Number(filters.period))?.name ?? 'No academic period';
    const className = filters.class ? classes.find((option) => option.id === Number(filters.class))?.name : allClassesLabel;
    const subjectName = filters.subject ? subjects.find((option) => option.id === Number(filters.subject))?.name : 'All authorized subjects';
    const displayColumns = Object.fromEntries(
        Object.entries(columns).map(([key, label]) => [key, key === 'classBatch' ? classTerm.singular : label]),
    );

    return (
        <>
            <Head title="Reports" />
            <style>{`@media print { @page { size: landscape; margin: 12mm; } #main-content { padding: 0; } table { font-size: 9pt; } th, td { padding: 6pt; } tr { break-inside: avoid; } .report-print-table > div { overflow: visible; } }`}</style>

            <div className="print:hidden">
                <PageHeader
                    title="Reports"
                    description="Academic standing and assessment results within your authorized subjects."
                    actions={
                        <a href={`${REPORTS_URL}/pdf?${queryString(filters)}`} className={buttonClasses('secondary')}>
                            <Download className="size-4" aria-hidden="true" />
                            Save as PDF
                        </a>
                    }
                />
            </div>

            <section className="rounded-lg border border-line-box bg-surface print:border-0" aria-labelledby="report-title">
                    <FilterBar onReset={resetFilters} canReset={canReset}>
                        <FormField label="Report" className="sm:w-60">
                            <SelectInput value={values.type} onChange={(event) => update('type', event.target.value)}>
                                {Object.entries(types).map(([value, label]) => (
                                    <option key={value} value={value}>
                                        {label}
                                    </option>
                                ))}
                            </SelectInput>
                        </FormField>
                        <FormField label="Academic period" error={errors.period} className="sm:w-52">
                            <SelectInput
                                value={values.period}
                                onChange={(event) => updateMany({ period: event.target.value, class: '', subject: '' })}
                            >
                                {periods.length === 0 && <option value="">No available periods</option>}
                                {periods.map((period) => (
                                    <option key={period.id} value={String(period.id)}>
                                        {period.name}
                                    </option>
                                ))}
                            </SelectInput>
                        </FormField>
                        {/* Classes and subjects depend on the campus, so a new campus clears them. */}
                        <CampusFilter
                            options={campusOptions}
                            value={values.campus}
                            onChange={(campus) => updateMany({ campus, class: '', subject: '' })}
                            className="sm:w-52"
                        />
                        <FormField label={classTerm.singular} className="sm:w-52">
                            <SelectInput value={values.class} onChange={(event) => update('class', event.target.value)}>
                                <option value="">{allClassesLabel}</option>
                                {classes.map((option) => (
                                    <option key={option.id} value={String(option.id)}>
                                        {option.name}
                                    </option>
                                ))}
                            </SelectInput>
                        </FormField>
                        <FormField label="Subject" className="sm:w-52">
                            <SelectInput value={values.subject} onChange={(event) => update('subject', event.target.value)}>
                                <option value="">All authorized subjects</option>
                                {subjects.map((option) => (
                                    <option key={option.id} value={String(option.id)}>
                                        {option.name}
                                    </option>
                                ))}
                            </SelectInput>
                        </FormField>
                        {RESULT_TYPES.includes(values.type) && (
                            <>
                                <FormField label="Submitted from" error={errors.from} className="sm:w-44">
                                    <TextInput
                                        type="date"
                                        value={values.from}
                                        onChange={(event) => update('from', event.target.value, { debounce: true })}
                                    />
                                </FormField>
                                <FormField label="Submitted through" error={errors.to} className="sm:w-44">
                                    <TextInput
                                        type="date"
                                        value={values.to}
                                        onChange={(event) => update('to', event.target.value, { debounce: true })}
                                    />
                                </FormField>
                            </>
                        )}
                        <SearchField
                            label="Search report"
                            placeholder={searchPlaceholder(values.type)}
                            value={values.search}
                            onChange={(value) => update('search', value, { debounce: true })}
                        />
                        {errors.search && (
                            <p role="alert" className="w-full text-sm text-danger-fg">
                                {errors.search}
                            </p>
                        )}
                    </FilterBar>

                <div className="border-b border-line p-5 print:px-0">
                    <h2 id="report-title" className="text-lg font-semibold text-primary-900">
                        {types[filters.type]}
                    </h2>
                    <p className="mt-1 text-sm text-ink-muted">
                        {periodName} · {campusOptions.length > 1 ? `${campusLabel} · ` : ''}
                        {className} · {subjectName}
                    </p>
                    <p className="mt-1 text-sm text-ink-muted">
                        Generated <time dateTime={generatedAt}>{dates.dateTime(generatedAt)}</time> ({app.timezone}) ·{' '}
                        <span className="tabular-nums">{rows.total}</span> {rows.total === 1 ? 'record' : 'records'}
                        {filters.search ? ` · Search: ${filters.search}` : ''}
                    </p>
                    <p className="mt-2 text-sm text-ink-muted">
                        {results
                            ? `Every submitted or expired attempt is listed separately. Submission dates: ${filters.from || 'any'} through ${filters.to || 'any'}. Scores awaiting review are not final.`
                            : 'Current weighted grades from finalized assessments. Provisional grades are included; configured period thresholds determine standing. This is a current snapshot, not a historical as-of report.'}{' '}
                        {scope === 'taught' && 'Standing covers only the subjects you teach.'}
                    </p>
                </div>

                {rows.total > 0 && <ReportCharts charts={charts} />}

                {rows.total === 0 ? (
                    canReset ? (
                        <EmptyState
                            icon={SearchX}
                            title="No records match these filters"
                            description="Try a different search term or selection, or reset the filters to see the whole report."
                            action={
                                <Button variant="secondary" onClick={resetFilters}>
                                    Reset Filters
                                </Button>
                            }
                        />
                    ) : (
                        <EmptyState
                            icon={FileChartColumn}
                            title="No records for this report"
                            description={
                                results
                                    ? 'No attempts have been submitted within the selected scope yet.'
                                    : 'There are no candidates with academic records within the selected scope yet.'
                            }
                        />
                    )
                ) : (
                    <ReportTable caption={types[filters.type] ?? 'Report'} columns={displayColumns} numericColumns={numericColumns} rows={rows.data} />
                )}

                <Pagination page={rows} noun={{ one: 'record', other: 'records' }} />
            </section>
        </>
    );
}
