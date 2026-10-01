import { Head } from '@inertiajs/react';
import { Medal, UserRound } from 'lucide-react';
import { useState } from 'react';
import { ConductLedgerTable } from '@/components/conduct/conduct-ledger-table';
import { ConductTotalsList } from '@/components/conduct/conduct-totals';
import { RecordConductForm } from '@/components/conduct/record-conduct-form';
import { VoidConductEntryDialog } from '@/components/conduct/void-conduct-entry-dialog';
import { Alert } from '@/components/ui/alert';
import { ButtonLink } from '@/components/ui/button';
import { EmptyState } from '@/components/ui/empty-state';
import { PageHeader } from '@/components/ui/page-header';
import { Panel } from '@/components/ui/panel';
import { StatusBadge } from '@/components/ui/status-badge';
import { routes } from '@/lib/routes';
import type { ConductEntryRow, ConductKindGroup, ConductTotals, ConductTypeOption } from '@/types/conduct';
import type { StatusValue } from '@/types/grading';

interface ConductShowProps {
    candidate: {
        id: number;
        candidateNumber: string;
        name: string;
        status: StatusValue;
        classBatch: { name: string; period: string } | null;
    };
    totals: ConductTotals;
    /** Newest first, voided entries included. */
    entries: ConductEntryRow[];
    /** Active types, merits first. */
    types: ConductTypeOption[];
    kinds: ConductKindGroup[];
    /** Today in the institution's timezone (Y-m-d). */
    today: string;
    can: { record: boolean; viewCandidate: boolean; configureTypes: boolean };
}

export default function ConductShow({ candidate, totals, entries, types, kinds, today, can }: ConductShowProps) {
    const [voiding, setVoiding] = useState<ConductEntryRow | null>(null);
    const voidedCount = entries.filter((entry) => entry.voided !== null).length;

    return (
        <>
            <Head title={`Merits & Demerits · ${candidate.name}`} />

            <PageHeader
                title={candidate.name}
                description={
                    <>
                        Merits & Demerits · Candidate {candidate.candidateNumber}
                        {candidate.classBatch !== null && ` · ${candidate.classBatch.name} (${candidate.classBatch.period})`}
                        {candidate.status.value !== 'enrolled' && (
                            <>
                                {' '}
                                <StatusBadge tone={candidate.status.tone}>{candidate.status.label}</StatusBadge>
                            </>
                        )}
                    </>
                }
                breadcrumbs={[{ label: 'Merits & Demerits', href: routes.conduct.index() }, { label: candidate.name }]}
                actions={
                    can.viewCandidate && (
                        <ButtonLink href={routes.candidates.show(candidate.id)} icon={<UserRound className="size-4" aria-hidden="true" />}>
                            Candidate Profile
                        </ButtonLink>
                    )
                }
            />

            <div className="flex flex-col gap-6">
                <section aria-labelledby="conduct-totals-heading" className="flex flex-col gap-3">
                    <h2 id="conduct-totals-heading" className="text-lg font-semibold text-ink">
                        Totals
                    </h2>
                    <ConductTotalsList totals={totals} />
                </section>

                <Panel
                    title="Record a Merit or Demerit"
                    description="Entries cannot be edited or deleted: void a mistaken entry and record the correct one."
                >
                    {!can.record ? (
                        <Alert tone="info" title="Recording is closed for this candidate">
                            Merits and demerits cannot be recorded for a withdrawn candidate. The entries already recorded are kept, and mistaken ones can still be
                            voided.
                        </Alert>
                    ) : types.length === 0 ? (
                        <Alert tone="warning" title="No active merit or demerit types">
                            <p>Entries are recorded under a type. {can.configureTypes ? 'Add or reactivate a type first.' : 'Ask an administrator to add or reactivate a type.'}</p>
                            {can.configureTypes && (
                                <div className="mt-2">
                                    <ButtonLink href={routes.conduct.types.index()} variant="secondary" size="sm">
                                        Open Merit & Demerit Types
                                    </ButtonLink>
                                </div>
                            )}
                        </Alert>
                    ) : (
                        <RecordConductForm candidateId={candidate.id} types={types} kinds={kinds} today={today} />
                    )}
                </Panel>

                <Panel
                    title="Conduct Ledger"
                    description={
                        voidedCount > 0
                            ? `Newest first. ${voidedCount} voided ${voidedCount === 1 ? 'entry stays' : 'entries stay'} listed, struck through, and ${voidedCount === 1 ? 'does' : 'do'} not count.`
                            : 'Newest first. Voided entries stay listed, struck through, and do not count.'
                    }
                    bodyClassName="p-0"
                >
                    {entries.length === 0 ? (
                        <EmptyState
                            icon={Medal}
                            headingLevel="h3"
                            title="No merits or demerits yet"
                            description={can.record ? 'Record the first entry with the form above.' : 'No merits or demerits have been recorded for this candidate.'}
                        />
                    ) : (
                        <ConductLedgerTable entries={entries} caption={`Merits and demerits of ${candidate.name}`} onVoid={setVoiding} />
                    )}
                </Panel>
            </div>

            <VoidConductEntryDialog entry={voiding} onClose={() => setVoiding(null)} />
        </>
    );
}
