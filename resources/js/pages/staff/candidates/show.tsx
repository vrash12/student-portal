import { Head } from '@inertiajs/react';
import { ChartColumn, Pencil } from 'lucide-react';
import type { ReactNode } from 'react';
import { GradeStatusBadge, StandingCell, ThresholdSummary } from '@/components/grading/standing';
import {
    AcademicSummary,
    AssessmentResults,
    RecentActivity,
    type ActivityEntry,
    type SubjectResults,
} from '@/components/monitoring/candidate-academic-record';
import { ButtonLink } from '@/components/ui/button';
import { EmptyState } from '@/components/ui/empty-state';
import { PageHeader, type BreadcrumbItem } from '@/components/ui/page-header';
import { Panel } from '@/components/ui/panel';
import { StatusBadge, type StatusTone } from '@/components/ui/status-badge';
import { RowAction, Table, TableBody, TableHead, Td, Th, Tr } from '@/components/ui/table';
import { formatGrade, useDateFormatter } from '@/lib/format';
import { routes } from '@/lib/routes';
import { terms } from '@/lib/terminology';
import type { GradingThresholds, OverallStanding, SubjectGrade } from '@/types/grading';
import type { SubjectConcern } from '@/types/monitoring';

interface SubjectPerformance {
    classSubjectId: number;
    code: string;
    name: string;
    /** Calculated by the server from finalized assessments. */
    result: SubjectGrade;
    /** The viewer teaches this subject and may open its gradebook. */
    canOpenGradebook: boolean;
}

interface CandidateShowProps {
    candidate: {
        id: number;
        candidateNumber: string;
        name: string;
        status: { label: string; tone: StatusTone };
        classBatch: { id: number; name: string; period: string; periodId: number } | null;
        /** Only provided to users who manage candidate records. */
        account: { username: string; isActive: boolean; lastLoginAt: string | null } | null;
        createdAt: string | null;
        updatedAt: string | null;
    };
    subjects: Array<{ code: string; name: string; instructors: string[] }>;
    performance: SubjectPerformance[];
    standing: {
        /** Most serious subject standing over the subjects shown, decided by the server. */
        overall: OverallStanding;
        /** "all": every subject of the class; "taught": only the subjects the viewer teaches. */
        scope: 'all' | 'taught';
        /** Passing and warning grades of the class's academic period; null when not set up. */
        thresholds: GradingThresholds | null;
        /** False for withdrawn candidates (and candidates without a class): grades only, no standing. */
        monitored: boolean;
        /** The viewer may set the period's passing and warning grades. */
        canConfigureThresholds: boolean;
    };
    /** Subjects needing attention, most serious first (empty when not monitored). */
    warnings: SubjectConcern[];
    /** Results of finalized assessments, by visible subject. */
    assessmentResults: SubjectResults[];
    recentActivity: ActivityEntry[];
    canEdit: boolean;
    /** Administrators browse all candidates; instructors arrive from a class they teach. */
    canBrowseCandidates: boolean;
}

export default function CandidateShow({
    candidate,
    subjects,
    performance,
    standing,
    warnings,
    assessmentResults,
    recentActivity,
    canEdit,
    canBrowseCandidates,
}: CandidateShowProps) {
    const formatDate = useDateFormatter();
    const classTerm = terms.classBatch.singular;
    const overallLabel = standing.scope === 'all' ? 'Overall Standing' : 'Standing in Your Subjects';
    const showsStanding = standing.monitored && standing.thresholds !== null;
    // Why no standing is shown, when it is not.
    const unavailableReason =
        candidate.classBatch === null
            ? null
            : !standing.monitored
              ? `Standing is not monitored for ${candidate.status.label.toLowerCase()} candidates.`
              : standing.thresholds === null
                ? `Academic standing is not available: passing and warning grades have not been set for ${candidate.classBatch.period}.`
                : null;
    const thresholdsHref =
        candidate.classBatch !== null && standing.canConfigureThresholds && standing.monitored && standing.thresholds === null
            ? routes.academicPeriods.thresholds(candidate.classBatch.periodId)
            : null;
    const instructorsBySubject = new Map(subjects.map((subject) => [subject.code, subject.instructors]));

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

            <div className="flex flex-col gap-6">
                <AcademicSummary
                    label={overallLabel}
                    overall={standing.overall}
                    showsStanding={showsStanding}
                    unavailableReason={unavailableReason}
                    thresholdsHref={thresholdsHref}
                    warnings={warnings}
                    hasClass={candidate.classBatch !== null}
                />

                <Panel
                    title="Academic Performance"
                    description={
                        <>
                            Current grades from finalized assessments.
                            {showsStanding && standing.thresholds !== null && candidate.classBatch !== null && (
                                <>
                                    {' '}
                                    Standing uses the <ThresholdSummary thresholds={standing.thresholds} /> of {candidate.classBatch.period}.
                                </>
                            )}
                            {standing.scope === 'taught' && ' Only the subjects you teach are shown.'}
                        </>
                    }
                    bodyClassName={performance.length === 0 ? undefined : 'p-0'}
                >
                    {performance.length === 0 ? (
                        <EmptyState
                            icon={ChartColumn}
                            headingLevel="h3"
                            title="No subjects to grade"
                            description={
                                candidate.classBatch === null
                                    ? `Assign the candidate to a ${classTerm.toLowerCase()} to see subject grades.`
                                    : `Subject grades appear here once subjects are added to ${candidate.classBatch.name}.`
                            }
                        />
                    ) : (
                        <Table caption={`Subject grades of ${candidate.name}`} className="min-w-[32rem]">
                            <TableHead>
                                <Th>Subject</Th>
                                <Th align="right">Current Grade</Th>
                                <Th>{showsStanding ? 'Current Standing' : 'Grade Status'}</Th>
                                <Th align="right">
                                    <span className="sr-only">Actions</span>
                                </Th>
                            </TableHead>
                            <TableBody>
                                {performance.map((subject) => {
                                    const instructors = instructorsBySubject.get(subject.code) ?? [];

                                    return (
                                        <Tr key={subject.classSubjectId}>
                                            <Td className="text-ink">
                                                <span className="font-medium">{subject.name}</span> <span className="text-ink-muted">({subject.code})</span>
                                                <span className="block text-xs text-ink-muted">
                                                    {instructors.length > 0 ? instructors.join(', ') : 'No instructor assigned'}
                                                </span>
                                            </Td>
                                            <Td align="right" numeric className="font-semibold text-ink">
                                                {formatGrade(subject.result.grade)}
                                            </Td>
                                            <Td>
                                                {showsStanding ? <StandingCell result={subject.result} /> : <GradeStatusBadge result={subject.result} />}
                                            </Td>
                                            <Td align="right">
                                                {subject.canOpenGradebook && candidate.classBatch !== null && (
                                                    <RowAction
                                                        href={routes.teaching.gradebook(candidate.classBatch.id, subject.classSubjectId)}
                                                        label={`Gradebook for ${subject.name}`}
                                                    >
                                                        Gradebook
                                                    </RowAction>
                                                )}
                                            </Td>
                                        </Tr>
                                    );
                                })}
                            </TableBody>
                        </Table>
                    )}
                </Panel>

                {assessmentResults.length > 0 && <AssessmentResults subjects={assessmentResults} />}

                <div className="grid gap-6 lg:grid-cols-2">
                    {candidate.classBatch !== null && <RecentActivity entries={recentActivity} />}

                    <div className="flex flex-col gap-6">
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
                    </div>
                </div>
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
