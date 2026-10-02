import { Head } from '@inertiajs/react';
import { ChartColumn, Pencil } from 'lucide-react';
import { CandidateInformationPanels } from '@/components/candidates/candidate-information';
import { RecordDownloads } from '@/components/candidates/record-downloads';
import { CandidateExaminationResults } from '@/components/candidates/examination-results';
import { CandidateAttendance } from '@/components/attendance/candidate-attendance';
import { CandidateConductPanel, CandidateQualificationPanel } from '@/components/candidate-performance/profile-panels';
import { CandidateFitness } from '@/components/fitness/candidate-fitness';
import { CandidateMedicalPanel } from '@/components/medical/medical-record-panel';
import type { ProfileMedical } from '@/types/medical';
import type { ProfileAttendance, ProfileConduct, ProfileQualification } from '@/types/candidate-performance';
import type { CandidateFitnessTest } from '@/types/fitness';
import type { CandidateInformation, CandidateExaminationResult } from '@/types/candidates';
import type { Paginated } from '@/types';
import { GradeStatusBadge, StandingCell, ThresholdSummary } from '@/components/grading/standing';
import {
    AcademicSummary,
    AssessmentResults,
    RecentActivity,
    type ActivityEntry,
    type SubjectResults,
} from '@/components/monitoring/candidate-academic-record';
import { ButtonLink } from '@/components/ui/button';
import { ClientPagination, useClientPagination } from '@/components/ui/client-pagination';
import { EmptyState } from '@/components/ui/empty-state';
import { PageHeader, type BreadcrumbItem } from '@/components/ui/page-header';
import { Panel } from '@/components/ui/panel';
import { StatusBadge } from '@/components/ui/status-badge';
import { RowAction, Table, TableBody, TableHead, Td, Th, Tr } from '@/components/ui/table';
import { formatGrade } from '@/lib/format';
import { routes } from '@/lib/routes';
import { StartCollapsed } from '@/lib/use-collapsible';
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
    candidate: CandidateInformation;
    examinationResults: Paginated<CandidateExaminationResult>;
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
    /** Military fitness history, newest first; null when the viewer may not see fitness records. */
    fitness: CandidateFitnessTest[] | null;
    /** Areas and qualification; null for viewers who only see the subjects they teach. Rank only with performance.view. */
    qualification: ProfileQualification | null;
    /** Merits and demerits; null when the candidate is outside the viewer's conduct scope. */
    conduct: ProfileConduct | null;
    /** Attendance of the current class; null when the class is outside the viewer's attendance scope. */
    attendance: ProfileAttendance | null;
    /** Medical record: every field for medical staff, the fields shared with instructors for those who teach the class; null otherwise. */
    medical: ProfileMedical | null;
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
    examinationResults,
    recentActivity,
    fitness,
    qualification,
    conduct,
    attendance,
    medical,
    canEdit,
    canBrowseCandidates,
}: CandidateShowProps) {

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
    const subjectPagination = useClientPagination(performance);

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
                    <>{canBrowseCandidates && <RecordDownloads baseUrl={`/candidates/${candidate.id}/documents`} />}{canEdit && (
                        <ButtonLink href={routes.candidates.edit(candidate.id)} icon={<Pencil className="size-4" aria-hidden="true" />}>
                            Edit Candidate
                        </ButtonLink>
                    )}</>
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

                {/* The long record starts closed; each section opens from its header. */}
                <StartCollapsed.Provider value>
                <CandidateInformationPanels candidate={candidate} />

                {medical !== null && <CandidateMedicalPanel medical={medical} candidateId={candidate.id} />}

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
                                {subjectPagination.rows.map((subject) => {
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
                    <ClientPagination pagination={subjectPagination} noun={{ one: 'subject', other: 'subjects' }} label="Subject grade pages" />
                </Panel>

                {qualification !== null && (
                    <CandidateQualificationPanel
                        qualification={qualification}
                        classBatch={candidate.classBatch === null ? null : { id: candidate.classBatch.id, name: candidate.classBatch.name }}
                    />
                )}

                {assessmentResults.length > 0 && <AssessmentResults subjects={assessmentResults} />}

                <CandidateExaminationResults results={examinationResults} />
                {fitness !== null && <CandidateFitness tests={fitness} />}
                {conduct !== null && <CandidateConductPanel conduct={conduct} candidateId={candidate.id} />}
                {attendance !== null && (
                    <CandidateAttendance
                        summary={attendance.summary}
                        sessions={attendance.sessions}
                        linkSessions={attendance.canManage}
                        description="Training sessions of the current class, latest first. Excused and unrecorded sessions are left out of the rate."
                    />
                )}
                {candidate.classBatch !== null && <RecentActivity entries={recentActivity} />}
                </StartCollapsed.Provider>
            </div>
        </>
    );
}
