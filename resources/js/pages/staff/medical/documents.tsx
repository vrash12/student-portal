import { Head, Link } from '@inertiajs/react';
import { FileStack } from 'lucide-react';
import { MedicalDocumentList } from '@/components/medical/medical-documents';
import { EmptyState } from '@/components/ui/empty-state';
import { FilterBar } from '@/components/ui/filter-bar';
import { FormField, TextInput } from '@/components/ui/form-field';
import { PageHeader } from '@/components/ui/page-header';
import { Pagination } from '@/components/ui/pagination';
import { cn } from '@/lib/cn';
import { routes } from '@/lib/routes';
import { useQueryFilters } from '@/lib/use-query-filters';
import type { Paginated } from '@/types';
import type { MedicalDocument, MedicalDocumentRow } from '@/types/medical';

type Tab = 'waiting' | 'returned' | 'accepted' | 'all';

interface MedicalDocumentsProps {
    documents: Paginated<MedicalDocumentRow>;
    filters: { tab: Tab; search: string };
    counts: { waiting: number };
}

const TABS: Array<{ value: Tab; label: string }> = [
    { value: 'waiting', label: 'Waiting for Review' },
    { value: 'returned', label: 'Returned' },
    { value: 'accepted', label: 'Accepted' },
    { value: 'all', label: 'All' },
];

const EMPTY: Record<Tab, string> = {
    waiting: 'No document waits for review',
    returned: 'No document has been returned',
    accepted: 'No document has been accepted yet',
    all: 'No candidate has uploaded a document yet',
};

/**
 * Medical documents uploaded by candidates (owner request, 2026-10-02): the
 * medical staff open each one and accept it, or return it with a reason the
 * candidate sees. Waiting documents are listed oldest first.
 */
export default function MedicalDocuments({ documents, filters, counts }: MedicalDocumentsProps) {
    const { values, update } = useQueryFilters(routes.medical.documents.index(), { tab: filters.tab, search: filters.search });
    const rows = new Map<number, MedicalDocumentRow>(documents.data.map((row) => [row.id, row]));

    const candidateLine = (document: MedicalDocument) => {
        const row = rows.get(document.id);
        if (row === undefined) {
            return null;
        }

        return (
            <p className="mt-0.5 text-sm text-ink">
                <Link href={routes.candidates.show(row.candidate.id)} className="font-medium text-primary-700 underline-offset-2 hover:underline">
                    {row.candidate.name}
                </Link>
                <span className="text-ink-muted">
                    {' · '}
                    {row.candidate.number}
                    {row.candidate.className !== null && ` · ${row.candidate.className}`}
                </span>
            </p>
        );
    };

    return (
        <>
            <Head title="Uploaded Medical Documents" />

            <PageHeader
                title="Uploaded Documents"
                description="Medical certificates, check-up findings and other documents uploaded by candidates. Open each one, then accept it or return it with a reason the candidate will see. Instructors of the candidate's class see accepted and waiting documents, view only; a copy needs an approved download request."
                breadcrumbs={[{ label: 'Medical Records', href: routes.medical.records.index() }, { label: 'Uploaded Documents' }]}
            />

            <section className="rounded-lg border border-line-box bg-surface" aria-label="Uploaded medical documents">
                <nav aria-label="Filter by review status" className="flex flex-wrap gap-2 border-b border-line px-4 py-3">
                    {TABS.map((tab) => {
                        const active = tab.value === filters.tab;

                        return (
                            <Link
                                key={tab.value}
                                href={routes.medical.documents.index({ tab: tab.value, ...(values.search !== '' ? { search: values.search } : {}) })}
                                preserveScroll
                                aria-current={active ? 'page' : undefined}
                                className={cn(
                                    'inline-flex min-h-11 items-center gap-2 rounded-lg border px-3 text-sm font-medium',
                                    active ? 'border-primary-700 bg-primary-700 text-white' : 'border-line text-ink hover:bg-surface-muted',
                                )}
                            >
                                {tab.label}
                                {tab.value === 'waiting' && (
                                    <span className={cn('rounded-full px-2 text-xs tabular-nums', active ? 'bg-white/20' : 'bg-surface-muted text-ink-muted')}>{counts.waiting}</span>
                                )}
                            </Link>
                        );
                    })}
                </nav>

                <FilterBar onReset={() => update('search', '')} canReset={values.search !== ''}>
                    <FormField label="Search" className="sm:w-72">
                        <TextInput type="search" value={values.search} onChange={(event) => update('search', event.target.value, { debounce: true })} placeholder="Candidate number or name" />
                    </FormField>
                </FilterBar>

                {documents.data.length === 0 ? (
                    <EmptyState
                        icon={FileStack}
                        title={values.search !== '' ? 'No document matches the search' : EMPTY[filters.tab]}
                        description="Candidates upload their documents from the Medical page of the candidate portal."
                    />
                ) : (
                    <div className="px-4 py-4">
                        <MedicalDocumentList documents={documents.data} audience="staff" emptyText="" renderContext={candidateLine} />
                    </div>
                )}

                <Pagination page={documents} noun={{ one: 'document', other: 'documents' }} />
            </section>
        </>
    );
}
