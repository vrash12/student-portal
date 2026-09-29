import { Head } from '@inertiajs/react';
import { ChartColumn, Pencil } from 'lucide-react';
import type { ReactNode } from 'react';
import { ButtonLink } from '@/components/ui/button';
import { EmptyState } from '@/components/ui/empty-state';
import { PageHeader, type BreadcrumbItem } from '@/components/ui/page-header';
import { Panel } from '@/components/ui/panel';
import { StatusBadge, type StatusTone } from '@/components/ui/status-badge';
import { useDateFormatter } from '@/lib/format';
import { routes } from '@/lib/routes';
import { terms } from '@/lib/terminology';

interface CandidateShowProps {
    candidate: {
        id: number;
        candidateNumber: string;
        name: string;
        status: { label: string; tone: StatusTone };
        classBatch: { id: number; name: string; period: string } | null;
        /** Only provided to users who manage candidate records. */
        account: { username: string; isActive: boolean; lastLoginAt: string | null } | null;
        createdAt: string | null;
        updatedAt: string | null;
    };
    subjects: Array<{ code: string; name: string; instructors: string[] }>;
    canEdit: boolean;
    /** Administrators browse all candidates; instructors arrive from a class they teach. */
    canBrowseCandidates: boolean;
}

export default function CandidateShow({ candidate, subjects, canEdit, canBrowseCandidates }: CandidateShowProps) {
    const formatDate = useDateFormatter();
    const classTerm = terms.classBatch.singular;

    const breadcrumbs: BreadcrumbItem[] = canBrowseCandidates
        ? [{ label: 'Candidates', href: routes.candidates.index() }, { label: candidate.name }]
        : [
              { label: `My ${terms.classBatch.plural}`, href: routes.teaching.classes.index() },
              ...(candidate.classBatch
                  ? [{ label: candidate.classBatch.name, href: routes.teaching.classes.show(candidate.classBatch.id) }]
                  : []),
              { label: candidate.name },
          ];

    return (
        <>
            <Head title={candidate.name} />

            <PageHeader
                title={candidate.name}
                description={
                    <>
                        Candidate {candidate.candidateNumber} <StatusBadge tone={candidate.status.tone}>{candidate.status.label}</StatusBadge>
                    </>
                }
                breadcrumbs={breadcrumbs}
                actions={
                    canEdit && (
                        <ButtonLink href={routes.candidates.edit(candidate.id)} icon={<Pencil className="size-4" aria-hidden="true" />}>
                            Edit Candidate
                        </ButtonLink>
                    )
                }
            />

            <div className="grid gap-6 lg:grid-cols-2">
                <Panel title="Candidate Information">
                    <dl className="grid gap-4 sm:grid-cols-2">
                        <Detail label="Candidate Number">{candidate.candidateNumber}</Detail>
                        <Detail label="Status">
                            <StatusBadge tone={candidate.status.tone}>{candidate.status.label}</StatusBadge>
                        </Detail>
                        <Detail label={classTerm}>{candidate.classBatch?.name ?? 'Not assigned'}</Detail>
                        <Detail label="Academic Period">{candidate.classBatch?.period ?? '—'}</Detail>
                        <Detail label="Record Created">{formatDate.date(candidate.createdAt)}</Detail>
                        <Detail label="Last Updated">{formatDate.dateTime(candidate.updatedAt)}</Detail>
                    </dl>
                </Panel>

                {candidate.account !== null && (
                    <Panel title="Sign-In Account">
                        <dl className="grid gap-4 sm:grid-cols-2">
                            <Detail label="Username">{candidate.account.username}</Detail>
                            <Detail label="Account Status">
                                {candidate.account.isActive ? (
                                    <StatusBadge tone="success">Active</StatusBadge>
                                ) : (
                                    <StatusBadge tone="neutral">Deactivated</StatusBadge>
                                )}
                            </Detail>
                            <Detail label="Last Sign-In">
                                {candidate.account.lastLoginAt ? formatDate.dateTime(candidate.account.lastLoginAt) : 'Never'}
                            </Detail>
                        </dl>
                    </Panel>
                )}

                <Panel title="Subjects" description={`Subjects taken through the candidate's ${classTerm.toLowerCase()}.`}>
                    {subjects.length === 0 ? (
                        <p className="text-sm text-ink-muted">
                            {candidate.classBatch === null
                                ? `Assign the candidate to a ${classTerm.toLowerCase()} to list their subjects.`
                                : `No subjects have been added to ${candidate.classBatch.name} yet.`}
                        </p>
                    ) : (
                        <ul className="divide-y divide-line">
                            {subjects.map((subject) => (
                                <li key={subject.code} className="flex flex-col gap-0.5 py-3 first:pt-0 last:pb-0">
                                    <p className="font-medium text-ink">
                                        {subject.name} <span className="font-normal text-ink-muted">({subject.code})</span>
                                    </p>
                                    <p className="text-sm text-ink-muted">
                                        {subject.instructors.length > 0 ? subject.instructors.join(', ') : 'No instructor assigned'}
                                    </p>
                                </li>
                            ))}
                        </ul>
                    )}
                </Panel>

                <Panel title="Academic Performance">
                    <EmptyState
                        icon={ChartColumn}
                        headingLevel="h3"
                        title="No grades recorded yet"
                        description="Subject grades and academic standing will appear here once assessments are recorded."
                    />
                </Panel>
            </div>
        </>
    );
}

function Detail({ label, children }: { label: string; children: ReactNode }) {
    return (
        <div>
            <dt className="text-sm text-ink-muted">{label}</dt>
            <dd className="mt-0.5 font-medium text-ink">{children}</dd>
        </div>
    );
}
